import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Download, Info, RefreshCw, RotateCcw, ShieldCheck } from 'lucide-react';
import {
    Badge,
    Checkbox,
    DeleteAction,
    Dialog,
    DropdownMenuItem,
    RowActionButton,
    RowActions,
    RowActionsMenu,
    Table,
    actionsColumn,
    httpErrorToHuman,
    toast,
    type TableColumn,
    type TablePagination,
} from '@pterodactyl/sdk';
import { checkCopy, deleteCopy, downloadCopy, keys, restoreCopy, retryCopy } from '../api';
import type { Copy } from '../types';
import { formatAgo, formatBytes, formatDate } from '../lib/format';
import CopyDetailsDialog from './CopyDetailsDialog';
import CopyStatusBadge from './CopyStatusBadge';
import RestoreDialog from './RestoreDialog';

interface Props {
    rows: Copy[];
    emptyState: ReactNode;
    isFetching?: boolean;
    pagination?: TablePagination;
    /** Leave out the server column, as on a server's own tab. */
    hideServer?: boolean;
    /** Show the row actions; the overview lists copies read only. */
    actions?: boolean;
    selected?: number[];
    onSelect?: (ids: number[]) => void;
}

const title = (copy: Copy) => (copy.kind === 'database' ? `Database ${copy.database}` : copy.backup.name);

export default function CopiesTable({ rows, emptyState, isFetching, pagination, hideServer = false, actions = true, selected, onSelect }: Props) {
    const queryClient = useQueryClient();
    const [details, setDetails] = useState<Copy | null>(null);
    const [restoring, setRestoring] = useState<Copy | null>(null);
    const [removing, setRemoving] = useState<Copy | null>(null);
    const refresh = () => queryClient.invalidateQueries({ queryKey: keys.all });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const retry = useMutation({ mutationFn: (copy: Copy) => retryCopy(copy.id), onSuccess: () => (toast.success('Copy queued again.'), refresh()), onError });
    const check = useMutation({
        mutationFn: (copy: Copy) => checkCopy(copy.id),
        onSuccess: (result) => (result.ok ? toast.success(result.message || 'The copy reads back intact.') : toast.error(result.message), refresh()),
        onError,
    });
    const download = useMutation({ mutationFn: (copy: Copy) => downloadCopy(copy.id), onSuccess: (url) => window.location.assign(url), onError });
    const restore = useMutation({
        mutationFn: ({ copy, truncate }: { copy: Copy; truncate: boolean }) => restoreCopy(copy.id, truncate),
        onSuccess: () => (toast.success('Restore started. The server is unavailable until it finishes.'), setRestoring(null), refresh()),
        onError,
    });
    const removal = useMutation({ mutationFn: (copy: Copy) => deleteCopy(copy.id), onSuccess: () => (setRemoving(null), refresh()), onError });

    const ids = rows.map((row) => row.id);
    const allSelected = selected !== undefined && ids.length > 0 && ids.every((id) => selected.includes(id));

    const columns: TableColumn<Copy>[] = [
        ...(selected && onSelect
            ? [
                  {
                      id: 'select',
                      label: <Checkbox aria-label='Select all copies on this page' checked={allSelected} onChange={(checked) => onSelect(checked ? [...new Set([...selected, ...ids])] : selected.filter((id) => !ids.includes(id)))} />,
                      headerClassName: 'ofs:w-10',
                      cell: (copy: Copy) => (
                          <Checkbox
                              aria-label={`Select ${title(copy)} on ${copy.destination.name}`}
                              checked={selected.includes(copy.id)}
                              onChange={(checked) => onSelect(checked ? [...selected, copy.id] : selected.filter((id) => id !== copy.id))}
                          />
                      ),
                  },
              ]
            : []),
        ...(hideServer
            ? []
            : [
                  {
                      id: 'server',
                      label: 'Server',
                      cell: (copy: Copy) => (
                          <span className='ofs:block ofs:max-w-48 ofs:truncate' title={copy.server.name}>
                              {copy.server.name}
                          </span>
                      ),
                  },
              ]),
        {
            id: 'backup',
            label: 'Backup',
            cell: (copy) => (
                <div className='ofs:min-w-0 ofs:max-w-64'>
                    <p className='ofs:flex ofs:min-w-0 ofs:items-center ofs:gap-2'>
                        <span className='ofs:truncate ofs:text-foreground' title={title(copy)}>
                            {title(copy)}
                        </span>
                        {copy.kind === 'backup' && !copy.backup.local && (
                            <Badge variant='soft' tone='info' size='sm' className='ofs:shrink-0 ofs:whitespace-nowrap'>
                                Kept off-site
                            </Badge>
                        )}
                    </p>
                    {copy.rule && <p className='ofs:truncate ofs:text-xs ofs:text-muted-foreground'>{copy.rule.name}</p>}
                </div>
            ),
        },
        { id: 'destination', label: 'Destination', cell: (copy) => <span className='ofs:whitespace-nowrap'>{copy.destination.name}</span> },
        { id: 'status', label: 'Status', cell: (copy) => <CopyStatusBadge copy={copy} /> },
        {
            id: 'size',
            label: 'Size',
            cell: (copy) => (
                <div className='ofs:whitespace-nowrap'>
                    <p>{formatBytes(copy.source_bytes ?? (copy.progress.total || null))}</p>
                    {copy.stored_bytes !== null && copy.destination.format === 'dedup' && <p className='ofs:text-xs ofs:text-muted-foreground'>{formatBytes(copy.stored_bytes)} new</p>}
                </div>
            ),
        },
        {
            id: 'created',
            label: 'Created',
            cell: (copy) => (
                <span className='ofs:whitespace-nowrap' title={formatDate(copy.created_at)}>
                    {formatAgo(copy.created_at)}
                </span>
            ),
        },
    ];

    if (actions) {
        columns.push(
            actionsColumn(3, (copy: Copy) => {
                const verified = copy.status === 'verified';
                const name = title(copy);

                return (
                    <RowActions>
                        <RowActionButton
                            aria-label={`Restore ${name}`}
                            icon={RotateCcw}
                            label='Restore'
                            disabled={!verified || copy.kind !== 'backup' || copy.server.id === null}
                            disabledReason={copy.server.id === null ? 'The server no longer exists' : copy.kind !== 'backup' ? 'Database dumps are restored by hand' : 'Only finished copies can be restored'}
                            onClick={() => setRestoring(copy)}
                        />
                        <RowActionButton
                            aria-label={`Download ${name}`}
                            icon={Download}
                            label='Download'
                            disabled={!verified || download.isPending}
                            disabledReason='Only finished copies can be downloaded'
                            onClick={() => download.mutate(copy)}
                        />
                        <DeleteAction aria-label={`Delete ${name}`} disabled={copy.status === 'running'} disabledReason='Wait until the copy finishes' onClick={() => setRemoving(copy)} />
                        <RowActionsMenu label={`More actions for ${name}`}>
                            <DropdownMenuItem icon={Info} onClick={() => setDetails(copy)}>
                                Details
                            </DropdownMenuItem>
                            {copy.status === 'failed' && (
                                <DropdownMenuItem icon={RefreshCw} onClick={() => retry.mutate(copy)}>
                                    Retry
                                </DropdownMenuItem>
                            )}
                            {verified && (
                                <DropdownMenuItem icon={ShieldCheck} onClick={() => check.mutate(copy)}>
                                    Check
                                </DropdownMenuItem>
                            )}
                        </RowActionsMenu>
                    </RowActions>
                );
            }, 'ofs:w-40')
        );
    }

    return (
        <>
            <div className='ofs:min-w-0 ofs:max-w-full ofs:overflow-x-auto'>
                <Table rows={rows} columns={columns} keyOf={(copy) => String(copy.id)} emptyState={emptyState} isFetching={isFetching} pagination={pagination} />
            </div>
            <CopyDetailsDialog copy={details} onClose={() => setDetails(null)} />
            <RestoreDialog name={restoring ? title(restoring) : null} pending={restore.isPending} onClose={() => setRestoring(null)} onRestore={(truncate) => restoring && restore.mutate({ copy: restoring, truncate })} />
            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title={`Delete this copy of ${removing ? title(removing) : ''}?`}
                confirm='Delete'
                pending={removal.isPending}
                onConfirmed={() => removing && removal.mutate(removing)}
            >
                The copy is removed from {removing?.destination.name}. The backup on the node is not touched.
            </Dialog.Confirm>
        </>
    );
}
