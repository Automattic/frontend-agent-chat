export type RetrievalStateKind = 'grounded' | 'partial' | 'no_answer' | 'error';

export interface RetrievalState {
	kind: RetrievalStateKind;
	label: string;
	description: string;
	sourceCount?: number;
}

const STATUS_ALIASES: Record< string, RetrievalStateKind > = {
	grounded: 'grounded',
	found: 'grounded',
	available: 'grounded',
	retrieved: 'grounded',
	partial: 'partial',
	limited: 'partial',
	insufficient: 'partial',
	weak: 'partial',
	no_answer: 'no_answer',
	no_answer_found: 'no_answer',
	no_relevant_source: 'no_answer',
	no_relevant_sources: 'no_answer',
	no_source: 'no_answer',
	no_sources: 'no_answer',
	not_found: 'no_answer',
	empty: 'no_answer',
	unavailable: 'error',
	retrieval_error: 'error',
	error: 'error',
	failed: 'error',
};

const STATUS_PATHS = [
	[ 'retrieval_status' ],
	[ 'retrievalStatus' ],
	[ 'grounding_status' ],
	[ 'groundingStatus' ],
	[ 'retrieved_context_status' ],
	[ 'retrievedContextStatus' ],
	[ 'retrieval', 'status' ],
	[ 'retrieval', 'state' ],
	[ 'retrieved_context', 'status' ],
	[ 'retrievedContext', 'status' ],
	[ 'grounding', 'status' ],
	[ 'grounding', 'state' ],
];

const SOURCE_COUNT_PATHS = [
	[ 'source_count' ],
	[ 'sourceCount' ],
	[ 'sources_count' ],
	[ 'sourcesCount' ],
	[ 'citation_count' ],
	[ 'citationCount' ],
	[ 'retrieval', 'source_count' ],
	[ 'retrieval', 'sourceCount' ],
	[ 'retrieval', 'sources' ],
	[ 'retrieval', 'citations' ],
	[ 'retrieved_context', 'source_count' ],
	[ 'retrievedContext', 'sourceCount' ],
	[ 'retrieved_context', 'sources' ],
	[ 'retrievedContext', 'sources' ],
	[ 'grounding', 'source_count' ],
	[ 'grounding', 'sourceCount' ],
	[ 'grounding', 'sources' ],
	[ 'sources' ],
	[ 'citations' ],
];

export function getRetrievalState( metadata: Record< string, unknown > | undefined ): RetrievalState | null {
	if ( ! metadata ) {
		return null;
	}

	const sourceCount = readSourceCount( metadata );
	const explicitStatus = readStatus( metadata );
	const kind = explicitStatus ?? inferStatus( metadata, sourceCount );

	if ( ! kind ) {
		return null;
	}

	return createRetrievalState( kind, sourceCount );
}

function createRetrievalState( kind: RetrievalStateKind, sourceCount?: number ): RetrievalState {
	if ( kind === 'grounded' ) {
		return {
			kind,
			label: 'Grounded in retrieved context',
			description: describeSources( sourceCount, 'Retrieved context was available for this answer.' ),
			sourceCount,
		};
	}

	if ( kind === 'partial' ) {
		return {
			kind,
			label: 'Partial context available',
			description: describeSources( sourceCount, 'Retrieved context may be incomplete.' ),
			sourceCount,
		};
	}

	if ( kind === 'no_answer' ) {
		return {
			kind,
			label: 'No relevant sources found',
			description: 'The agent did not find retrieved context that matched this answer.',
			sourceCount,
		};
	}

	return {
		kind,
		label: 'Retrieval unavailable',
		description: 'Context lookup was unavailable for this answer.',
		sourceCount,
	};
}

function describeSources( sourceCount: number | undefined, fallback: string ): string {
	if ( sourceCount === undefined ) {
		return fallback;
	}

	if ( sourceCount === 1 ) {
		return '1 source was available for this answer.';
	}

	return `${ sourceCount } sources were available for this answer.`;
}

function inferStatus( metadata: Record< string, unknown >, sourceCount: number | undefined ): RetrievalStateKind | null {
	if ( readBoolean( metadata, [ 'retrieval_error', 'retrievalError' ] ) ) {
		return 'error';
	}

	if ( readBoolean( metadata, [ 'no_answer', 'noAnswer', 'no_relevant_source', 'noRelevantSource' ] ) ) {
		return 'no_answer';
	}

	if ( readBoolean( metadata, [ 'partial_evidence', 'partialEvidence', 'partial_context', 'partialContext' ] ) ) {
		return 'partial';
	}

	if ( sourceCount !== undefined ) {
		return sourceCount > 0 ? 'grounded' : 'no_answer';
	}

	return null;
}

function readStatus( metadata: Record< string, unknown > ): RetrievalStateKind | null {
	for ( const path of STATUS_PATHS ) {
		const value = readPath( metadata, path );
		if ( typeof value !== 'string' ) {
			continue;
		}

		const normalized = value.trim().toLowerCase().replace( /[\s-]+/g, '_' );
		if ( normalized in STATUS_ALIASES ) {
			return STATUS_ALIASES[ normalized ];
		}
	}

	return null;
}

function readSourceCount( metadata: Record< string, unknown > ): number | undefined {
	for ( const path of SOURCE_COUNT_PATHS ) {
		const value = readPath( metadata, path );
		if ( typeof value === 'number' && Number.isFinite( value ) && value >= 0 ) {
			return Math.floor( value );
		}

		if ( Array.isArray( value ) ) {
			return value.length;
		}
	}

	return undefined;
}

function readBoolean( metadata: Record< string, unknown >, keys: string[] ): boolean {
	return keys.some( ( key ) => metadata[ key ] === true );
}

function readPath( metadata: Record< string, unknown >, path: string[] ): unknown {
	return path.reduce< unknown >( ( current, key ) => {
		if ( ! isRecord( current ) ) {
			return undefined;
		}

		return current[ key ];
	}, metadata );
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}
