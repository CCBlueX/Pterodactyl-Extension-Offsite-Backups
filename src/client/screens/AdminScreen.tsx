import { Settings } from 'lucide-react';
import { PageContentBlock, PanelLink, usePanelNavigate, type ScreenComponentProps } from '@pterodactyl/sdk';
import Tabs from '../components/Tabs';
import CopiesTab from './admin/CopiesTab';
import DestinationsTab from './admin/DestinationsTab';
import OverviewTab from './admin/OverviewTab';
import RulesTab from './admin/RulesTab';
import StorageTab from './admin/StorageTab';

const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'destinations', label: 'Destinations' },
    { id: 'rules', label: 'Backup rules' },
    { id: 'copies', label: 'Copies' },
    { id: 'storage', label: 'Backup storage' },
] as const;

type Tab = (typeof TABS)[number]['id'];

export default function AdminScreen({ data }: ScreenComponentProps) {
    const navigate = usePanelNavigate();
    const tab: Tab = TABS.find((item) => item.id === data.params.tab)?.id ?? 'overview';
    const open = (id: Tab) => navigate({ extension: 'offsite-backups', screen: 'admin', params: { tab: id } });

    return (
        <PageContentBlock title='Offsite Backups'>
            <header className='ofs:mb-6 ofs:flex ofs:flex-col ofs:gap-3 ofs:sm:flex-row ofs:sm:items-center ofs:sm:justify-between'>
                <div className='ofs:min-w-0'>
                    <h1 className='ofs:font-header ofs:text-2xl ofs:font-semibold ofs:text-foreground'>Offsite Backups</h1>
                    <p className='ofs:mt-1 ofs:text-sm ofs:leading-relaxed ofs:text-foreground/70'>Copies every backup to S3 or SFTP, checked and restorable from the Backups page.</p>
                </div>
                <PanelLink destination={{ to: '/panel/extensions' }} className='ofs:inline-flex ofs:shrink-0 ofs:items-center ofs:gap-2 ofs:text-sm ofs:text-muted-foreground ofs:hover:text-foreground'>
                    <Settings className='ofs:size-4' aria-hidden />
                    Settings
                </PanelLink>
            </header>
            <Tabs label='Offsite Backups' tabs={TABS} active={tab} onChange={open} />
            {tab === 'overview' && <OverviewTab onOpen={open} />}
            {tab === 'destinations' && <DestinationsTab />}
            {tab === 'rules' && <RulesTab />}
            {tab === 'copies' && <CopiesTab />}
            {tab === 'storage' && <StorageTab />}
        </PageContentBlock>
    );
}
