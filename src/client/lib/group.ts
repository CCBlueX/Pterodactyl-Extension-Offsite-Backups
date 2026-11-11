import type { ClientCopy } from '../types';

/** One backup on the Backups page, with its copies on every destination. */
export interface OffsiteBackup {
    key: string;
    name: string;
    local: boolean;
    bytes: number | null;
    createdAt: string;
    destinations: string[];
    /** The copy restores and downloads use: the first finished one in rule order, else the first. */
    primary: ClientCopy;
    copying: ClientCopy | null;
}

/** Groups copies (newest first, as the API sends them) by backup, keeping that order. */
export function groupCopies(copies: ClientCopy[]): OffsiteBackup[] {
    const groups = new Map<string, ClientCopy[]>();

    for (const copy of copies) {
        const key = copy.backup_uuid ?? copy.uuid;
        groups.set(key, [...(groups.get(key) ?? []), copy]);
    }

    return [...groups].map(([key, group]) => {
        // Copies of one backup are created in destination order, so the oldest is the primary one.
        const ordered = [...group].reverse();
        const primary = ordered.find((copy) => copy.status === 'off-site') ?? ordered[0];

        return {
            key,
            name: primary.backup_name,
            local: primary.local,
            bytes: primary.bytes,
            createdAt: ordered[0].created_at,
            destinations: ordered.map((copy) => copy.destination),
            primary,
            copying: ordered.some((copy) => copy.status === 'off-site') ? null : (ordered.find((copy) => copy.status === 'copying') ?? null),
        };
    });
}
