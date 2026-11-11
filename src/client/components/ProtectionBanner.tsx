import { ShieldCheck } from 'lucide-react';
import { formatBytes } from '../lib/format';
import useClientOverview from './useClientOverview';

/** Above the Backups page: tells users their backups also live off-site. */
export default function ProtectionBanner() {
    const { data } = useClientOverview().query;

    if (!data || (!data.protected && data.count === 0)) {
        return null;
    }

    const details = [data.destinations.join(', '), data.count > 0 && `${data.count} ${data.count === 1 ? 'copy' : 'copies'} · ${formatBytes(data.stored_bytes)} stored`].filter(Boolean).join(' · ');

    return (
        <div className='ofs:mb-4 ofs:flex ofs:items-center ofs:gap-3 ofs:rounded-sm ofs:border ofs:border-border ofs:bg-card ofs:p-3' role='status'>
            <span className='ofs:flex ofs:size-9 ofs:shrink-0 ofs:items-center ofs:justify-center ofs:rounded-sm ofs:bg-success/10 ofs:text-success'>
                <ShieldCheck className='ofs:size-5' aria-hidden />
            </span>
            <div className='ofs:min-w-0'>
                <p className='ofs:text-sm ofs:font-medium ofs:text-foreground'>{data.protected ? 'Your server is protected by off-site backups' : 'Off-site copies of your backups'}</p>
                {details && <p className='ofs:truncate ofs:text-xs ofs:text-muted-foreground'>{details}</p>}
            </div>
        </div>
    );
}
