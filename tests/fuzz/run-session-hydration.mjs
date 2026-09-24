import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = resolve( scriptDirectory, '../..' );
const templatePath = join( scriptDirectory, 'session-hydration.template.json' );
const temporaryDirectory = mkdtempSync(
	join( tmpdir(), 'fac-session-hydration-' )
);
const suitePath = join( temporaryDirectory, 'session-hydration.json' );

try {
	const suite = readFileSync( templatePath, 'utf8' ).replaceAll(
		'__REPO_ROOT__',
		repositoryRoot
	);
	writeFileSync( suitePath, suite );
	const result = spawnSync(
		'wp-codebox',
		[
			'run-fuzz-suite',
			'--input-file',
			suitePath,
			'--format=json',
			'--runner-mode=runtime-backed',
		],
		{ cwd: repositoryRoot, stdio: 'inherit' }
	);
	process.exitCode = result.status ?? 1;
} finally {
	rmSync( temporaryDirectory, { recursive: true, force: true } );
}
