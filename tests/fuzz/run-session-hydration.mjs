import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname( fileURLToPath( import.meta.url ) );
const root = resolve( directory, '../..' );
const temporaryDirectory = mkdtempSync( join( tmpdir(), 'fac-session-hydration-' ) );
const suitePath = join( temporaryDirectory, 'session-hydration.json' );

try {
	writeFileSync(
		suitePath,
		readFileSync( join( directory, 'session-hydration.template.json' ), 'utf8' ).replaceAll( '__REPO_ROOT__', root )
	);
	const result = spawnSync( 'wp-codebox', [ 'run-fuzz-suite', '--input-file', suitePath, '--format=json', '--runner-mode=runtime-backed' ], { cwd: root, stdio: 'inherit' } );
	process.exitCode = result.status ?? 1;
} finally {
	rmSync( temporaryDirectory, { recursive: true, force: true } );
}
