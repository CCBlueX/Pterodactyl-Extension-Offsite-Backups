import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Dialog, Input, Select, Switch, TextArea, httpErrorToHuman } from '@pterodactyl/sdk';
import { testDestinationInput } from '../api';
import type { Destination, DestinationFormat, DestinationInput, DestinationKind, S3Config, S3ConfigInput, SftpConfig, SftpConfigInput, TestResult } from '../types';
import Field from './Field';

interface Props {
    destination: Destination | null;
    pending: boolean;
    onClose: () => void;
    onSave: (input: DestinationInput) => void;
}

const FORMAT_OPTIONS = [
    { value: 'archive', label: 'Archive (.tar.gz per backup)' },
    { value: 'dedup', label: 'Deduplicated (only changes are stored)' },
];

const STORED = 'Stored. Leave empty to keep it.';

function initialS3(destination: Destination | null): S3ConfigInput {
    const config = destination?.kind === 's3' ? (destination.config as S3Config) : null;

    return { endpoint: config?.endpoint ?? '', region: config?.region ?? 'us-east-1', bucket: config?.bucket ?? '', prefix: config?.prefix ?? '', path_style: config?.path_style ?? false, key: '', secret: '' };
}

function initialSftp(destination: Destination | null): SftpConfigInput {
    const config = destination?.kind === 'sftp' ? (destination.config as SftpConfig) : null;

    return { host: config?.host ?? '', port: config?.port ?? 22, username: config?.username ?? '', path: config?.path ?? '', password: '', private_key: '' };
}

/** Adds or edits a destination. Secrets are never shown; empty secret fields keep what is stored. */
export default function DestinationDialog({ destination, pending, onClose, onSave }: Props) {
    const [name, setName] = useState(destination?.name ?? '');
    const [kind, setKind] = useState<DestinationKind>(destination?.kind ?? 's3');
    const [format, setFormat] = useState<DestinationFormat>(destination?.format ?? 'dedup');
    const [encrypted, setEncrypted] = useState(destination?.encrypted ?? false);
    const [passphrase, setPassphrase] = useState('');
    const [s3, setS3] = useState(() => initialS3(destination));
    const [sftp, setSftp] = useState(() => initialSftp(destination));
    const [result, setResult] = useState<TestResult | null>(null);
    const locked = (destination?.copies ?? 0) > 0;
    const s3Stored = destination?.kind === 's3' ? (destination.config as S3Config) : null;
    const sftpStored = destination?.kind === 'sftp' ? (destination.config as SftpConfig) : null;
    const setS3Field = (changes: Partial<S3ConfigInput>) => setS3((current) => ({ ...current, ...changes }));
    const setSftpField = (changes: Partial<SftpConfigInput>) => setSftp((current) => ({ ...current, ...changes }));

    const input = (): DestinationInput => ({
        name: name.trim(),
        kind,
        format,
        encrypted,
        ...(encrypted && passphrase !== '' ? { passphrase } : {}),
        config: kind === 's3' ? s3 : sftp,
    });

    const needsPassphrase = encrypted && !destination?.encrypted && passphrase === '';
    const valid =
        name.trim() !== '' &&
        !needsPassphrase &&
        (kind === 's3'
            ? s3.endpoint.trim() !== '' && s3.bucket.trim() !== '' && (s3.key !== '' || !!s3Stored?.key_set) && (s3.secret !== '' || !!s3Stored?.secret_set)
            : sftp.host.trim() !== '' && sftp.username.trim() !== '' && (sftp.password !== '' || sftp.private_key !== '' || !!sftpStored?.password_set || !!sftpStored?.private_key_set));

    const test = useMutation({
        mutationFn: () => testDestinationInput(input(), destination?.id),
        onMutate: () => setResult(null),
        onSuccess: setResult,
        onError: (error) => setResult({ ok: false, message: httpErrorToHuman(error) }),
    });

    return (
        <Dialog open onClose={onClose} title={destination ? `Edit ${destination.name}` : 'Add a destination'} preventExternalClose={pending}>
            <div className='ofs:grid ofs:gap-4'>
                <div className='ofs:grid ofs:gap-4 ofs:sm:grid-cols-[minmax(0,1fr)_10rem]'>
                    <Field id='ofs-destination-name' label='Name'>
                        <Input id='ofs-destination-name' value={name} maxLength={64} onChange={(event) => setName(event.currentTarget.value)} />
                    </Field>
                    <Field id='ofs-destination-kind' label='Type'>
                        <Select
                            id='ofs-destination-kind'
                            value={kind}
                            disabled={locked}
                            onChange={(value) => setKind(value as DestinationKind)}
                            options={[
                                { value: 's3', label: 'S3' },
                                { value: 'sftp', label: 'SFTP' },
                            ]}
                        />
                    </Field>
                </div>

                {kind === 's3' ? (
                    <div className='ofs:grid ofs:gap-4 ofs:sm:grid-cols-2'>
                        <div className='ofs:sm:col-span-2'>
                            <Field id='ofs-s3-endpoint' label='Endpoint' help='The S3 API address, such as https://s3.eu-central-1.amazonaws.com or your MinIO, R2 or Wasabi URL.'>
                                <Input id='ofs-s3-endpoint' type='url' placeholder='https://' value={s3.endpoint} onChange={(event) => setS3Field({ endpoint: event.currentTarget.value })} />
                            </Field>
                        </div>
                        <Field id='ofs-s3-region' label='Region'>
                            <Input id='ofs-s3-region' value={s3.region} onChange={(event) => setS3Field({ region: event.currentTarget.value })} />
                        </Field>
                        <Field id='ofs-s3-bucket' label='Bucket'>
                            <Input id='ofs-s3-bucket' value={s3.bucket} onChange={(event) => setS3Field({ bucket: event.currentTarget.value })} />
                        </Field>
                        <Field id='ofs-s3-prefix' label='Prefix' help='A folder inside the bucket. Empty for the bucket root.'>
                            <Input id='ofs-s3-prefix' placeholder='pterodactyl/' value={s3.prefix} onChange={(event) => setS3Field({ prefix: event.currentTarget.value })} />
                        </Field>
                        <div className='ofs:self-center'>
                            <Switch id='ofs-s3-path-style' checked={s3.path_style} onChange={(checked) => setS3Field({ path_style: checked })} label='Path-style addressing' description='Needed by MinIO and most self-hosted S3 servers.' />
                        </div>
                        <Field id='ofs-s3-key' label='Access key' help={s3Stored?.key_set ? STORED : undefined}>
                            <Input id='ofs-s3-key' type='password' autoComplete='off' placeholder={s3Stored?.key_set ? '••••••••' : ''} value={s3.key} onChange={(event) => setS3Field({ key: event.currentTarget.value })} />
                        </Field>
                        <Field id='ofs-s3-secret' label='Secret key' help={s3Stored?.secret_set ? STORED : undefined}>
                            <Input id='ofs-s3-secret' type='password' autoComplete='off' placeholder={s3Stored?.secret_set ? '••••••••' : ''} value={s3.secret} onChange={(event) => setS3Field({ secret: event.currentTarget.value })} />
                        </Field>
                    </div>
                ) : (
                    <div className='ofs:grid ofs:gap-4 ofs:sm:grid-cols-[minmax(0,1fr)_7rem]'>
                        <Field id='ofs-sftp-host' label='Host'>
                            <Input id='ofs-sftp-host' placeholder='backup.example.com' value={sftp.host} onChange={(event) => setSftpField({ host: event.currentTarget.value })} />
                        </Field>
                        <Field id='ofs-sftp-port' label='Port'>
                            <Input id='ofs-sftp-port' type='number' min={1} max={65535} value={String(sftp.port)} onChange={(event) => setSftpField({ port: Number(event.currentTarget.value) })} />
                        </Field>
                        <Field id='ofs-sftp-username' label='Username'>
                            <Input id='ofs-sftp-username' value={sftp.username} onChange={(event) => setSftpField({ username: event.currentTarget.value })} />
                        </Field>
                        <div />
                        <div className='ofs:sm:col-span-2'>
                            <Field id='ofs-sftp-path' label='Path' help='The folder copies go to. Empty for the home folder.'>
                                <Input id='ofs-sftp-path' placeholder='backups/pterodactyl' value={sftp.path} onChange={(event) => setSftpField({ path: event.currentTarget.value })} />
                            </Field>
                        </div>
                        <div className='ofs:sm:col-span-2'>
                            <Field id='ofs-sftp-password' label='Password' help={sftpStored?.password_set ? STORED : 'Or use a private key below.'}>
                                <Input id='ofs-sftp-password' type='password' autoComplete='off' placeholder={sftpStored?.password_set ? '••••••••' : ''} value={sftp.password} onChange={(event) => setSftpField({ password: event.currentTarget.value })} />
                            </Field>
                        </div>
                        <div className='ofs:sm:col-span-2'>
                            <Field id='ofs-sftp-key' label='Private key' help={sftpStored?.private_key_set ? STORED : 'OpenSSH or PEM format.'}>
                                <TextArea
                                    id='ofs-sftp-key'
                                    rows={3}
                                    spellCheck={false}
                                    className='ofs:font-mono ofs:text-xs'
                                    placeholder={sftpStored?.private_key_set ? 'Stored' : '-----BEGIN OPENSSH PRIVATE KEY-----'}
                                    value={sftp.private_key}
                                    onChange={(event) => setSftpField({ private_key: event.currentTarget.value })}
                                />
                            </Field>
                        </div>
                    </div>
                )}

                <Field id='ofs-destination-format' label='Format' help={locked ? 'Cannot change while the destination holds copies.' : format === 'dedup' ? 'Splits backups into pieces and stores each piece once. Later backups only add what changed.' : 'Stores each backup as it is. Simple to read without the panel.'}>
                    <Select id='ofs-destination-format' value={format} disabled={locked} onChange={(value) => setFormat(value as DestinationFormat)} options={FORMAT_OPTIONS} />
                </Field>
                <Switch
                    id='ofs-destination-encrypted'
                    checked={encrypted}
                    disabled={locked}
                    onChange={setEncrypted}
                    label='Encrypt copies'
                    description='Copies are encrypted before they leave the panel. Without the passphrase they cannot be read, not even by you.'
                />
                {encrypted && (
                    <Field id='ofs-destination-passphrase' label='Passphrase' help={destination?.encrypted ? STORED : 'Write it down somewhere safe. It cannot be recovered.'}>
                        <Input
                            id='ofs-destination-passphrase'
                            type='password'
                            autoComplete='new-password'
                            disabled={locked}
                            placeholder={destination?.encrypted ? '••••••••' : ''}
                            value={passphrase}
                            onChange={(event) => setPassphrase(event.currentTarget.value)}
                        />
                    </Field>
                )}

                {result && (
                    <Alert type={result.ok ? 'success' : 'danger'}>
                        {result.ok ? result.message || 'Connected. A test file was written, read and deleted.' : result.message}
                    </Alert>
                )}
            </div>
            <Dialog.Footer>
                <Button.Text className='ofs:w-full ofs:sm:w-auto' disabled={pending || !valid} isLoading={test.isPending} onClick={() => test.mutate()}>
                    Test connection
                </Button.Text>
                <Button.Text className='ofs:w-full ofs:sm:w-auto' disabled={pending} onClick={onClose}>
                    Cancel
                </Button.Text>
                <Button className='ofs:w-full ofs:sm:w-auto' disabled={!valid} isLoading={pending} onClick={() => onSave(input())}>
                    Save
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
