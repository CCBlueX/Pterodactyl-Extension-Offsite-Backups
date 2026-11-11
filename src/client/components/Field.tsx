import type { ReactNode } from 'react';
import { Label } from '@pterodactyl/sdk';

interface Props {
    id: string;
    label: string;
    help?: ReactNode;
    error?: string;
    children: ReactNode;
}

/** A labelled control with one line of help, replaced by the error when there is one. */
export default function Field({ id, label, help, error, children }: Props) {
    return (
        <div className='ofs:grid ofs:min-w-0 ofs:content-start ofs:gap-1'>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? <p className='ofs:text-xs ofs:text-destructive'>{error}</p> : help && <p className='ofs:text-xs ofs:text-muted-foreground'>{help}</p>}
        </div>
    );
}

export function SectionTitle({ children }: { children: ReactNode }) {
    return <h2 className='ofs:mb-3 ofs:font-header ofs:text-sm ofs:font-semibold ofs:uppercase ofs:tracking-wide ofs:text-foreground'>{children}</h2>;
}

/** Number input text: empty means null. */
export const numberOrNull = (text: string): number | null => (text.trim() === '' ? null : Number(text));
