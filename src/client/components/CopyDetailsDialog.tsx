import type { ReactNode } from 'react';
import { Badge, Button, Dialog } from '@pterodactyl/sdk';
import type { Copy } from '../types';
import { formatBytes, formatDate } from '../lib/format';
import CopyStatusBadge from './CopyStatusBadge';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className='ofs:grid ofs:grid-cols-[9rem_minmax(0,1fr)] ofs:gap-3 ofs:text-sm'>
            <dt className='ofs:text-muted-foreground'>{label}</dt>
            <dd className='ofs:min-w-0 ofs:break-words ofs:text-foreground'>{children}</dd>
        </div>
    );
}

const FORMATS = { archive: 'Archive', dedup: 'Deduplicated' };

export default function CopyDetailsDialog({ copy, onClose }: { copy: Copy | null; onClose: () => void }) {
    return (
        <Dialog open={copy !== null} onClose={onClose} title={copy ? copy.backup.name : ''}>
            {copy && (
                <dl className='ofs:grid ofs:gap-2'>
                    <Row label='Status'>
                        <CopyStatusBadge copy={copy} />
                    </Row>
                    <Row label='Server'>{copy.server.name}</Row>
                    <Row label={copy.kind === 'database' ? 'Database' : 'Backup'}>
                        <span className='ofs:flex ofs:flex-wrap ofs:items-center ofs:gap-2'>
                            {copy.kind === 'database' ? copy.database : copy.backup.name}
                            {copy.kind === 'backup' && !copy.backup.local && (
                                <Badge variant='soft' tone='info' size='sm'>
                                    Kept off-site
                                </Badge>
                            )}
                        </span>
                    </Row>
                    <Row label='Destination'>
                        {copy.destination.name} · {copy.destination.kind.toUpperCase()} · {FORMATS[copy.destination.format]}
                    </Row>
                    <Row label='Rule'>{copy.rule?.name ?? 'Started by hand'}</Row>
                    <Row label='Backup size'>{formatBytes(copy.source_bytes)}</Row>
                    <Row label='Stored'>{formatBytes(copy.stored_bytes)}</Row>
                    <Row label='Attempts'>{copy.attempts}</Row>
                    {copy.checksum && (
                        <Row label='SHA-1'>
                            <code className='ofs:font-mono ofs:text-xs'>{copy.checksum}</code>
                        </Row>
                    )}
                    <Row label='Created'>{formatDate(copy.created_at)}</Row>
                    <Row label='Finished'>{formatDate(copy.finished_at)}</Row>
                    <Row label='Verified'>{formatDate(copy.verified_at)}</Row>
                    <Row label='Last checked'>{formatDate(copy.checked_at)}</Row>
                    {copy.error && (
                        <Row label='Error'>
                            <span className='ofs:text-destructive'>{copy.error}</span>
                        </Row>
                    )}
                    {Object.entries(copy.details ?? {}).map(([key, value]) => (
                        <Row key={key} label={key.replaceAll('_', ' ')}>
                            {key.endsWith('bytes') && typeof value === 'number' ? formatBytes(value) : String(value)}
                        </Row>
                    ))}
                </dl>
            )}
            <Dialog.Footer>
                <Button.Text onClick={onClose}>Close</Button.Text>
            </Dialog.Footer>
        </Dialog>
    );
}
