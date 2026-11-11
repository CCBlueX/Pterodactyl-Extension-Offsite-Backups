import type { ReactNode } from 'react';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle, NamedIcon } from '@pterodactyl/sdk';

interface Props {
    icon: string;
    title: string;
    description: string;
    /** Page level empty states sit in a card; compact ones go inside cards, tables and dialogs. */
    compact?: boolean;
    action?: ReactNode;
}

export default function EmptyState({ icon, title, description, compact = false, action }: Props) {
    const empty = (
        <Empty size={compact ? 'compact' : 'default'}>
            <EmptyHeader>
                <EmptyMedia variant='icon'>
                    <NamedIcon name={icon} />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
            {action && <EmptyContent>{action}</EmptyContent>}
        </Empty>
    );

    return compact ? empty : <div className='ofs:rounded-sm ofs:border ofs:border-border ofs:bg-card'>{empty}</div>;
}
