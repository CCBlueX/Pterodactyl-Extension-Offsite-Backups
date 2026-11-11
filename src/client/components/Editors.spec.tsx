import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import { blankRule } from '../lib/rules';
import DestinationDialog from './DestinationDialog';
import RuleEditor from './RuleEditor';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof api>()),
    lookup: vi.fn().mockResolvedValue([]),
    testDestinationInput: vi.fn().mockResolvedValue({ ok: true, message: 'Connected in 120 ms.' }),
}));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

function renderWithHost(element: React.ReactElement) {
    host = createExtensionTestHost({ extensionId: 'offsite-backups', prefix: 'ofs' });
    render(element, { wrapper: host.Wrapper });
}

const destination = (changes: Partial<api.Destination>): api.Destination => ({
    id: 1,
    name: 'Hetzner S3',
    kind: 's3',
    format: 'dedup',
    encrypted: true,
    is_default: true,
    status: 'ok',
    status_message: null,
    checked_at: null,
    copies: 0,
    stored_bytes: 0,
    config: { endpoint: 'https://fsn1.your-objectstorage.com', region: 'fsn1', bucket: 'backups', prefix: 'panel/', path_style: false, storage_class: null, key_set: true, secret_set: true },
    ...changes,
});

const rule = (changes: Partial<api.Rule>): api.Rule => ({ ...blankRule(), id: 4, sort: 0, targets: [], excluded: [], destinations: [], matches: 12, last_run_at: null, next_run_at: null, ...changes });

test('the rule editor asks for a cron expression and a destination before saving', async () => {
    const onSave = vi.fn();
    renderWithHost(<RuleEditor rule={rule({ name: 'Nightly', trigger: 'schedule', cron: '' })} destinations={[destination({}), destination({ id: 2, name: 'Storage box', kind: 'sftp' })]} pending={false} onCancel={vi.fn()} onSave={onSave} />);

    expect(await screen.findByText('Matches 12 servers right now.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save rule' }));

    expect(await screen.findByText('Enter when to create backups, as a cron expression.')).toBeTruthy();
    expect(screen.getByText('Pick at least one destination.')).toBeTruthy();
    expect(onSave).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Daily 04:00' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Storage box' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Hetzner S3' }));
    expect(screen.getByText('Primary')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save rule' }));

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
    expect(onSave.mock.calls[0][0]).toMatchObject({ name: 'Nightly', trigger: 'schedule', cron: '0 4 * * *', destination_ids: [2, 1], target_ids: [] });
    expect(screen.queryByText('Pick at least one destination.')).toBeNull();
});

test('the rule editor checks retention numbers', async () => {
    const onSave = vi.fn();
    renderWithHost(<RuleEditor rule={null} destinations={[destination({})]} pending={false} onCancel={vi.fn()} onSave={onSave} />);

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Every backup' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Hetzner S3' }));
    fireEvent.change(screen.getByLabelText('Keep the newest'), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create rule' }));

    expect(await screen.findByText('Use a whole number from 1 to 1000, or leave it empty.')).toBeTruthy();
    expect(onSave).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Keep the newest'), { target: { value: '14' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create rule' }));
    await waitFor(() => expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ name: 'Every backup', trigger: 'backup', cron: null, keep_newest: 14, destination_ids: [1] })));
});

test('the destination dialog never shows secrets and sends empty ones to keep them', async () => {
    const onSave = vi.fn();
    renderWithHost(<DestinationDialog destination={destination({ copies: 3 })} pending={false} onClose={vi.fn()} onSave={onSave} />);

    const key = (await screen.findByLabelText('Access key')) as HTMLInputElement;
    const secret = screen.getByLabelText('Secret key') as HTMLInputElement;
    expect(key.type).toBe('password');
    expect(key.value).toBe('');
    expect(secret.value).toBe('');
    expect(screen.getAllByText('Stored. Leave empty to keep it.').length).toBe(3);
    expect((screen.getByLabelText('Endpoint') as HTMLInputElement).value).toBe('https://fsn1.your-objectstorage.com');

    fireEvent.click(screen.getByRole('button', { name: 'Test connection' }));
    await waitFor(() => expect(api.testDestinationInput).toHaveBeenCalledWith(expect.objectContaining({ config: expect.objectContaining({ key: '', secret: '' }) }), 1));
    expect(await screen.findByText('Connected in 120 ms.')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Bucket'), { target: { value: 'backups-2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(onSave).toHaveBeenCalledWith({
        name: 'Hetzner S3',
        kind: 's3',
        format: 'dedup',
        encrypted: true,
        config: { endpoint: 'https://fsn1.your-objectstorage.com', region: 'fsn1', bucket: 'backups-2', prefix: 'panel/', path_style: false, key: '', secret: '' },
    });
});

test('a new destination needs its secrets', async () => {
    renderWithHost(<DestinationDialog destination={null} pending={false} onClose={vi.fn()} onSave={vi.fn()} />);

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Backblaze' } });
    fireEvent.change(screen.getByLabelText('Endpoint'), { target: { value: 'https://s3.eu-central-003.backblazeb2.com' } });
    fireEvent.change(screen.getByLabelText('Bucket'), { target: { value: 'panel' } });
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Access key'), { target: { value: 'id' } });
    fireEvent.change(screen.getByLabelText('Secret key'), { target: { value: 'secret' } });
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(false);
});
