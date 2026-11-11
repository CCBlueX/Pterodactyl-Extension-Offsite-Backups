import { useQuery } from '@tanstack/react-query';
import { useCurrentServerRequired } from '@pterodactyl/sdk';
import { getClientOverview, keys } from '../api';

/** The off-site state of the current server, polled every 10 seconds while a copy runs. */
export default function useClientOverview() {
    const { identifier, uuid } = useCurrentServerRequired().attributes;
    const query = useQuery({
        queryKey: keys.client(identifier),
        queryFn: () => getClientOverview(identifier),
        refetchInterval: (current) => (current.state.data?.copies.some((copy) => copy.status === 'copying') ? 10_000 : false),
    });

    return { identifier, uuid, query };
}
