import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import OffsiteCopiesCard from './OffsiteCopiesCard';
import ProtectionBanner from './ProtectionBanner';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof api>()),
    getClientOverview: vi.fn(),
    restoreClientCopy: vi.fn().mockResolvedValue(undefined),
    downloadClientCopy: vi.fn(),
}));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

const copy = (changes: Partial<api.ClientCopy>): api.ClientCopy => ({
    uuid: 'c1',
    backup_name: 'Nightly',
    backup_uuid: 'b1',
    kind: 'backup',
    local: true,
    status: 'off-site',
    progress: { done: 0, total: 0 },
    bytes: 2 * 1024 ** 3,
    created_at: new Date(Date.now() - 2 * 3600_000).toISOString(),
    destination: 'Hetzner S3',
    ...changes,
});

function renderSlot(overview: api.ClientOverview, permissions: string[] = ['*']) {
    vi.mocked(api.getClientOverview).mockResolvedValue(overview);
    const server = createTestServer({ identifier: 'abcd1234', owner: permissions.includes('*'), permissions });
    host = createExtensionTestHost({ extensionId: 'offsite-backups', prefix: 'ofs', server });
    render(
        <>
            <ProtectionBanner />
            <p>Backups</p>
            <OffsiteCopiesCard />
        </>,
        { wrapper: host.Wrapper }
    );
}

test('the banner and card stay hidden for servers without off-site backups', async () => {
    renderSlot({ protected: false, destinations: [], stored_bytes: 0, count: 0, copies: [] });

    await waitFor(() => expect(api.getClientOverview).toHaveBeenCalledWith('abcd1234'));
    expect(await screen.findByText('Backups')).toBeTruthy();
    expect(screen.queryByText('Your server is protected by off-site backups')).toBeNull();
    expect(screen.queryByText('Off-site copies')).toBeNull();
});

test('the banner names the destinations, size and count', async () => {
    renderSlot({ protected: true, destinations: ['Hetzner S3', 'Storage box'], stored_bytes: 3 * 1024 ** 3, count: 4, copies: [copy({})] });

    expect(await screen.findByText('Your server is protected by off-site backups')).toBeTruthy();
    expect(screen.getByText('Hetzner S3, Storage box · 4 copies · 3 GiB stored')).toBeTruthy();
});

test('lists copies with status, kept off-site badge and both actions for the owner', async () => {
    renderSlot({
        protected: true,
        destinations: ['Hetzner S3'],
        stored_bytes: 1,
        count: 2,
        copies: [copy({ local: false }), copy({ uuid: 'c2', backup_uuid: 'b2', backup_name: 'Fresh', status: 'copying', progress: { done: 512, total: 1024 } })],
    });

    expect(await screen.findByText('Off-site copies')).toBeTruthy();
    expect(screen.getByText('Kept off-site')).toBeTruthy();
    expect(screen.getByText('Off-site')).toBeTruthy();
    expect(screen.getByText('Copying 50%')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Download Nightly' })).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Restore Fresh' }) as HTMLButtonElement).disabled).toBe(true);
});

test('shows one row per backup and acts on its first finished copy', async () => {
    renderSlot({
        protected: true,
        destinations: ['Hetzner S3', 'Storage box'],
        stored_bytes: 1,
        count: 2,
        copies: [copy({ uuid: 'c2', destination: 'Storage box' }), copy({ uuid: 'c1', destination: 'Hetzner S3', status: 'copying', progress: { done: 1, total: 2 } })],
    });

    expect(await screen.findByText('Hetzner S3, Storage box')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Restore Nightly' })).toHaveLength(1);
    expect(screen.getByText('Off-site')).toBeTruthy();

    vi.mocked(api.downloadClientCopy).mockResolvedValue('about:blank');
    fireEvent.click(screen.getByRole('button', { name: 'Download Nightly' }));
    await waitFor(() => expect(api.downloadClientCopy).toHaveBeenCalledWith('abcd1234', 'c2'));
});

test('hides download and restore from subusers without those permissions', async () => {
    renderSlot({ protected: true, destinations: ['Hetzner S3'], stored_bytes: 1, count: 1, copies: [copy({})] }, ['backup.read', 'backup.download']);

    expect(await screen.findByText('Nightly')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Download Nightly' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Restore Nightly' })).toBeNull();
});

test('restores after confirming, deleting files first when asked', async () => {
    renderSlot({ protected: true, destinations: ['Hetzner S3'], stored_bytes: 1, count: 1, copies: [copy({})] }, ['backup.read', 'backup.restore']);

    expect(screen.queryByRole('button', { name: 'Download Nightly' })).toBeNull();
    fireEvent.click(await screen.findByRole('button', { name: 'Restore Nightly' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Restore "Nightly"?')).toBeTruthy();

    fireEvent.click(within(dialog).getByRole('switch', { name: /Delete all files first/ }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Restore' }));

    await waitFor(() => expect(api.restoreClientCopy).toHaveBeenCalledWith('abcd1234', 'c1', true));
});
