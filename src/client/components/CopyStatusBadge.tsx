import { Badge, ProgressBar, Tooltip } from '@pterodactyl/sdk';
import type { Copy } from '../types';
import { formatAgo, formatBytes, percent } from '../lib/format';

/** Off-site, Copying with its bar, Queued or Failed with the error on hover. */
export default function CopyStatusBadge({ copy }: { copy: Pick<Copy, 'status' | 'progress' | 'error' | 'attempts' | 'next_attempt_at'> }) {
    if (copy.status === 'verified') {
        return (
            <Badge variant='soft' tone='success' size='sm' className='ofs:whitespace-nowrap'>
                Off-site
            </Badge>
        );
    }

    if (copy.status === 'running') {
        const value = percent(copy.progress);
        const label = value === null ? `${formatBytes(copy.progress.done)} copied` : `${Math.floor(value)}% of ${formatBytes(copy.progress.total)}`;

        return (
            <div className='ofs:grid ofs:w-36 ofs:gap-1'>
                <Badge variant='soft' tone='info' size='sm' className='ofs:w-fit ofs:whitespace-nowrap'>
                    Copying
                </Badge>
                <ProgressBar value={value} tone='info' label={label} />
                <span className='ofs:text-xs ofs:text-muted-foreground'>{label}</span>
            </div>
        );
    }

    const failed = copy.status === 'failed';
    const badge = (
        <Badge variant='soft' tone={failed ? 'danger' : copy.attempts > 0 ? 'warning' : 'neutral'} size='sm' className='ofs:whitespace-nowrap'>
            {failed ? 'Failed' : 'Queued'}
        </Badge>
    );

    if (!copy.error) {
        return badge;
    }

    const content = failed ? copy.error : `Attempt ${copy.attempts} failed: ${copy.error}${copy.next_attempt_at ? ` Next try ${formatAgo(copy.next_attempt_at)}.` : ''}`;

    return (
        <Tooltip content={content}>
            <span tabIndex={0} aria-label={content} className='ofs:inline-flex ofs:cursor-help'>
                {badge}
            </span>
        </Tooltip>
    );
}
