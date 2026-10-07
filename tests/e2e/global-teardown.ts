import { existsSync, renameSync } from 'node:fs';
import { HOT_BACKUP, HOT_FILE } from './global-setup';

/** Restores whatever `public/hot` state the developer had before the run. */
export default function globalTeardown() {
    if (existsSync(HOT_BACKUP)) {
        renameSync(HOT_BACKUP, HOT_FILE);
    }
}
