import { bytesToString } from '@pterodactyl/sdk';
import type { StorageChoice } from '../types';

export const STORAGE_LABELS: Record<StorageChoice, string> = { default: 'Panel default', node: 'On the node', s3: 'Directly in S3' };

export function formatBytes(bytes: number | null | undefined): string {
    return bytes === null || bytes === undefined ? '–' : bytesToString(bytes);
}

export function formatAgo(value: string | null, now = Date.now()): string {
    if (!value) {
        return 'never';
    }

    const minutes = Math.round((now - new Date(value).getTime()) / 60000);

    if (minutes < 0) return formatIn(-minutes);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)} h ago`;

    return `${Math.round(minutes / 1440)} d ago`;
}

function formatIn(minutes: number): string {
    if (minutes < 60) return `in ${Math.max(1, minutes)} min`;
    if (minutes < 1440) return `in ${Math.round(minutes / 60)} h`;

    return `in ${Math.round(minutes / 1440)} d`;
}

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '–';
}

export const plural = (count: number, word: string, many = `${word}s`) => `${count} ${count === 1 ? word : many}`;

/** Percent done, or null while the total is unknown. */
export const percent = ({ done, total }: { done: number; total: number }) => (total > 0 ? Math.min(100, (done / total) * 100) : null);
