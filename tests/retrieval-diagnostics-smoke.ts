import assert from 'node:assert/strict';
import {
	getRetrievalDiagnosticsRows,
	shouldRenderRetrievalDiagnostics,
} from '../src/retrieval-diagnostics.ts';

const metadata = {
	retrieval_diagnostics: {
		mode: 'semantic',
		knowledge_base: 'docs',
		result_count: 3,
		provider_errors: [ 'timeout' ],
		excluded_sources_summary: '2 stale entries',
		score_details: { top: 0.91 },
	},
};

assert.equal(
	shouldRenderRetrievalDiagnostics( false, metadata ),
	false,
	'diagnostics stay hidden when the operator gate is disabled'
);

assert.equal(
	shouldRenderRetrievalDiagnostics( true, {} ),
	false,
	'diagnostics stay hidden when response metadata has no retrieval fields'
);

assert.equal(
	shouldRenderRetrievalDiagnostics( true, metadata ),
	true,
	'diagnostics render when the operator gate is enabled and retrieval metadata exists'
);

assert.deepEqual(
	getRetrievalDiagnosticsRows( metadata ).map( ( row ) => row.label ),
	[ 'Mode', 'Corpus', 'Results', 'Provider errors', 'Excluded sources', 'Scores' ],
	'generic retrieval diagnostics rows are normalized without provider-specific fields'
);

console.log( 'Frontend retrieval diagnostics smoke passed (4 assertions).' );
