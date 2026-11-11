import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CloudUpload, HardDrive, ListChecks } from 'lucide-react';
import { Button, Input, Select, ServerError, Spinner, Stat, TitledGreyBox, httpErrorToHuman, toast, useCurrentResource } from '@pterodactyl/sdk';
import { copyLatest, getServerDetails, keys, saveAllowance } from '../api';
import type { Allowance, ServerDetails, StorageChoice } from '../types';
import CopiesTable from '../components/CopiesTable';
import EmptyState from '../components/EmptyState';
import Field, { SectionTitle, numberOrNull } from '../components/Field';
import { STORAGE_LABELS, formatAgo, formatBytes, plural } from '../lib/format';

const enabledValue = (enabled: boolean | null) => (enabled === null ? 'default' : enabled ? 'on' : 'off');

function Tiles({ details }: { details: ServerDetails }) {
    const { usage, allowance } = details;
    const limit = allowance.max_storage_gb ? `/ ${allowance.max_storage_gb} GiB` : undefined;

    return (
        <div className='ofs:mb-6 ofs:grid ofs:grid-cols-1 ofs:gap-3 ofs:sm:grid-cols-3'>
            <Stat
                label='Copies'
                value={String(usage.copies)}
                limit={allowance.max_copies ? `/ ${allowance.max_copies}` : usage.failed > 0 ? `· ${usage.failed} failed` : undefined}
                tone={usage.failed > 0 ? 'danger' : 'neutral'}
                icon={CloudUpload}
                className='ofs:h-full ofs:min-w-0'
                title={usage.last_copy_at ? `Last copy ${formatAgo(usage.last_copy_at)}` : 'No copy yet'}
            />
            <Stat
                label='Storage used'
                value={formatBytes(usage.stored_bytes)}
                limit={limit}
                meter={allowance.max_storage_gb ? Math.min(100, (usage.stored_bytes / (allowance.max_storage_gb * 1024 ** 3)) * 100) : undefined}
                icon={HardDrive}
                className='ofs:h-full ofs:min-w-0'
            />
            <Stat label='Policy' value={usage.policy ?? (usage.protected ? 'Protected' : 'Not copied')} icon={ListChecks} tone={usage.protected ? 'success' : 'neutral'} className='ofs:h-full ofs:min-w-0' />
        </div>
    );
}

export default function ServerTab() {
    const context = useCurrentResource();
    const id = context?.kind === 'admin.server' ? context.resource.attributes.id : 0;
    const queryClient = useQueryClient();
    const details = useQuery({
        queryKey: keys.server(id),
        queryFn: () => getServerDetails(id),
        enabled: id > 0,
        refetchInterval: (query) => (query.state.data?.copies.some((copy) => copy.status === 'running' || copy.status === 'queued') ? 10_000 : false),
    });
    const [allowance, setAllowance] = useState<Allowance | null>(null);
    const set = (changes: Partial<Allowance>) => setAllowance((current) => (current ? { ...current, ...changes } : current));
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    useEffect(() => {
        if (details.data) {
            setAllowance(details.data.allowance);
        }
    }, [details.data]);

    const saving = useMutation({
        mutationFn: (value: Allowance) => saveAllowance(id, value),
        onSuccess: () => (toast.success('Saved.'), queryClient.invalidateQueries({ queryKey: keys.all })),
        onError,
    });
    const copying = useMutation({
        mutationFn: () => copyLatest(id),
        onSuccess: (copy) => (copy ? toast.success(`Copying ${copy.backup.name} to ${copy.destination.name}.`) : toast.error('This server has no finished backup to copy.'), queryClient.invalidateQueries({ queryKey: keys.all })),
        onError,
    });

    if (!details.data || !allowance) {
        return details.isError ? <ServerError title='Could not load off-site backups' message={httpErrorToHuman(details.error)} onRetry={() => details.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />;
    }

    const { data } = details;
    const buckets = data.destinations.filter((destination) => destination.kind === 's3');

    return (
        <>
            <Tiles details={data} />
            <div className='ofs:mb-6 ofs:grid ofs:items-start ofs:gap-6 ofs:lg:grid-cols-2'>
                <TitledGreyBox title='Backup storage'>
                    <div className='ofs:grid ofs:gap-4'>
                        <Field
                            id='ofs-server-storage'
                            label='New backups are stored'
                            help={allowance.storage === 'default' ? `Panel default: ${data.defaults.storage_destination ? `directly in S3 (${data.defaults.storage_destination})` : 'the panel configuration'}.` : allowance.storage === 'node' ? "On the node's disk, whatever the panel default is." : 'The node uploads each backup straight to the bucket.'}
                        >
                            <Select
                                id='ofs-server-storage'
                                value={allowance.storage}
                                onChange={(value) => set({ storage: value as StorageChoice, storage_destination_id: value === 's3' ? (allowance.storage_destination_id ?? buckets[0]?.id ?? null) : null })}
                                options={(['default', 'node', 's3'] as const).map((value) => ({ value, label: STORAGE_LABELS[value], disabled: value === 's3' && buckets.length === 0 }))}
                            />
                        </Field>
                        {allowance.storage === 's3' && (
                            <Field id='ofs-server-bucket' label='Bucket'>
                                <Select id='ofs-server-bucket' placeholder='Pick an S3 destination' value={allowance.storage_destination_id} onChange={(value) => set({ storage_destination_id: Number(value) })} options={buckets.map((bucket) => ({ value: bucket.id, label: bucket.name }))} />
                            </Field>
                        )}
                        <div className='ofs:flex ofs:justify-end'>
                            <Button size='small' disabled={allowance.storage === 's3' && allowance.storage_destination_id === null} isLoading={saving.isPending} onClick={() => saving.mutate({ ...data.allowance, storage: allowance.storage, storage_destination_id: allowance.storage_destination_id })}>
                                Save
                            </Button>
                        </div>
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title='Off-site allowance'>
                    <div className='ofs:grid ofs:gap-4'>
                        <Field id='ofs-server-enabled' label='Off-site backups' help={data.rules.length > 0 ? `Matching rules: ${data.rules.map((rule) => rule.name).join(', ')}.` : 'No rule matches this server.'}>
                            <Select
                                id='ofs-server-enabled'
                                value={enabledValue(allowance.enabled)}
                                onChange={(value) => set({ enabled: value === 'default' ? null : value === 'on' })}
                                options={[
                                    { value: 'default', label: `Panel default (${data.defaults.enabled ? 'On' : 'Off'})` },
                                    { value: 'on', label: 'On' },
                                    { value: 'off', label: 'Off' },
                                ]}
                            />
                        </Field>
                        <div className='ofs:grid ofs:gap-4 ofs:sm:grid-cols-2'>
                            <Field id='ofs-server-max-copies' label='Maximum copies' help='Oldest copies go first. Empty for no limit.'>
                                <Input id='ofs-server-max-copies' type='number' min={1} placeholder='No limit' value={allowance.max_copies ?? ''} onChange={(event) => set({ max_copies: numberOrNull(event.currentTarget.value) })} />
                            </Field>
                            <Field id='ofs-server-max-storage' label='Maximum storage (GB)' help='Across all destinations. Empty for no limit.'>
                                <Input id='ofs-server-max-storage' type='number' min={1} placeholder='No limit' value={allowance.max_storage_gb ?? ''} onChange={(event) => set({ max_storage_gb: numberOrNull(event.currentTarget.value) })} />
                            </Field>
                        </div>
                        <div className='ofs:flex ofs:flex-wrap ofs:justify-end ofs:gap-2'>
                            <Button.Text size='small' isLoading={copying.isPending} onClick={() => copying.mutate()}>
                                Copy latest backup now
                            </Button.Text>
                            <Button size='small' isLoading={saving.isPending} onClick={() => saving.mutate({ ...data.allowance, enabled: allowance.enabled, max_copies: allowance.max_copies, max_storage_gb: allowance.max_storage_gb })}>
                                Save
                            </Button>
                        </div>
                    </div>
                </TitledGreyBox>
            </div>

            <section className='ofs:min-w-0'>
                <SectionTitle>Copies of this server</SectionTitle>
                <CopiesTable
                    rows={data.copies}
                    hideServer
                    isFetching={details.isFetching}
                    emptyState={<EmptyState compact icon='archive' title='No copies yet' description={data.usage.protected ? 'New backups are copied a few minutes after they finish.' : 'No rule copies this server yet.'} />}
                />
                {data.usage.copies > data.copies.length && <p className='ofs:mt-2 ofs:text-xs ofs:text-muted-foreground'>Showing the newest {plural(data.copies.length, 'copy', 'copies')}. The Copies tab of Offsite Backups lists them all.</p>}
            </section>
        </>
    );
}
