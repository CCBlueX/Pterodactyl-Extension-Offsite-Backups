import { useState } from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Badge, Button, Checkbox, Input, RowActionButton, Select, Switch, TitledGreyBox } from '@pterodactyl/sdk';
import type { Destination, Named, Rule, RuleInput, TargetType } from '../types';
import { CRON_PRESETS, blankRule, ruleErrors, ruleInput } from '../lib/rules';
import { plural } from '../lib/format';
import Field, { numberOrNull } from './Field';
import LookupSelect from './LookupSelect';

interface Props {
    rule: Rule | null;
    destinations: Destination[];
    pending: boolean;
    onCancel: () => void;
    onSave: (input: RuleInput) => void;
}

const TARGETS = [
    { value: 'all', label: 'All servers' },
    { value: 'nodes', label: 'Servers on these nodes' },
    { value: 'eggs', label: 'Servers of these eggs' },
    { value: 'servers', label: 'Only these servers' },
];

const TARGET_WORD: Record<Exclude<TargetType, 'all'>, string> = { nodes: 'nodes', eggs: 'eggs', servers: 'servers' };

const numberField = (value: number | null) => (value === null ? '' : String(value));

/** The rule form, in the order a rule is read: which servers, where, what, and how long. */
export default function RuleEditor({ rule, destinations, pending, onCancel, onSave }: Props) {
    const [input, setInput] = useState<RuleInput>(() => (rule ? ruleInput(rule) : blankRule()));
    const [targets, setTargets] = useState<Named[]>(rule?.targets ?? []);
    const [excluded, setExcluded] = useState<Named[]>(rule?.excluded ?? []);
    const [submitted, setSubmitted] = useState(false);
    const set = (changes: Partial<RuleInput>) => setInput((current) => ({ ...current, ...changes }));
    const complete: RuleInput = { ...input, target_ids: input.target_type === 'all' ? [] : targets.map((item) => item.id), excluded_server_ids: excluded.map((item) => item.id) };
    const errors = submitted ? ruleErrors(complete) : {};
    const picked = input.destination_ids.map((id) => destinations.find((item) => item.id === id)).filter((item): item is Destination => item !== undefined);
    const others = destinations.filter((item) => !input.destination_ids.includes(item.id));

    const move = (id: number, by: -1 | 1) => {
        const list = [...input.destination_ids];
        const index = list.indexOf(id);
        [list[index], list[index + by]] = [list[index + by], list[index]];
        set({ destination_ids: list });
    };

    const save = () => {
        setSubmitted(true);

        if (Object.keys(ruleErrors(complete)).length === 0) {
            onSave({ ...complete, name: complete.name.trim(), cron: complete.trigger === 'schedule' ? (complete.cron ?? '').trim() : null });
        }
    };

    return (
        <div className='ofs:grid ofs:gap-6'>
            <div className='ofs:grid ofs:items-start ofs:gap-6 ofs:lg:grid-cols-2'>
                <TitledGreyBox title='1. Which servers'>
                    <div className='ofs:grid ofs:gap-4'>
                        <Field id='ofs-rule-name' label='Name' error={errors.name}>
                            <Input id='ofs-rule-name' value={input.name} maxLength={64} placeholder='Nightly to S3' onChange={(event) => set({ name: event.currentTarget.value })} />
                        </Field>
                        <Field id='ofs-rule-target' label='Servers' help='Pick by node or egg to include servers created later automatically.' error={errors.target_ids}>
                            <Select id='ofs-rule-target' value={input.target_type} onChange={(value) => (set({ target_type: value as TargetType }), setTargets([]))} options={TARGETS} />
                            {input.target_type !== 'all' && (
                                <div className='ofs:mt-2'>
                                    <LookupSelect id='ofs-rule-targets' type={input.target_type} value={targets} onChange={setTargets} placeholder={`Pick ${TARGET_WORD[input.target_type]}`} />
                                </div>
                            )}
                        </Field>
                        <Field id='ofs-rule-excluded' label='Except these servers' help='Matching servers that this rule leaves alone.'>
                            <LookupSelect id='ofs-rule-excluded' type='servers' value={excluded} onChange={setExcluded} placeholder='No exceptions' />
                        </Field>
                        <p className='ofs:text-sm ofs:text-muted-foreground'>{rule ? `Matches ${plural(rule.matches, 'server')} right now.` : 'The number of matching servers shows once the rule is saved.'}</p>
                        <Switch id='ofs-rule-enabled' checked={input.enabled} onChange={(checked) => set({ enabled: checked })} label='Rule is active' description='Paused rules keep their copies but start no new ones.' />
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title='2. Where to store'>
                    <div className='ofs:grid ofs:gap-3'>
                        <p className='ofs:text-sm ofs:text-muted-foreground'>Every backup is copied to each checked destination. The first one is the primary copy.</p>
                        {destinations.length === 0 && <p className='ofs:text-sm ofs:text-foreground'>Add a destination first.</p>}
                        <ul className='ofs:grid ofs:gap-2' aria-label='Destinations'>
                            {picked.map((destination, index) => (
                                <li key={destination.id} className='ofs:flex ofs:items-center ofs:gap-3 ofs:rounded-sm ofs:bg-sunken ofs:px-3 ofs:py-2 ofs:text-sm'>
                                    <Checkbox aria-label={destination.name} checked onChange={() => set({ destination_ids: input.destination_ids.filter((id) => id !== destination.id) })} />
                                    <span className='ofs:min-w-0 ofs:flex-1 ofs:truncate'>{destination.name}</span>
                                    <Badge variant='soft' tone={index === 0 ? 'accent' : 'neutral'} size='sm'>
                                        {index === 0 ? 'Primary' : `Copy ${index + 1}`}
                                    </Badge>
                                    <RowActionButton aria-label={`Move ${destination.name} up`} icon={ArrowUp} label='Move up' disabled={index === 0} onClick={() => move(destination.id, -1)} />
                                    <RowActionButton aria-label={`Move ${destination.name} down`} icon={ArrowDown} label='Move down' disabled={index === picked.length - 1} onClick={() => move(destination.id, 1)} />
                                </li>
                            ))}
                            {others.map((destination) => (
                                <li key={destination.id} className='ofs:flex ofs:items-center ofs:gap-3 ofs:px-3 ofs:py-2 ofs:text-sm'>
                                    <Checkbox aria-label={destination.name} checked={false} onChange={() => set({ destination_ids: [...input.destination_ids, destination.id] })} />
                                    <span className='ofs:min-w-0 ofs:flex-1 ofs:truncate ofs:text-muted-foreground'>{destination.name}</span>
                                    <span className='ofs:text-xs ofs:text-muted-foreground'>{destination.kind.toUpperCase()}</span>
                                </li>
                            ))}
                        </ul>
                        {errors.destination_ids && <p className='ofs:text-xs ofs:text-destructive'>{errors.destination_ids}</p>}
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title='3. What to do'>
                    <div className='ofs:grid ofs:gap-4'>
                        <Field id='ofs-rule-trigger' label='When' help={input.trigger === 'backup' ? 'Copies backups users, schedules and the panel make, as soon as each one finishes.' : 'Creates a backup of every matching server at these times, then copies it.'}>
                            <Select
                                id='ofs-rule-trigger'
                                value={input.trigger}
                                onChange={(value) => set({ trigger: value as RuleInput['trigger'], cron: value === 'schedule' ? (input.cron ?? CRON_PRESETS[0].cron) : input.cron })}
                                options={[
                                    { value: 'backup', label: 'Copy every backup when it finishes' },
                                    { value: 'schedule', label: 'Create backups on a schedule' },
                                ]}
                            />
                        </Field>
                        {input.trigger === 'schedule' && (
                            <>
                                <Field id='ofs-rule-cron' label='Schedule (cron)' help='Minute, hour, day, month and weekday in the panel time zone.' error={errors.cron}>
                                    <Input id='ofs-rule-cron' className='ofs:font-mono' placeholder='0 4 * * *' value={input.cron ?? ''} onChange={(event) => set({ cron: event.currentTarget.value })} />
                                    <div className='ofs:mt-1 ofs:flex ofs:flex-wrap ofs:gap-1'>
                                        {CRON_PRESETS.map((preset) => (
                                            <Button.Text key={preset.cron} size='xsmall' onClick={() => set({ cron: preset.cron })}>
                                                {preset.label}
                                            </Button.Text>
                                        ))}
                                    </div>
                                </Field>
                                <Field id='ofs-rule-when-full' label='When the backup limit is full' help='A server at its backup limit cannot take another backup.'>
                                    <Select
                                        id='ofs-rule-when-full'
                                        value={input.when_full}
                                        onChange={(value) => set({ when_full: value as RuleInput['when_full'] })}
                                        options={[
                                            { value: 'skip', label: 'Skip the server' },
                                            { value: 'rotate', label: 'Delete its oldest unlocked backup' },
                                        ]}
                                    />
                                </Field>
                                <Switch id='ofs-rule-skip-idle' checked={input.skip_idle} onChange={(checked) => set({ skip_idle: checked })} label='Skip idle servers' description='No new backup for a server that is off and unchanged since its last backup.' />
                            </>
                        )}
                        <Switch id='ofs-rule-databases' checked={input.include_databases} onChange={(checked) => set({ include_databases: checked })} label='Include databases' description="Stores a dump of each of the server's databases next to the backup." />
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title='4. Keep & clean up'>
                    <div className='ofs:grid ofs:gap-4'>
                        <Field id='ofs-rule-retention' label='Copies to keep' help={input.retention === 'newest' ? 'Older copies are deleted after each new one. Empty keeps them all.' : 'The newest copy of each day, week and month, counted back from the newest.'}>
                            <Select
                                id='ofs-rule-retention'
                                value={input.retention}
                                onChange={(value) => set({ retention: value as RuleInput['retention'] })}
                                options={[
                                    { value: 'newest', label: 'The newest copies' },
                                    { value: 'gfs', label: 'Daily, weekly and monthly copies' },
                                ]}
                            />
                        </Field>
                        {input.retention === 'newest' ? (
                            <Field id='ofs-rule-keep-newest' label='Keep the newest' help='Copies per server and destination.' error={errors.keep_newest}>
                                <Input id='ofs-rule-keep-newest' type='number' min={1} max={1000} placeholder='All' value={numberField(input.keep_newest)} onChange={(event) => set({ keep_newest: numberOrNull(event.currentTarget.value) })} />
                            </Field>
                        ) : (
                            <div className='ofs:grid ofs:gap-1'>
                                <div className='ofs:grid ofs:grid-cols-3 ofs:gap-3'>
                                    {(['keep_daily', 'keep_weekly', 'keep_monthly'] as const).map((key) => (
                                        <Field key={key} id={`ofs-rule-${key}`} label={key === 'keep_daily' ? 'Daily' : key === 'keep_weekly' ? 'Weekly' : 'Monthly'}>
                                            <Input id={`ofs-rule-${key}`} type='number' min={1} max={1000} placeholder='0' value={numberField(input[key])} onChange={(event) => set({ [key]: numberOrNull(event.currentTarget.value) })} />
                                        </Field>
                                    ))}
                                </div>
                                {errors.keep_daily || errors.keep_weekly || errors.keep_monthly ? (
                                    <p className='ofs:text-xs ofs:text-destructive'>{errors.keep_daily ?? errors.keep_weekly ?? errors.keep_monthly}</p>
                                ) : (
                                    <p className='ofs:text-xs ofs:text-muted-foreground'>7 daily, 4 weekly and 6 monthly keeps half a year of history in 17 copies.</p>
                                )}
                            </div>
                        )}
                        <Field id='ofs-rule-max-age' label='Delete copies older than (days)' help='Applies on top of the above. Empty for no age limit.' error={errors.max_age_days}>
                            <Input id='ofs-rule-max-age' type='number' min={1} max={1000} placeholder='No limit' value={numberField(input.max_age_days)} onChange={(event) => set({ max_age_days: numberOrNull(event.currentTarget.value) })} />
                        </Field>
                        <Field id='ofs-rule-keep-local' label='Keep only this many backups on the node' help='Deletes older unlocked backups on the node once they are safely off-site. Empty leaves them alone.' error={errors.keep_local}>
                            <Input id='ofs-rule-keep-local' type='number' min={1} max={1000} placeholder='All' value={numberField(input.keep_local)} onChange={(event) => set({ keep_local: numberOrNull(event.currentTarget.value) })} />
                        </Field>
                    </div>
                </TitledGreyBox>
            </div>
            <div className='ofs:flex ofs:flex-col-reverse ofs:gap-2 ofs:sm:flex-row ofs:sm:justify-end'>
                <Button.Text disabled={pending} onClick={onCancel}>
                    Cancel
                </Button.Text>
                <Button isLoading={pending} onClick={save}>
                    {rule ? 'Save rule' : 'Create rule'}
                </Button>
            </div>
        </div>
    );
}
