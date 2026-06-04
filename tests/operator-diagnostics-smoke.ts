import assert from 'node:assert/strict';
import {
	getOperatorDiagnosticsPanel,
	getOperatorDiagnosticsRows,
	shouldRenderOperatorDiagnostics,
} from '../src/operator-diagnostics.ts';

const metadata = {
	operator_diagnostics: {
		title: 'Retrieval diagnostics',
		rows: [
			{ label: 'Mode', value: 'semantic' },
			{ label: 'Result count', value: 3 },
			{ label: 'Provider errors', value: [ 'timeout' ] },
		],
	},
};

assert.equal(
	shouldRenderOperatorDiagnostics( false, metadata ),
	false,
	'diagnostics stay hidden when the operator gate is disabled'
);

assert.equal(
	shouldRenderOperatorDiagnostics( true, {} ),
	false,
	'diagnostics stay hidden when response metadata has no operator diagnostics payload'
);

assert.equal(
	shouldRenderOperatorDiagnostics( true, metadata ),
	true,
	'diagnostics render when the operator gate is enabled and generic diagnostics metadata exists'
);

assert.deepEqual(
	getOperatorDiagnosticsRows( metadata ).map( ( row ) => row.label ),
	[ 'Mode', 'Result count', 'Provider errors' ],
	'operator diagnostics rows are supplied by metadata rather than a built-in domain field map'
);

assert.equal(
	getOperatorDiagnosticsPanel( metadata )?.title,
	'Retrieval diagnostics',
	'domain integrations may provide their own panel title as metadata'
);

console.log( 'Frontend operator diagnostics smoke passed (5 assertions).' );
