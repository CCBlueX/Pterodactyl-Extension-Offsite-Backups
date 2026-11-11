import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';

export default definePterodactylExtension({
    setup({ screens, views }) {
        screens.register('admin', () => import('./screens/AdminScreen'));
        screens.register('server-tab', () => import('./screens/ServerTab'));
        views.override('server.backups', { load: () => import('./views/BackupList') });
    },
});
