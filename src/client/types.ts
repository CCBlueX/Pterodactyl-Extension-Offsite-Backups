export type DestinationKind = 's3' | 'sftp';
export type DestinationFormat = 'archive' | 'dedup';
export type DestinationStatus = 'ok' | 'error' | 'paused';
export type CopyStatus = 'queued' | 'running' | 'verified' | 'failed';
export type CopyKind = 'backup' | 'database';
export type StorageChoice = 'default' | 'node' | 's3';
export type TargetType = 'all' | 'nodes' | 'eggs' | 'servers';
export type LookupType = 'nodes' | 'eggs' | 'servers';

export interface Named {
    id: number;
    name: string;
}

export interface S3Config {
    endpoint: string;
    region: string;
    bucket: string;
    prefix: string;
    path_style: boolean;
    storage_class: string | null;
    key_set: boolean;
    secret_set: boolean;
}

export interface SftpConfig {
    host: string;
    port: number;
    username: string;
    path: string;
    password_set: boolean;
    private_key_set: boolean;
}

export interface Destination {
    id: number;
    name: string;
    kind: DestinationKind;
    format: DestinationFormat;
    encrypted: boolean;
    is_default: boolean;
    status: DestinationStatus;
    status_message: string | null;
    checked_at: string | null;
    copies: number;
    stored_bytes: number;
    config: S3Config | SftpConfig;
}

export interface S3ConfigInput {
    endpoint: string;
    region: string;
    bucket: string;
    prefix: string;
    path_style: boolean;
    key: string;
    secret: string;
}

export interface SftpConfigInput {
    host: string;
    port: number;
    username: string;
    path: string;
    password: string;
    private_key: string;
}

export interface DestinationInput {
    name: string;
    kind: DestinationKind;
    format: DestinationFormat;
    encrypted: boolean;
    passphrase?: string;
    config: S3ConfigInput | SftpConfigInput;
}

export interface TestResult {
    ok: boolean;
    message: string;
}

export interface Progress {
    done: number;
    total: number;
}

export interface Copy {
    id: number;
    uuid: string;
    server: { id: number | null; uuid: string; name: string };
    backup: { uuid: string | null; name: string; local: boolean };
    kind: CopyKind;
    database: string | null;
    destination: { id: number; name: string; kind: DestinationKind; format: DestinationFormat };
    rule: Named | null;
    status: CopyStatus;
    attempts: number;
    progress: Progress;
    source_bytes: number | null;
    stored_bytes: number | null;
    checksum: string | null;
    verified_at: string | null;
    checked_at: string | null;
    error: string | null;
    details: Record<string, unknown> | null;
    created_at: string;
    finished_at: string | null;
    next_attempt_at: string | null;
}

export interface CopyPage {
    data: Copy[];
    meta: { page: number; per_page: number; total: number };
}

export interface CopyFilters {
    search: string;
    status: CopyStatus | '';
    destination: number | '';
    server: number | '';
    page: number;
}

export interface BulkResult {
    done: number;
    failed: number;
}

export interface Overview {
    totals: Record<CopyStatus, number>;
    stored_bytes: number;
    copies_24h: number;
    failed_24h: number;
    worker: { mode: 'scheduler' | 'dedicated'; seen_at: string | null };
    destinations: Pick<Destination, 'id' | 'name' | 'kind' | 'format' | 'encrypted' | 'status' | 'copies' | 'stored_bytes'>[];
    recent: Copy[];
}

export interface StorageOverride {
    server_id: number;
    server_name: string;
    storage: StorageChoice;
    destination: string | null;
}

export interface Storage {
    panel_driver: string;
    destination_id: number | null;
    overrides: StorageOverride[];
}

export type RetentionMode = 'newest' | 'gfs';

export interface RuleInput {
    name: string;
    enabled: boolean;
    target_type: TargetType;
    target_ids: number[];
    excluded_server_ids: number[];
    trigger: 'backup' | 'schedule';
    cron: string | null;
    skip_idle: boolean;
    when_full: 'skip' | 'rotate';
    destination_ids: number[];
    retention: RetentionMode;
    keep_newest: number | null;
    keep_daily: number | null;
    keep_weekly: number | null;
    keep_monthly: number | null;
    max_age_days: number | null;
    keep_local: number | null;
    include_databases: boolean;
}

export interface Rule extends RuleInput {
    id: number;
    sort: number;
    targets: Named[];
    excluded: Named[];
    destinations: Named[];
    matches: number;
    last_run_at: string | null;
    next_run_at: string | null;
}

export interface Usage {
    copies: number;
    stored_bytes: number;
    failed: number;
    last_copy_at: string | null;
    destinations: string[];
    protected: boolean;
    policy: string | null;
}

export interface Allowance {
    enabled: boolean | null;
    max_copies: number | null;
    max_storage_gb: number | null;
    storage: StorageChoice;
    storage_destination_id: number | null;
}

export interface ServerDetails {
    server: { id: number; uuid: string; name: string };
    usage: Usage;
    allowance: Allowance;
    defaults: { enabled: boolean; storage_destination: string | null };
    rules: Named[];
    destinations: { id: number; name: string; kind: DestinationKind }[];
    copies: Copy[];
}

export interface ClientCopy {
    uuid: string;
    backup_name: string;
    backup_uuid: string | null;
    kind: CopyKind;
    local: boolean;
    status: 'copying' | 'off-site';
    progress: Progress;
    bytes: number | null;
    created_at: string;
    destination: string;
}

export interface ClientOverview {
    protected: boolean;
    destinations: string[];
    stored_bytes: number;
    count: number;
    copies: ClientCopy[];
}
