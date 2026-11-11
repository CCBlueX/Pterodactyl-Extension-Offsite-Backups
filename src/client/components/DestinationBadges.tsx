import { Badge, Tooltip } from '@pterodactyl/sdk';
import type { Destination } from '../types';
import { formatAgo } from '../lib/format';

/** Kind, format and encryption of a destination as small pills. */
export default function DestinationBadges({ destination }: { destination: Pick<Destination, 'kind' | 'format' | 'encrypted'> & { is_default?: boolean } }) {
    return (
        <span className='ofs:mt-1 ofs:flex ofs:flex-wrap ofs:gap-1'>
            {destination.is_default && (
                <Badge variant='soft' tone='accent' size='sm'>
                    Default
                </Badge>
            )}
            <Badge variant='soft' size='sm'>
                {destination.kind === 's3' ? 'S3' : 'SFTP'}
            </Badge>
            <Badge variant='soft' size='sm'>
                {destination.format === 'dedup' ? 'Deduplicated' : 'Archive'}
            </Badge>
            {destination.encrypted && (
                <Badge variant='soft' tone='info' size='sm'>
                    Encrypted
                </Badge>
            )}
        </span>
    );
}

export function DestinationStatusBadge({ destination }: { destination: Pick<Destination, 'status'> & { status_message?: string | null; checked_at?: string | null } }) {
    if (destination.status === 'ok') {
        return (
            <Badge variant='soft' tone='success' size='sm' title={destination.checked_at ? `Checked ${formatAgo(destination.checked_at)}` : undefined}>
                Connected
            </Badge>
        );
    }

    const badge = (
        <Badge variant='soft' tone={destination.status === 'paused' ? 'warning' : 'danger'} size='sm'>
            {destination.status === 'paused' ? 'Paused' : 'Error'}
        </Badge>
    );

    return destination.status_message ? (
        <Tooltip content={destination.status_message}>
            <span tabIndex={0} aria-label={destination.status_message} className='ofs:inline-flex ofs:cursor-help'>
                {badge}
            </span>
        </Tooltip>
    ) : (
        badge
    );
}
