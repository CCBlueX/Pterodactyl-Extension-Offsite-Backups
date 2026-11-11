import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useCurrentServerRequired } from '@pterodactyl/sdk';
import type { ViewProps } from '@pterodactyl/sdk/theme';
import { keys } from '../api';
import OffsiteCopiesCard from '../components/OffsiteCopiesCard';
import ProtectionBanner from '../components/ProtectionBanner';

/** The native backup list, with the off-site banner above and the off-site copies below it, inside the page. */
export default function BackupList({ model, Default }: ViewProps<'server.backups'>) {
    const queryClient = useQueryClient();
    const { identifier } = useCurrentServerRequired().attributes;
    const signature = model.backups.map((backup) => `${backup.uuid}:${backup.completedAt ?? ''}`).join(',');
    const seen = useRef(signature);

    // A finished or deleted backup changes which copies exist and which are kept only off-site.
    useEffect(() => {
        if (seen.current !== signature) {
            seen.current = signature;
            void queryClient.invalidateQueries({ queryKey: keys.client(identifier) });
        }
    }, [signature, identifier, queryClient]);

    return (
        <>
            <ProtectionBanner />
            <Default model={model} />
            <OffsiteCopiesCard />
        </>
    );
}
