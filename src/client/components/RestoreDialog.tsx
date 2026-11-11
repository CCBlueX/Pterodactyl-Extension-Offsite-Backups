import { useEffect, useState } from 'react';
import { Dialog, Switch } from '@pterodactyl/sdk';

interface Props {
    /** The backup name, or null while closed. */
    name: string | null;
    pending: boolean;
    onClose: () => void;
    onRestore: (truncate: boolean) => void;
}

/** Confirms a restore from an off-site copy, optionally wiping the server first. */
export default function RestoreDialog({ name, pending, onClose, onRestore }: Props) {
    const [truncate, setTruncate] = useState(false);

    useEffect(() => {
        if (name !== null) {
            setTruncate(false);
        }
    }, [name]);

    return (
        <Dialog.Confirm open={name !== null} onClose={onClose} title={`Restore "${name}"?`} confirm='Restore' pending={pending} onConfirmed={() => onRestore(truncate)}>
            <p>The server is stopped and the files of this backup are copied back from off-site storage. This can take a while for large backups.</p>
            <div className='ofs:mt-4'>
                <Switch id='ofs-restore-truncate' checked={truncate} onChange={setTruncate} label='Delete all files first' description='Removes every file on the server before the backup is restored.' />
            </div>
        </Dialog.Confirm>
    );
}
