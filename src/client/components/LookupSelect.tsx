import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Select } from '@pterodactyl/sdk';
import { keys, lookup } from '../api';
import type { LookupType, Named } from '../types';

interface Props {
    id: string;
    type: LookupType;
    value: Named[];
    onChange: (value: Named[]) => void;
    placeholder: string;
}

/** A searchable multi-select over nodes, eggs or servers that keeps the names of picked items. */
export default function LookupSelect({ id, type, value, onChange, placeholder }: Props) {
    const [search, setSearch] = useState('');
    const results = useQuery({ queryKey: keys.lookup(type, search), queryFn: () => lookup(type, search), placeholderData: (previous) => previous });
    const known = new Map([...(results.data ?? []), ...value].map((item) => [item.id, item]));

    return (
        <Select
            id={id}
            multiple
            placeholder={placeholder}
            searchPlaceholder='Search by name'
            emptyMessage={results.isFetching ? 'Searching…' : 'Nothing found'}
            value={value.map((item) => item.id)}
            onSearchChange={setSearch}
            onChange={(ids) => onChange(ids.map((picked) => known.get(Number(picked))).filter((item): item is Named => item !== undefined))}
            options={[...known.values()].map((item) => ({ value: item.id, label: item.name }))}
        />
    );
}
