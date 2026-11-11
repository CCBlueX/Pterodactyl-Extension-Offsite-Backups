import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Input, Select, ServerError, Spinner, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { bulkCopies, getCopies, getDestinations, keys, lookup } from '../../api';
import type { CopyFilters, CopyStatus, Named } from '../../types';
import CopiesTable from '../../components/CopiesTable';
import EmptyState from '../../components/EmptyState';
import { plural } from '../../lib/format';

const STATUSES = [
    { value: '', label: 'Any status' },
    { value: 'verified', label: 'Off-site' },
    { value: 'running', label: 'Copying' },
    { value: 'queued', label: 'Queued' },
    { value: 'failed', label: 'Failed' },
];

function ServerFilter({ value, onChange }: { value: Named | null; onChange: (server: Named | null) => void }) {
    const [search, setSearch] = useState('');
    const results = useQuery({ queryKey: keys.lookup('servers', search), queryFn: () => lookup('servers', search), placeholderData: keepPreviousData });
    const known = new Map([...(value ? [value] : []), ...(results.data ?? [])].map((item) => [item.id, item]));

    return (
        <Select
            id='ofs-copies-server'
            placeholder='Any server'
            searchPlaceholder='Search servers'
            value={value?.id ?? 0}
            onSearchChange={setSearch}
            onChange={(picked) => onChange(known.get(Number(picked)) ?? null)}
            options={[{ value: 0, label: 'Any server' }, ...[...known.values()].map((item) => ({ value: item.id, label: item.name }))]}
        />
    );
}

export default function CopiesTab() {
    const queryClient = useQueryClient();
    const [filters, setFilters] = useState<CopyFilters>({ search: '', status: '', destination: '', server: '', page: 1 });
    const [text, setText] = useState('');
    const [server, setServer] = useState<Named | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkDelete, setBulkDelete] = useState(false);
    const destinations = useQuery({ queryKey: keys.destinations, queryFn: getDestinations });
    const copies = useQuery({
        queryKey: keys.copies(filters),
        queryFn: () => getCopies(filters),
        placeholderData: keepPreviousData,
        refetchInterval: (query) => (query.state.data?.data.some((copy) => copy.status === 'running' || copy.status === 'queued') ? 10_000 : false),
    });
    const filter = (changes: Partial<CopyFilters>) => (setFilters((current) => ({ ...current, ...changes, page: changes.page ?? 1 })), setSelected([]));

    useEffect(() => {
        const timer = setTimeout(() => text !== filters.search && filter({ search: text }), 300);

        return () => clearTimeout(timer);
    }, [text]);

    const bulk = useMutation({
        mutationFn: (action: 'retry' | 'delete') => bulkCopies(action, selected),
        onSuccess: ({ done, failed }, action) => {
            const verb = action === 'retry' ? 'queued again' : 'deleted';
            failed > 0 ? toast.error(`${plural(done, 'copy', 'copies')} ${verb}, ${failed} could not be.`) : toast.success(`${plural(done, 'copy', 'copies')} ${verb}.`);
            setSelected([]);
            setBulkDelete(false);
            void queryClient.invalidateQueries({ queryKey: keys.all });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const filtered = filters.search !== '' || filters.status !== '' || filters.destination !== '' || filters.server !== '';

    return (
        <>
            <div className='ofs:mb-4 ofs:grid ofs:gap-3 ofs:sm:grid-cols-2 ofs:lg:grid-cols-4'>
                <Input aria-label='Search copies' type='search' placeholder='Search server or backup' value={text} onChange={(event) => setText(event.currentTarget.value)} />
                <Select id='ofs-copies-status' value={filters.status} onChange={(value) => filter({ status: value as CopyStatus | '' })} options={STATUSES} />
                <Select
                    id='ofs-copies-destination'
                    value={filters.destination === '' ? 0 : filters.destination}
                    onChange={(value) => filter({ destination: Number(value) || '' })}
                    options={[{ value: 0, label: 'Any destination' }, ...(destinations.data ?? []).map((destination) => ({ value: destination.id, label: destination.name }))]}
                />
                <ServerFilter value={server} onChange={(picked) => (setServer(picked), filter({ server: picked?.id ?? '' }))} />
            </div>

            {selected.length > 0 && (
                <div className='ofs:mb-3 ofs:flex ofs:flex-wrap ofs:items-center ofs:gap-3 ofs:rounded-sm ofs:border ofs:border-border ofs:bg-card ofs:px-3 ofs:py-2 ofs:text-sm'>
                    <span className='ofs:flex-1'>{selected.length} selected</span>
                    <Button.Text size='xsmall' disabled={bulk.isPending} onClick={() => bulk.mutate('retry')}>
                        Retry failed
                    </Button.Text>
                    <Button.Danger size='xsmall' disabled={bulk.isPending} onClick={() => setBulkDelete(true)}>
                        Delete
                    </Button.Danger>
                </div>
            )}

            {!copies.data ? (
                copies.isError ? <ServerError title='Could not load copies' message={httpErrorToHuman(copies.error)} onRetry={() => copies.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />
            ) : (
                <CopiesTable
                    rows={copies.data.data}
                    isFetching={copies.isFetching}
                    selected={selected}
                    onSelect={setSelected}
                    pagination={
                        copies.data.meta.total > copies.data.meta.per_page
                            ? { page: copies.data.meta.page, perPage: copies.data.meta.per_page, total: copies.data.meta.total, count: copies.data.data.length, itemLabel: 'copies', onPageSelect: (page) => filter({ page }) }
                            : undefined
                    }
                    emptyState={
                        filtered ? (
                            <EmptyState compact icon='search-x' title='No matching copies' description='Try other filters.' />
                        ) : (
                            <EmptyState compact icon='archive' title='No copies yet' description='Backups are copied here once a rule matches them.' />
                        )
                    }
                />
            )}

            <Dialog.Confirm open={bulkDelete} onClose={() => setBulkDelete(false)} title={`Delete ${plural(selected.length, 'copy', 'copies')}?`} confirm='Delete' pending={bulk.isPending} onConfirmed={() => bulk.mutate('delete')}>
                The copies are removed from their destinations. Backups on the nodes are not touched. Running copies are skipped.
            </Dialog.Confirm>
        </>
    );
}
