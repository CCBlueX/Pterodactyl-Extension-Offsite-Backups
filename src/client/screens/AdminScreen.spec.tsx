import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import type * as sdk from '@pterodactyl/sdk';
import * as api from '../api';
import AdminScreen from './AdminScreen';

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }));

// The test host has no extension screens, so links and navigation are checked by destination.
vi.mock('@pterodactyl/sdk', async (importOriginal) => ({
    ...(await importOriginal<typeof sdk>()),
    usePanelNavigate: () => navigate,
    PanelLink: ({ children, className }: { children: React.ReactNode; className?: string }) => <a className={className}>{children}</a>,
}));

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof api>()),
    getCopies: vi.fn(),
    getDestinations: vi.fn(),
    lookup: vi.fn().mockResolvedValue([]),
    bulkCopies: vi.fn().mockResolvedValue({ done: 2, failed: 0 }),
    retryCopy: vi.fn().mockResolvedValue(undefined),
}));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

const copy = (changes: Partial<api.Copy>): api.Copy => ({
    id: 1,
    uuid: 'u1',
    server: { id: 7, uuid: 's1', name: 'Survival' },
    backup: { uuid: 'b1', name: 'Nightly', local: true },
    kind: 'backup',
    database: null,
    destination: { id: 1, name: 'Hetzner S3', kind: 's3', format: 'dedup' },
    rule: { id: 1, name: 'Every backup' },
    status: 'verified',
    attempts: 0,
    progress: { done: 0, total: 0 },
    source_bytes: 1024 ** 3,
    stored_bytes: 50 * 1024 ** 2,
    checksum: 'abc',
    verified_at: new Date().toISOString(),
    checked_at: null,
    error: null,
    details: null,
    created_at: new Date(Date.now() - 3600_000).toISOString(),
    finished_at: null,
    next_attempt_at: null,
    ...changes,
});

function renderTab(tab: string) {
    vi.mocked(api.getDestinations).mockResolvedValue([]);
    vi.mocked(api.getCopies).mockResolvedValue({
        data: [
            copy({}),
            copy({ id: 2, backup: { uuid: 'b2', name: 'Before update', local: false } }),
            copy({ id: 3, backup: { uuid: 'b3', name: 'Hourly', local: true }, status: 'running', progress: { done: 256 * 1024 ** 2, total: 1024 ** 3 } }),
            copy({ id: 4, backup: { uuid: 'b4', name: 'Weekly', local: true }, status: 'failed', attempts: 5, error: 'Bucket not found' }),
            copy({ id: 5, backup: { uuid: 'b5', name: 'Fresh', local: true }, status: 'queued' }),
        ],
        meta: { page: 1, per_page: 25, total: 5 },
    });
    host = createExtensionTestHost({ extensionId: 'offsite-backups', prefix: 'ofs' });
    render(<AdminScreen data={{ pathname: `/panel/offsite-backups/${tab}`, search: {}, params: { tab } }} />, { wrapper: host.Wrapper });
}

test('shows each copy with its status', async () => {
    renderTab('copies');

    expect(await screen.findByText('Before update')).toBeTruthy();
    expect(screen.getAllByText('Off-site')).toHaveLength(2);
    expect(screen.getByText('Kept off-site')).toBeTruthy();
    expect(screen.getByText('Copying')).toBeTruthy();
    expect(screen.getByText('25% of 1 GiB')).toBeTruthy();
    expect(screen.getByText('Queued')).toBeTruthy();
    expect(screen.getByText('Failed')).toBeTruthy();
    expect(screen.getByLabelText('Bucket not found')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Restore Hourly' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Delete Hourly' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByRole('tab', { name: 'Copies' }).getAttribute('aria-selected')).toBe('true');
});

test('filters by search text and resets to the first page', async () => {
    renderTab('copies');

    await screen.findByText('Nightly');
    expect(api.getCopies).toHaveBeenLastCalledWith({ search: '', status: '', destination: '', server: '', page: 1 });

    fireEvent.change(screen.getByLabelText('Search copies'), { target: { value: 'survival' } });
    await waitFor(() => expect(api.getCopies).toHaveBeenLastCalledWith({ search: 'survival', status: '', destination: '', server: '', page: 1 }));
});

test('filters by status', async () => {
    renderTab('copies');

    await screen.findByText('Nightly');
    const status = document.getElementById('ofs-copies-status')!.closest('[role="group"]') as HTMLElement;
    fireEvent.click(within(status).getByRole('button', { name: 'Open popup' }));
    fireEvent.click(await screen.findByRole('option', { name: 'Failed' }));

    await waitFor(() => expect(api.getCopies).toHaveBeenLastCalledWith({ search: '', status: 'failed', destination: '', server: '', page: 1 }));
});

test('retries a failed copy from its menu and bulk deletes selected copies', async () => {
    renderTab('copies');

    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Weekly' }));
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Retry' }));
    await waitFor(() => expect(api.retryCopy).toHaveBeenCalledWith(4));

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Nightly on Hetzner S3' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Weekly on Hetzner S3' }));
    expect(screen.getByText('2 selected')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Delete 2 copies?')).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(api.bulkCopies).toHaveBeenCalledWith('delete', [1, 4]));
});

test('switches tabs through the panel router', async () => {
    renderTab('copies');

    fireEvent.click(await screen.findByRole('tab', { name: 'Backup rules' }));
    expect(navigate).toHaveBeenCalledWith({ extension: 'offsite-backups', screen: 'admin', params: { tab: 'rules' } });
});
