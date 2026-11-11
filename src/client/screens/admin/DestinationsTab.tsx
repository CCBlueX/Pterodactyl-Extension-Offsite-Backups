import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlugZap, Star } from 'lucide-react';
import {
    Button,
    DeleteAction,
    Dialog,
    DropdownMenuItem,
    EditAction,
    RowActions,
    RowActionsMenu,
    ServerError,
    Spinner,
    Switch,
    Table,
    actionsColumn,
    httpErrorToHuman,
    toast,
} from '@pterodactyl/sdk';
import { deleteDestination, getDestinations, keys, makeDefaultDestination, saveDestination, testDestination } from '../../api';
import type { Destination, DestinationInput } from '../../types';
import DestinationBadges, { DestinationStatusBadge } from '../../components/DestinationBadges';
import DestinationDialog from '../../components/DestinationDialog';
import EmptyState from '../../components/EmptyState';
import { formatBytes, plural } from '../../lib/format';

export default function DestinationsTab() {
    const queryClient = useQueryClient();
    const destinations = useQuery({ queryKey: keys.destinations, queryFn: getDestinations });
    const [editing, setEditing] = useState<Destination | 'new' | null>(null);
    const [removing, setRemoving] = useState<Destination | null>(null);
    const [withCopies, setWithCopies] = useState(false);
    const refresh = () => queryClient.invalidateQueries({ queryKey: keys.all });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const saving = useMutation({
        mutationFn: (input: DestinationInput) => saveDestination(input, editing && editing !== 'new' ? editing.id : undefined),
        onSuccess: () => (toast.success('Destination saved.'), setEditing(null), refresh()),
        onError,
    });
    const removal = useMutation({ mutationFn: (destination: Destination) => deleteDestination(destination.id, withCopies), onSuccess: () => (setRemoving(null), refresh()), onError });
    const testing = useMutation({
        mutationFn: (destination: Destination) => testDestination(destination.id),
        onSuccess: (result) => (result.ok ? toast.success(result.message || 'Connected.') : toast.error(result.message), refresh()),
        onError,
    });
    const defaulting = useMutation({ mutationFn: (destination: Destination) => makeDefaultDestination(destination.id), onSuccess: refresh, onError });

    if (!destinations.data) {
        return destinations.isError ? <ServerError title='Could not load destinations' message={httpErrorToHuman(destinations.error)} onRetry={() => destinations.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />;
    }

    return (
        <>
            {destinations.data.length === 0 ? (
                <EmptyState icon='cloud-upload' title='No destination yet' description='An S3 bucket or SFTP server where copies of your backups go.' action={<Button onClick={() => setEditing('new')}>Add a destination</Button>} />
            ) : (
                <>
                    <div className='ofs:mb-3 ofs:flex ofs:justify-end'>
                        <Button size='small' onClick={() => setEditing('new')}>
                            Add destination
                        </Button>
                    </div>
                    <div className='ofs:min-w-0 ofs:max-w-full ofs:overflow-x-auto'>
                        <Table
                            rows={destinations.data}
                            keyOf={(destination) => String(destination.id)}
                            emptyState={null}
                            isFetching={destinations.isFetching}
                            columns={[
                                {
                                    id: 'name',
                                    label: 'Destination',
                                    cell: (destination) => (
                                        <div className='ofs:min-w-0 ofs:max-w-80'>
                                            <p className='ofs:truncate ofs:text-foreground' title={destination.name}>
                                                {destination.name}
                                            </p>
                                            <DestinationBadges destination={destination} />
                                        </div>
                                    ),
                                },
                                { id: 'status', label: 'Status', cell: (destination) => <DestinationStatusBadge destination={destination} /> },
                                { id: 'copies', label: 'Copies', cell: (destination) => destination.copies },
                                { id: 'stored', label: 'Stored', cell: (destination) => <span className='ofs:whitespace-nowrap'>{formatBytes(destination.stored_bytes)}</span> },
                                actionsColumn(3, (destination: Destination) => (
                                    <RowActions>
                                        <EditAction aria-label={`Edit ${destination.name}`} onClick={() => setEditing(destination)} />
                                        <DeleteAction aria-label={`Delete ${destination.name}`} onClick={() => (setWithCopies(false), setRemoving(destination))} />
                                        <RowActionsMenu label={`More actions for ${destination.name}`}>
                                            <DropdownMenuItem icon={PlugZap} onClick={() => testing.mutate(destination)}>
                                                Test connection
                                            </DropdownMenuItem>
                                            {!destination.is_default && (
                                                <DropdownMenuItem icon={Star} onClick={() => defaulting.mutate(destination)}>
                                                    Make default
                                                </DropdownMenuItem>
                                            )}
                                        </RowActionsMenu>
                                    </RowActions>
                                )),
                            ]}
                        />
                    </div>
                    <p className='ofs:mt-3 ofs:text-xs ofs:text-muted-foreground'>The default destination is used for "Copy latest backup now" on servers no rule covers.</p>
                </>
            )}

            {editing && <DestinationDialog destination={editing === 'new' ? null : editing} pending={saving.isPending} onClose={() => setEditing(null)} onSave={(input) => saving.mutate(input)} />}
            <Dialog.Confirm open={removing !== null} onClose={() => setRemoving(null)} title={`Delete ${removing?.name}?`} confirm='Delete' pending={removal.isPending} onConfirmed={() => removing && removal.mutate(removing)}>
                <p>Rules stop copying to this destination.</p>
                {removing && removing.copies > 0 && (
                    <div className='ofs:mt-4'>
                        <Switch
                            id='ofs-delete-with-copies'
                            checked={withCopies}
                            onChange={setWithCopies}
                            label={`Also delete its ${plural(removing.copies, 'copy', 'copies')}`}
                            description='Without this, a destination that still holds copies is not deleted.'
                        />
                    </div>
                )}
            </Dialog.Confirm>
        </>
    );
}
