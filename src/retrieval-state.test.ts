import { getRetrievalState } from './retrieval-state';

declare function describe( name: string, callback: () => void ): void;
declare function it( name: string, callback: () => void ): void;
declare const expect: ( value: unknown ) => {
	toBe: ( expected: unknown ) => void;
	toBeNull: () => void;
	toEqual: ( expected: unknown ) => void;
};

describe( 'getRetrievalState', () => {
	it( 'renders grounded state from explicit retrieval metadata', () => {
		expect( getRetrievalState( {
			retrieval: {
				status: 'grounded',
				sources: [ { id: 'a' }, { id: 'b' } ],
			},
		} ) ).toEqual( {
			kind: 'grounded',
			label: 'Grounded in retrieved context',
			description: '2 sources were available for this answer.',
			sourceCount: 2,
		} );
	} );

	it( 'renders partial state from generic grounding metadata', () => {
		expect( getRetrievalState( {
			groundingStatus: 'limited',
			citation_count: 1,
		} ) ).toEqual( {
			kind: 'partial',
			label: 'Partial context available',
			description: '1 source was available for this answer.',
			sourceCount: 1,
		} );
	} );

	it( 'renders no-answer state without alarming language', () => {
		expect( getRetrievalState( {
			retrieved_context: {
				status: 'no relevant sources',
			},
		} ) ).toEqual( {
			kind: 'no_answer',
			label: 'No relevant sources found',
			description: 'The agent did not find retrieved context that matched this answer.',
			sourceCount: undefined,
		} );
	} );

	it( 'renders retrieval-error state without exposing debug payloads', () => {
		expect( getRetrievalState( {
			retrieval_status: 'retrieval error',
			error: 'internal lookup timeout',
			debug: { query: 'private' },
		} ) ).toEqual( {
			kind: 'error',
			label: 'Retrieval unavailable',
			description: 'Context lookup was unavailable for this answer.',
			sourceCount: undefined,
		} );
	} );

	it( 'infers grounded state from source count metadata', () => {
		expect( getRetrievalState( {
			sources: [ { id: 'source' } ],
		} )?.kind ).toBe( 'grounded' );
	} );

	it( 'returns null when metadata has no retrieval signal', () => {
		expect( getRetrievalState( {
			run_id: 'run-1',
		} ) ).toBeNull();
	} );
} );
