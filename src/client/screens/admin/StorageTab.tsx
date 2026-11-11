import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, PanelLink, Select, ServerError, Spinner, Table, TitledGreyBox, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { getDestinations, getStorage, keys, saveStorage } from '../../api';
import EmptyState from '../../components/EmptyState';
import Field from '../../components/Field';
import { STORAGE_LABELS } from '../../lib/format';

export default function StorageTab() {
    const queryClient = useQueryClient();
    const storage = useQuery({ queryKey: keys.storage, queryFn: getStorage });
    const destinations = useQuery({ queryKey: keys.destinations, queryFn: getDestinations });
    const [mode, setMode] = useState<'panel' | 's3'>('panel');
    const [destination, setDestination] = useState<number | null>(null);
    const buckets = (destinations.data ?? []).filter((item) => item.kind === 's3');

    useEffect(() => {
        if (storage.data) {
            setMode(storage.data.destination_id === null ? 'panel' : 's3');
            setDestination(storage.data.destination_id);
        }
    }, [storage.data]);

    const saving = useMutation({
        mutationFn: () => saveStorage(mode === 's3' ? destination : null),
        onSuccess: () => (toast.success('Backup storage saved.'), queryClient.invalidateQueries({ queryKey: keys.storage })),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (!storage.data) {
        return storage.isError ? <ServerError title='Could not load backup storage' message={httpErrorToHuman(storage.error)} onRetry={() => storage.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />;
    }

    return (
        <div className='ofs:grid ofs:items-start ofs:gap-6 ofs:lg:grid-cols-2'>
            <TitledGreyBox title='Where nodes store backups'>
                <div className='ofs:grid ofs:gap-4'>
                    <p className='ofs:text-sm ofs:text-muted-foreground'>Directly in S3, nodes upload new backups straight to the bucket instead of keeping them on their disk. Servers can override this on their Off-site tab.</p>
                    <Field id='ofs-storage-mode' label='Panel default' help={mode === 'panel' ? 'Whatever the panel is configured with, unchanged.' : 'Only S3 destinations can take backups directly.'}>
                        <Select
                            id='ofs-storage-mode'
                            value={mode}
                            onChange={(value) => setMode(value as 'panel' | 's3')}
                            options={[
                                { value: 'panel', label: `Panel configuration (${storage.data.panel_driver})` },
                                { value: 's3', label: 'Directly in S3', disabled: buckets.length === 0 },
                            ]}
                        />
                    </Field>
                    {mode === 's3' && (
                        <Field id='ofs-storage-destination' label='Bucket'>
                            <Select id='ofs-storage-destination' placeholder='Pick an S3 destination' value={destination} onChange={(value) => setDestination(Number(value))} options={buckets.map((item) => ({ value: item.id, label: item.name }))} />
                        </Field>
                    )}
                    <div className='ofs:flex ofs:justify-end'>
                        <Button size='small' disabled={mode === 's3' && destination === null} isLoading={saving.isPending} onClick={() => saving.mutate()}>
                            Save
                        </Button>
                    </div>
                </div>
            </TitledGreyBox>
            <TitledGreyBox title='Servers with their own storage'>
                <Table
                    rows={storage.data.overrides}
                    keyOf={(row) => String(row.server_id)}
                    emptyState={<EmptyState compact icon='server' title='No overrides' description='Every server follows the panel default.' />}
                    columns={[
                        {
                            id: 'server',
                            label: 'Server',
                            cell: (row) => (
                                <PanelLink destination={{ extension: 'offsite-backups', screen: 'server-tab', params: { id: String(row.server_id) } }} className='ofs:text-foreground ofs:hover:underline'>
                                    {row.server_name}
                                </PanelLink>
                            ),
                        },
                        { id: 'storage', label: 'Storage', cell: (row) => (row.storage === 's3' && row.destination ? `${STORAGE_LABELS.s3} (${row.destination})` : STORAGE_LABELS[row.storage]) },
                    ]}
                />
            </TitledGreyBox>
        </div>
    );
}
