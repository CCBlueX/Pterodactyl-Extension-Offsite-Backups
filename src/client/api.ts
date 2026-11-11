import { http } from '@pterodactyl/sdk';
import type {
    Allowance,
    BulkResult,
    ClientOverview,
    Copy,
    CopyFilters,
    CopyPage,
    Destination,
    DestinationInput,
    LookupType,
    Named,
    Overview,
    Rule,
    RuleInput,
    ServerDetails,
    Storage,
    TestResult,
} from './types';

export type * from './types';

const admin = '/api/admin/extensions/offsite-backups';
const server = (identifier: string) => `/api/client/servers/${identifier}/extensions/offsite-backups`;

export const keys = {
    all: ['offsite-backups'] as const,
    overview: ['offsite-backups', 'overview'] as const,
    destinations: ['offsite-backups', 'destinations'] as const,
    rules: ['offsite-backups', 'rules'] as const,
    copies: (filters: CopyFilters) => ['offsite-backups', 'copies', filters] as const,
    storage: ['offsite-backups', 'storage'] as const,
    server: (id: number) => ['offsite-backups', 'server', id] as const,
    lookup: (type: LookupType, search: string) => ['offsite-backups', 'lookup', type, search] as const,
    client: (identifier: string) => ['offsite-backups', 'client', identifier] as const,
};

export const getOverview = async () => (await http.get<Overview>(`${admin}/overview`)).data;

export const getDestinations = async () => (await http.get<{ data: Destination[] }>(`${admin}/destinations`)).data.data;
export const saveDestination = async (input: DestinationInput, id?: number) => {
    if (id) {
        await http.patch(`${admin}/destinations/${id}`, input);
    } else {
        await http.post(`${admin}/destinations`, input);
    }
};
export const deleteDestination = async (id: number, withCopies: boolean) => {
    await http.delete(`${admin}/destinations/${id}`, { params: withCopies ? { with_copies: 1 } : {} });
};
/** Tests settings that are not saved yet; `id` lets empty secrets fall back to that destination's stored ones. */
export const testDestinationInput = async (input: DestinationInput, id?: number) => (await http.post<TestResult>(`${admin}/destinations/test`, id ? { ...input, id } : input)).data;
export const testDestination = async (id: number) => (await http.post<TestResult>(`${admin}/destinations/${id}/test`)).data;
export const makeDefaultDestination = async (id: number) => {
    await http.post(`${admin}/destinations/${id}/default`);
};

export const getStorage = async () => (await http.get<Storage>(`${admin}/storage`)).data;
export const saveStorage = async (destinationId: number | null) => {
    await http.put(`${admin}/storage`, { destination_id: destinationId });
};

export const getRules = async () => (await http.get<{ data: Rule[] }>(`${admin}/rules`)).data.data;
export const saveRule = async (input: RuleInput, id?: number) => {
    if (id) {
        await http.patch(`${admin}/rules/${id}`, input);
    } else {
        await http.post(`${admin}/rules`, input);
    }
};
export const deleteRule = async (id: number) => {
    await http.delete(`${admin}/rules/${id}`);
};
export const runRule = async (id: number) => (await http.post<{ queued: number }>(`${admin}/rules/${id}/run`)).data;

export const getCopies = async ({ search, status, destination, server: serverId, page }: CopyFilters) =>
    (
        await http.get<CopyPage>(`${admin}/copies`, {
            params: { search: search || undefined, status: status || undefined, destination: destination || undefined, server: serverId || undefined, page },
        })
    ).data;
export const getCopy = async (id: number) => (await http.get<Copy>(`${admin}/copies/${id}`)).data;
export const retryCopy = async (id: number) => {
    await http.post(`${admin}/copies/${id}/retry`);
};
export const checkCopy = async (id: number) => (await http.post<TestResult>(`${admin}/copies/${id}/check`)).data;
export const deleteCopy = async (id: number) => {
    await http.delete(`${admin}/copies/${id}`);
};
export const bulkCopies = async (action: 'retry' | 'delete', ids: number[]) => (await http.post<BulkResult>(`${admin}/copies/bulk`, { action, ids })).data;
export const downloadCopy = async (id: number) => (await http.post<{ url: string }>(`${admin}/copies/${id}/download`)).data.url;
export const restoreCopy = async (id: number, truncate: boolean) => {
    await http.post(`${admin}/copies/${id}/restore`, { truncate });
};

export const getServerDetails = async (id: number) => (await http.get<ServerDetails>(`${admin}/servers/${id}`)).data;
export const saveAllowance = async (id: number, allowance: Allowance) => {
    await http.put(`${admin}/servers/${id}`, allowance);
};
export const copyLatest = async (id: number, destinationId?: number) => (await http.post<{ copy: Copy | null }>(`${admin}/servers/${id}/copy`, destinationId ? { destination_id: destinationId } : {})).data.copy;

export const lookup = async (type: LookupType, search: string) => (await http.get<{ data: Named[] }>(`${admin}/lookup`, { params: { type, search } })).data.data;

export const getClientOverview = async (identifier: string) => (await http.get<ClientOverview>(server(identifier))).data;
export const restoreClientCopy = async (identifier: string, uuid: string, truncate: boolean) => {
    await http.post(`${server(identifier)}/copies/${uuid}/restore`, { truncate });
};
export const downloadClientCopy = async (identifier: string, uuid: string) => (await http.post<{ url: string }>(`${server(identifier)}/copies/${uuid}/download`)).data.url;
