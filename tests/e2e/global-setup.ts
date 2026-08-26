import { execFileSync } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { E2E_DB, E2E_ENV_FILE, E2E_OVERRIDES } from '../../playwright.config';

export const HOT_FILE = resolve(process.cwd(), 'public/hot');
export const HOT_BACKUP = resolve(process.cwd(), 'public/hot.e2e-backup');

/**
 * Prepares an isolated world for the browser suite:
 *
 * - `.env.e2e`, generated from the developer's own `.env` with the test
 *   overrides applied on top, so the app keeps its real APP_KEY and settings
 *   but points at a throwaway database and the `log` mailer.
 * - a fresh SQLite file, migrated and seeded, so no spec inherits state from a
 *   previous run or from the developer's dev database.
 * - `public/hot` moved aside. Its mere presence tells Laravel to serve assets
 *   from the Vite dev server, and a stale one left behind by a killed
 *   `npm run dev` points every page at a port nothing is listening on — the
 *   app then renders blank. globalTeardown puts it back.
 */
export default function globalSetup() {
    writeEnvFile();

    const dbPath = resolve(process.cwd(), E2E_DB);
    mkdirSync(dirname(dbPath), { recursive: true });
    rmSync(dbPath, { force: true });
    closeSync(openSync(dbPath, 'w')); // sqlite will not create it itself

    if (!existsSync(resolve(process.cwd(), 'public/build/manifest.json'))) {
        throw new Error('No Vite build found. Run `npm run build` before the E2E suite.');
    }

    if (existsSync(HOT_FILE)) {
        rmSync(HOT_BACKUP, { force: true });
        renameSync(HOT_FILE, HOT_BACKUP);
    }

    const env = { ...process.env, APP_ENV: 'e2e' };

    run('php', ['artisan', 'config:clear'], env);
    run('php', ['artisan', 'migrate:fresh', '--force', '--no-interaction'], env);
    run('php', ['artisan', 'db:seed', '--class=E2eSeeder', '--force', '--no-interaction'], env);
}

/**
 * Copies `.env` and applies the overrides. Keys already present are rewritten
 * in place rather than appended, so a duplicate cannot shadow the override.
 */
function writeEnvFile() {
    const source = resolve(process.cwd(), '.env');

    if (!existsSync(source)) {
        throw new Error('No .env found — the E2E environment is derived from it.');
    }

    const applied = new Set<string>();

    const lines = readFileSync(source, 'utf8')
        .split(/\r?\n/)
        .map((line) => {
            const key = line.match(/^\s*([A-Z0-9_]+)\s*=/)?.[1];

            if (key && key in E2E_OVERRIDES) {
                applied.add(key);

                return `${key}=${E2E_OVERRIDES[key]}`;
            }

            return line;
        });

    for (const [key, value] of Object.entries(E2E_OVERRIDES)) {
        if (!applied.has(key)) {
            lines.push(`${key}=${value}`);
        }
    }

    writeFileSync(resolve(process.cwd(), E2E_ENV_FILE), lines.join('\n'), 'utf8');
}

function run(cmd: string, args: string[], env: NodeJS.ProcessEnv) {
    execFileSync(cmd, args, { env, stdio: 'inherit', shell: process.platform === 'win32' });
}
