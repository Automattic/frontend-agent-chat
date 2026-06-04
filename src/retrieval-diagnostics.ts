export interface RetrievalDiagnosticsRow {
	label: string;
	value: string;
}

export interface RetrievalDiagnosticsPanel {
	title: string;
	rows: RetrievalDiagnosticsRow[];
}

const DIAGNOSTICS_KEYS = [
	'retrieval_diagnostics',
	'retrievalDiagnostics',
	'retrieval_metadata',
	'retrievalMetadata',
	'retrieval',
];

const FIELD_MAP: Array< { label: string; keys: string[] } > = [
	{ label: 'Mode', keys: [ 'mode', 'retrieval_mode', 'retrievalMode', 'search_mode', 'searchMode' ] },
	{ label: 'Corpus', keys: [ 'corpus', 'corpus_id', 'corpusId', 'knowledge_base', 'knowledgeBase', 'knowledge_base_id', 'knowledgeBaseId', 'index', 'collection' ] },
	{ label: 'Results', keys: [ 'result_count', 'resultCount', 'results_count', 'resultsCount', 'count', 'total' ] },
	{ label: 'Provider errors', keys: [ 'provider_errors', 'providerErrors', 'errors', 'error' ] },
	{ label: 'Excluded sources', keys: [ 'excluded_sources_summary', 'excludedSourcesSummary', 'excluded_sources', 'excludedSources', 'excluded' ] },
	{ label: 'Scores', keys: [ 'score_details', 'scoreDetails', 'scores', 'score' ] },
];

function asRecord( value: unknown ): Record< string, unknown > | null {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value as Record< string, unknown >
		: null;
}

function hasAnyDiagnosticsField( source: Record< string, unknown > ): boolean {
	return FIELD_MAP.some( ( field ) => field.keys.some( ( key ) => source[ key ] !== undefined && source[ key ] !== null ) );
}

function findDiagnosticsSource( metadata: Record< string, unknown > ): Record< string, unknown > | null {
	for ( const key of DIAGNOSTICS_KEYS ) {
		const source = asRecord( metadata[ key ] );
		if ( source ) {
			return source;
		}
	}

	return hasAnyDiagnosticsField( metadata ) ? metadata : null;
}

function stringifyValue( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value.trim();
	}

	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}

	if ( Array.isArray( value ) ) {
		return value
			.map( stringifyValue )
			.filter( Boolean )
			.join( ', ' );
	}

	if ( value && typeof value === 'object' ) {
		try {
			return JSON.stringify( value );
		} catch {
			return '';
		}
	}

	return '';
}

function readDiagnosticValue( source: Record< string, unknown >, keys: string[] ): string {
	for ( const key of keys ) {
		const value = source[ key ];
		if ( value === undefined || value === null ) {
			continue;
		}

		const rendered = stringifyValue( value );
		if ( rendered ) {
			return rendered;
		}
	}

	return '';
}

export function getRetrievalDiagnosticsRows( metadata: Record< string, unknown > ): RetrievalDiagnosticsRow[] {
	const source = findDiagnosticsSource( metadata );
	if ( ! source ) {
		return [];
	}

	return FIELD_MAP.map( ( field ) => ( {
		label: field.label,
		value: readDiagnosticValue( source, field.keys ),
	} ) ).filter( ( row ) => row.value !== '' );
}

export function getRetrievalDiagnosticsPanel( metadata: Record< string, unknown > ): RetrievalDiagnosticsPanel | null {
	const rows = getRetrievalDiagnosticsRows( metadata );
	if ( rows.length === 0 ) {
		return null;
	}

	return {
		title: 'Retrieval diagnostics',
		rows,
	};
}

export function shouldRenderRetrievalDiagnostics( enabled: boolean, metadata: Record< string, unknown > | null | undefined ): boolean {
	return enabled && !! metadata && getRetrievalDiagnosticsRows( metadata ).length > 0;
}
