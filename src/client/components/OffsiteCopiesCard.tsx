import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Download, RotateCcw } from 'lucide-react';
import { Badge, ProgressBar, RowActionButton, RowActions, ServerError, Table, TitledGreyBox, actionsColumn, httpErrorToHuman, invalidateServerData, toast, useServerPermission, type TableColumn } from '@pterodactyl/sdk';
import { downloadClientCopy, keys, restoreClientCopy } from '../api';
import { formatAgo, formatBytes, formatDate, percent } from '../lib/format';
import { groupCopies, type OffsiteBackup } from '../lib/group';
import EmptyState from './EmptyState';
import RestoreDialog from './RestoreDialog';
import useClientOverview from './useClientOverview';

function Status({ backup }: { backup: OffsiteBackup }) {
    if (backup.copying === null) {
        return (
            <Badge variant='soft' tone='success' size='sm' className='ofs:whitespace-nowrap'>
                Off-site
            </Badge>
        );
    }

    const value = percent(backup.copying.progress);

    return (
        <div className='ofs:grid ofs:w-32 ofs:gap-1'>
            <Badge variant='soft' tone='info' size='sm' className='ofs:w-fit ofs:whitespace-nowrap'>
                Copying{value === null ? '' : ` ${Math.floor(value)}%`}
            </Badge>
            <ProgressBar value={value} tone='info' label={`Copying ${backup.name}`} />
        </div>
    );
}

/** Below the backup list: the server's off-site copies, restorable even when the node lost the backup. */
export default function OffsiteCopiesCard() {
    const queryClient = useQueryClient();
    const { identifier, uuid, query } = useClientOverview();
    const canDownload = useServerPermission('backup.download');
    const canRestore = useServerPermission('backup.restore');
    const [restoring, setRestoring] = useState<OffsiteBackup | null>(null);
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const download = useMutation({ mutationFn: (backup: OffsiteBackup) => downloadClientCopy(identifier, backup.primary.uuid), onSuccess: (url) => window.location.assign(url), onError });
    const restore = useMutation({
        mutationFn: ({ backup, truncate }: { backup: OffsiteBackup; truncate: boolean }) => restoreClientCopy(identifier, backup.primary.uuid, truncate),
        onSuccess: async () => {
            setRestoring(null);
            toast.success('Restore started. The server is unavailable until it finishes.');
            await Promise.all([invalidateServerData(uuid, ['server', 'backups']), queryClient.invalidateQueries({ queryKey: keys.client(identifier) })]);
        },
        onError,
    });

    if (query.isError && !query.data) {
        return <ServerError title='Could not load off-site copies' message={httpErrorToHuman(query.error)} onRetry={() => query.refetch()} />;
    }

    if (!query.data || (!query.data.protected && query.data.copies.length === 0)) {
        return null;
    }

    const backups = groupCopies(query.data.copies);
    const columns: TableColumn<OffsiteBackup>[] = [
        {
            id: 'name',
            label: 'Backup',
            cell: (backup) => (
                <div className='ofs:min-w-0 ofs:max-w-72'>
                    <span className='ofs:flex ofs:items-center ofs:gap-2'>
                        <span className='ofs:truncate ofs:text-foreground' title={backup.name}>
                            {backup.name}
                        </span>
                        {!backup.local && (
                            <Badge variant='soft' tone='info' size='sm' className='ofs:shrink-0 ofs:whitespace-nowrap'>
                                Kept off-site
                            </Badge>
                        )}
                    </span>
                    <span className='ofs:block ofs:truncate ofs:text-xs ofs:text-muted-foreground'>{backup.destinations.join(', ')}</span>
                </div>
            ),
        },
        { id: 'size', label: 'Size', cell: (backup) => <span className='ofs:whitespace-nowrap'>{formatBytes(backup.bytes)}</span> },
        {
            id: 'created',
            label: 'Created',
            cell: (backup) => (
                <span className='ofs:whitespace-nowrap' title={formatDate(backup.createdAt)}>
                    {formatAgo(backup.createdAt)}
                </span>
            ),
        },
        { id: 'status', label: 'Status', cell: (backup) => <Status backup={backup} /> },
    ];

    if (canDownload || canRestore) {
        columns.push(
            actionsColumn(canDownload && canRestore ? 2 : 1, (backup: OffsiteBackup) => (
                <RowActions>
                    {canDownload && (
                        <RowActionButton
                            aria-label={`Download ${backup.name}`}
                            icon={Download}
                            label='Download'
                            disabled={backup.primary.status !== 'off-site' || download.isPending}
                            disabledReason='Available once the copy is finished'
                            onClick={() => download.mutate(backup)}
                        />
                    )}
                    {canRestore && (
                        <RowActionButton
                            aria-label={`Restore ${backup.name}`}
                            icon={RotateCcw}
                            label='Restore'
                            disabled={backup.primary.status !== 'off-site'}
                            disabledReason='Available once the copy is finished'
                            onClick={() => setRestoring(backup)}
                        />
                    )}
                </RowActions>
            ))
        );
    }

    return (
        <TitledGreyBox title='Off-site copies' className='ofs:mt-6'>
            <p className='ofs:mb-3 ofs:text-sm ofs:text-muted-foreground'>Copies of your backups stored away from this node. They stay restorable even when a backup is gone here.</p>
            <div className='ofs:min-w-0 ofs:max-w-full ofs:overflow-x-auto'>
                <Table
                    rows={backups}
                    columns={columns}
                    keyOf={(backup) => backup.key}
                    isFetching={query.isFetching}
                    emptyState={<EmptyState compact icon='cloud-upload' title='No off-site copies yet' description='Backups show up here a few minutes after they finish.' />}
                />
            </div>
            <RestoreDialog name={restoring?.name ?? null} pending={restore.isPending} onClose={() => setRestoring(null)} onRestore={(truncate) => restoring && restore.mutate({ backup: restoring, truncate })} />
        </TitledGreyBox>
    );
}
