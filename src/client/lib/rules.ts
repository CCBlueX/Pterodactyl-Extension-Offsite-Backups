import type { Rule, RuleInput } from '../types';

export const CRON_PRESETS = [
    { label: 'Daily 04:00', cron: '0 4 * * *' },
    { label: 'Every 12 hours', cron: '0 */12 * * *' },
    { label: 'Every 6 hours', cron: '0 */6 * * *' },
    { label: 'Hourly', cron: '0 * * * *' },
    { label: 'Sundays 03:00', cron: '0 3 * * 0' },
] as const;

export const blankRule = (): RuleInput => ({
    name: '',
    enabled: true,
    target_type: 'all',
    target_ids: [],
    excluded_server_ids: [],
    trigger: 'backup',
    cron: null,
    skip_idle: false,
    when_full: 'skip',
    destination_ids: [],
    retention: 'newest',
    keep_newest: 7,
    keep_daily: null,
    keep_weekly: null,
    keep_monthly: null,
    max_age_days: null,
    keep_local: null,
    include_databases: false,
});

export function ruleInput(rule: Rule): RuleInput {
    const blank = blankRule();

    return Object.fromEntries(Object.keys(blank).map((key) => [key, rule[key as keyof RuleInput]])) as unknown as RuleInput;
}

export type RuleErrors = Partial<Record<keyof RuleInput, string>>;

const COUNTS = ['keep_newest', 'keep_daily', 'keep_weekly', 'keep_monthly', 'max_age_days', 'keep_local'] as const;

/** The same checks the backend runs, so the editor can point at the field before saving. */
export function ruleErrors(input: RuleInput): RuleErrors {
    const errors: RuleErrors = {};

    if (input.name.trim() === '') {
        errors.name = 'Give the rule a name.';
    }

    if (input.target_type !== 'all' && input.target_ids.length === 0) {
        errors.target_ids = `Pick at least one ${input.target_type === 'servers' ? 'server' : input.target_type === 'nodes' ? 'node' : 'egg'}.`;
    }

    if (input.trigger === 'schedule') {
        const cron = (input.cron ?? '').trim();

        if (cron === '') {
            errors.cron = 'Enter when to create backups, as a cron expression.';
        } else if (cron.split(/\s+/).length !== 5) {
            errors.cron = 'A cron expression has five parts: minute, hour, day, month and weekday.';
        }
    }

    if (input.destination_ids.length === 0) {
        errors.destination_ids = 'Pick at least one destination.';
    }

    for (const key of COUNTS) {
        const value = input[key];

        if (value !== null && (!Number.isInteger(value) || value < 1 || value > 1000)) {
            errors[key] = 'Use a whole number from 1 to 1000, or leave it empty.';
        }
    }

    if (input.retention === 'gfs' && input.keep_daily === null && input.keep_weekly === null && input.keep_monthly === null) {
        errors.keep_daily = 'Keep at least one daily, weekly or monthly copy.';
    }

    return errors;
}

export function triggerSummary(rule: Pick<RuleInput, 'trigger' | 'cron'>): string {
    if (rule.trigger === 'backup') {
        return 'Copies every backup when it finishes';
    }

    const preset = CRON_PRESETS.find((item) => item.cron === rule.cron);

    return `Creates backups ${preset ? preset.label.toLowerCase() : `on "${rule.cron}"`}`;
}

export function retentionSummary(rule: Pick<RuleInput, 'retention' | 'keep_newest' | 'keep_daily' | 'keep_weekly' | 'keep_monthly'>): string {
    if (rule.retention === 'newest') {
        return rule.keep_newest ? `Keeps the newest ${rule.keep_newest}` : 'Keeps every copy';
    }

    const parts = [rule.keep_daily && `${rule.keep_daily} daily`, rule.keep_weekly && `${rule.keep_weekly} weekly`, rule.keep_monthly && `${rule.keep_monthly} monthly`].filter(Boolean);

    return `Keeps ${parts.join(', ')}`;
}
