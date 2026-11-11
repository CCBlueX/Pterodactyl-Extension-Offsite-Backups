import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Play } from 'lucide-react';
import { Badge, Button, DeleteAction, Dialog, EditAction, RowActionButton, RowActions, ServerError, Spinner, Table, actionsColumn, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { deleteRule, getDestinations, getRules, keys, runRule, saveRule } from '../../api';
import type { Rule, RuleInput } from '../../types';
import EmptyState from '../../components/EmptyState';
import RuleEditor from '../../components/RuleEditor';
import { formatAgo, plural } from '../../lib/format';
import { retentionSummary, triggerSummary } from '../../lib/rules';

const TARGET_LABEL = { nodes: 'Nodes', eggs: 'Eggs', servers: 'Servers' };

const targetSummary = (rule: Rule) => (rule.target_type === 'all' ? 'All servers' : `${TARGET_LABEL[rule.target_type]}: ${rule.targets.map((target) => target.name).join(', ')}`);

export default function RulesTab() {
    const queryClient = useQueryClient();
    const rules = useQuery({ queryKey: keys.rules, queryFn: getRules });
    const destinations = useQuery({ queryKey: keys.destinations, queryFn: getDestinations });
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [removing, setRemoving] = useState<Rule | null>(null);
    const refresh = () => queryClient.invalidateQueries({ queryKey: keys.all });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const saving = useMutation({
        mutationFn: (input: RuleInput) => saveRule(input, editing && editing !== 'new' ? editing.id : undefined),
        onSuccess: () => (toast.success('Rule saved.'), setEditing(null), refresh()),
        onError,
    });
    const removal = useMutation({ mutationFn: (rule: Rule) => deleteRule(rule.id), onSuccess: () => (setRemoving(null), refresh()), onError });
    const running = useMutation({
        mutationFn: (rule: Rule) => runRule(rule.id),
        onSuccess: ({ queued }, rule) => (toast.success(rule.trigger === 'schedule' ? `${plural(queued, 'backup')} planned.` : `${plural(queued, 'copy', 'copies')} queued.`), refresh()),
        onError,
    });

    const failed = rules.isError ? rules : destinations.isError ? destinations : null;

    if (!rules.data || !destinations.data) {
        return failed ? <ServerError title='Could not load backup rules' message={httpErrorToHuman(failed.error)} onRetry={() => failed.refetch()} /> : <Spinner centered size={Spinner.Size.LARGE} />;
    }

    if (editing) {
        return <RuleEditor rule={editing === 'new' ? null : editing} destinations={destinations.data} pending={saving.isPending} onCancel={() => setEditing(null)} onSave={(input) => saving.mutate(input)} />;
    }

    return (
        <>
            {rules.data.length === 0 ? (
                <EmptyState
                    icon='list-checks'
                    title='No backup rules'
                    description={destinations.data.length === 0 ? 'Add a destination first, then a rule decides which backups go there.' : 'A rule decides which servers are copied, where to, and how long copies are kept.'}
                    action={
                        <Button disabled={destinations.data.length === 0} onClick={() => setEditing('new')}>
                            Add a rule
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className='ofs:mb-3 ofs:flex ofs:justify-end'>
                        <Button size='small' onClick={() => setEditing('new')}>
                            Add rule
                        </Button>
                    </div>
                    <div className='ofs:min-w-0 ofs:max-w-full ofs:overflow-x-auto'>
                        <Table
                            rows={rules.data}
                            keyOf={(rule) => String(rule.id)}
                            emptyState={null}
                            isFetching={rules.isFetching}
                            columns={[
                                {
                                    id: 'name',
                                    label: 'Rule',
                                    cell: (rule) => (
                                        <div className='ofs:min-w-0 ofs:max-w-80'>
                                            <p className='ofs:flex ofs:items-center ofs:gap-2'>
                                                <span className='ofs:truncate ofs:text-foreground'>{rule.name}</span>
                                                {!rule.enabled && (
                                                    <Badge variant='soft' tone='warning' size='sm'>
                                                        Paused
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className='ofs:truncate ofs:text-xs ofs:text-muted-foreground'>
                                                {triggerSummary(rule)}
                                                {rule.trigger === 'schedule' && rule.next_run_at ? ` · next ${formatAgo(rule.next_run_at)}` : ''}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'servers',
                                    label: 'Servers',
                                    cell: (rule) => (
                                        <div className='ofs:min-w-0 ofs:max-w-64'>
                                            <p className='ofs:truncate' title={targetSummary(rule)}>
                                                {targetSummary(rule)}
                                            </p>
                                            <p className='ofs:text-xs ofs:text-muted-foreground'>
                                                {plural(rule.matches, 'match', 'matches')}
                                                {rule.excluded.length > 0 ? ` · ${rule.excluded.length} excluded` : ''}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'destinations',
                                    label: 'Stored on',
                                    cell: (rule) => (
                                        <div className='ofs:min-w-0 ofs:max-w-64'>
                                            <p className='ofs:truncate'>{rule.destinations.map((destination) => destination.name).join(', ')}</p>
                                            <p className='ofs:text-xs ofs:text-muted-foreground'>{retentionSummary(rule)}</p>
                                        </div>
                                    ),
                                },
                                actionsColumn(3, (rule: Rule) => (
                                    <RowActions>
                                        <RowActionButton aria-label={`Run ${rule.name} now`} icon={Play} label='Run now' disabled={running.isPending || !rule.enabled} disabledReason='Paused rules do not run' onClick={() => running.mutate(rule)} />
                                        <EditAction aria-label={`Edit ${rule.name}`} onClick={() => setEditing(rule)} />
                                        <DeleteAction aria-label={`Delete ${rule.name}`} onClick={() => setRemoving(rule)} />
                                    </RowActions>
                                )),
                            ]}
                        />
                    </div>
                </>
            )}
            <Dialog.Confirm open={removing !== null} onClose={() => setRemoving(null)} title={`Delete ${removing?.name}?`} confirm='Delete' pending={removal.isPending} onConfirmed={() => removing && removal.mutate(removing)}>
                No new copies are made for it. Copies it already made stay until you delete them.
            </Dialog.Confirm>
        </>
    );
}
