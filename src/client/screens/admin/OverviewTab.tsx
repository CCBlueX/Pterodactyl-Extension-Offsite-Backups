import { useQuery } from '@tanstack/react-query';
import { Activity, CloudUpload, HardDrive, TriangleAlert } from 'lucide-react';
import { Badge, Button, ServerError, Spinner, Stat, Table, httpErrorToHuman } from '@pterodactyl/sdk';
import { getOverview, keys } from '../../api';
import type { Overview } from '../../types';
import CopiesTable from '../../components/CopiesTable';
import DestinationBadges, { DestinationStatusBadge } from '../../components/DestinationBadges';
import EmptyState from '../../components/EmptyState';
import { SectionTitle } from '../../components/Field';
import { formatAgo, formatBytes } from '../../lib/format';

/** The worker reports in at least every 90 seconds while it runs. */
const workerRunning = (seen: string | null) => seen !== null && Date.now() - new Date(seen).getTime() < 3 * 60_000;

interface Props {
    onOpen: (tab: 'destinations' | 'rules' | 'copies') => void;
}

function Tiles({ overview }: { overview: Overview }) {
    const running = workerRunning(overview.worker.seen_at);
    const active = overview.totals.queued + overview.totals.running;

    return (
        <div className='ofs:mb-6 ofs:grid ofs:grid-cols-2 ofs:gap-3 ofs:lg:grid-cols-4'>
            <Stat label='Off-site copies' value={String(overview.totals.verified)} limit={active > 0 ? `· ${active} in progress` : undefined} icon={CloudUpload} className='ofs:h-full ofs:min-w-0' />
            <Stat label='Stored' value={formatBytes(overview.stored_bytes)} icon={HardDrive} className='ofs:h-full ofs:min-w-0' />
            <Stat label='Failed in 24 h' value={String(overview.failed_24h)} limit={`of ${overview.copies_24h}`} icon={TriangleAlert} tone={overview.failed_24h > 0 ? 'danger' : 'neutral'} className='ofs:h-full ofs:min-w-0' />
            <Stat
                label={overview.worker.mode === 'dedicated' ? 'Dedicated worker' : 'Worker'}
                value={running ? 'Running' : overview.worker.seen_at ? 'Stopped' : 'Not started'}
                limit={overview.worker.seen_at ? `· seen ${formatAgo(overview.worker.seen_at)}` : undefined}
                icon={Activity}
                tone={running ? 'success' : active > 0 || overview.worker.mode === 'dedicated' ? 'danger' : 'neutral'}
                className='ofs:h-full ofs:min-w-0'
            />
        </div>
    );
}

export default function OverviewTab({ onOpen }: Props) {
    const overview = useQuery({ queryKey: keys.overview, queryFn: getOverview, refetchInterval: (query) => (query.state.data && query.state.data.totals.running + query.state.data.totals.queued > 0 ? 10_000 : 60_000) });

    if (!overview.data) {
        return overview.isError ? <ServerError title='Could not load the overview' message={httpErrorToHuman(overview.error)} onRetry={() => overview.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />;
    }

    const { data } = overview;

    return (
        <>
            <Tiles overview={data} />
            <div className='ofs:grid ofs:gap-6'>
                <section className='ofs:min-w-0'>
                    <SectionTitle>Destinations</SectionTitle>
                    {data.destinations.length === 0 ? (
                        <EmptyState icon='cloud-upload' title='No destination yet' description='Add an S3 bucket or SFTP server to start copying backups off-site.' action={<Button onClick={() => onOpen('destinations')}>Add a destination</Button>} />
                    ) : (
                        <div className='ofs:min-w-0 ofs:max-w-full ofs:overflow-x-auto'>
                            <Table
                                rows={data.destinations}
                                keyOf={(destination) => String(destination.id)}
                                emptyState={null}
                                columns={[
                                    {
                                        id: 'name',
                                        label: 'Destination',
                                        cell: (destination) => (
                                            <div className='ofs:min-w-0'>
                                                <p className='ofs:truncate ofs:text-foreground'>{destination.name}</p>
                                                <DestinationBadges destination={destination} />
                                            </div>
                                        ),
                                    },
                                    { id: 'status', label: 'Status', cell: (destination) => <DestinationStatusBadge destination={destination} /> },
                                    { id: 'copies', label: 'Copies', cell: (destination) => destination.copies },
                                    { id: 'stored', label: 'Stored', cell: (destination) => <span className='ofs:whitespace-nowrap'>{formatBytes(destination.stored_bytes)}</span> },
                                ]}
                            />
                        </div>
                    )}
                </section>
                <section className='ofs:min-w-0'>
                    <div className='ofs:flex ofs:items-baseline ofs:justify-between ofs:gap-3'>
                        <SectionTitle>Recent copies</SectionTitle>
                        {data.recent.length > 0 && (
                            <Button.Text size='xsmall' onClick={() => onOpen('copies')}>
                                All copies
                            </Button.Text>
                        )}
                    </div>
                    <CopiesTable
                        rows={data.recent}
                        actions={false}
                        emptyState={
                            <EmptyState
                                compact
                                icon='archive'
                                title='No copies yet'
                                description={data.destinations.length > 0 ? 'Backups are copied once a rule matches them.' : 'Copies show up here once a destination and a rule exist.'}
                                action={
                                    data.destinations.length > 0 ? (
                                        <Button size='small' onClick={() => onOpen('rules')}>
                                            Set up a rule
                                        </Button>
                                    ) : undefined
                                }
                            />
                        }
                    />
                    {data.totals.failed > 0 && (
                        <p className='ofs:mt-3 ofs:flex ofs:items-center ofs:gap-2 ofs:text-sm ofs:text-muted-foreground'>
                            <Badge variant='soft' tone='danger' size='sm'>
                                {data.totals.failed} failed
                            </Badge>
                            <Button.Text size='xsmall' onClick={() => onOpen('copies')}>
                                Review failed copies
                            </Button.Text>
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
