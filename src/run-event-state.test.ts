/**
 * External dependencies
 */
import type { AgentsApiRunEvent } from '@automattic/agenttic-client/agents-api';

/**
 * Internal dependencies
 */
import {
	getRunArtifactSummaries,
	getRunDiagnosticSummaries,
	getRunEventState,
	getRunProgressSummary,
} from './run-event-state';

declare function describe( name: string, callback: () => void ): void;
declare function it( name: string, callback: () => void ): void;
declare const expect: ( value: unknown ) => {
	toBe: ( expected: unknown ) => void;
	toBeNull: () => void;
	toEqual: ( expected: unknown ) => void;
};

function runEvent(
	type: string,
	metadata: Record< string, unknown > = {},
	status = 'running'
): AgentsApiRunEvent {
	return {
		id: `run-1-${ type }`,
		run_id: 'run-1',
		session_id: 'session-1',
		type,
		status,
		metadata,
		raw: { type, metadata },
	};
}

describe( 'run event state', () => {
	it( 'extracts progress counts from canonical metadata', () => {
		expect(
			getRunProgressSummary(
				runEvent( 'progress', {
					progress: {
						completed: 3,
						total: 5,
						unit: 'steps',
					},
				} )
			)
		).toEqual( {
			current: 3,
			total: 5,
			percent: undefined,
			unit: 'steps',
			label: undefined,
		} );
	} );

	it( 'extracts generic progress envelopes', () => {
		expect(
			getRunProgressSummary(
				runEvent( 'status', {
					progress_envelope: {
						value: 25,
						max: 100,
						percentage: 0.25,
						phase: 'Rendering preview',
					},
				} )
			)
		).toEqual( {
			current: 25,
			total: 100,
			percent: 25,
			unit: undefined,
			label: 'Rendering preview',
		} );
	} );

	it( 'extracts Codebox normalized progress envelopes', () => {
		const event = runEvent( 'worker.completed', {}, 'running' );
		event.raw = {
			type: 'worker.completed',
			normalized_progress: {
				schema: 'wp-codebox/live-progress-event/v1',
				phase: 'worker.completed',
				status: 'succeeded',
				label: 'Worker completed',
				progress: {
					completed: 2,
					total: 3,
				},
				artifacts: [ { id: 'artifact-1', label: 'Generated site' } ],
				diagnostics: [ { level: 'info', message: 'Worker finished' } ],
			},
		};

		expect( getRunProgressSummary( event ) ).toEqual( {
			current: 2,
			total: 3,
			percent: undefined,
			unit: undefined,
			label: 'Worker completed',
		} );
		expect( getRunArtifactSummaries( event ) ).toEqual( [
			{
				id: 'artifact-1',
				label: 'Generated site',
				url: undefined,
				type: undefined,
			},
		] );
		expect( getRunDiagnosticSummaries( event ) ).toEqual( [
			{ level: 'info', message: 'Worker finished' },
		] );
	} );

	it( 'extracts artifact refs from generic event metadata', () => {
		expect(
			getRunArtifactSummaries(
				runEvent( 'artifact.ready', {
					artifacts: [
						{ id: 'log', title: 'Log bundle', url: 'https://example.com/log' },
					],
					artifact_ref: 'summary.md',
				} )
			)
		).toEqual( [
			{
				label: 'summary.md',
				url: undefined,
			},
			{
				id: 'log',
				label: 'Log bundle',
				url: 'https://example.com/log',
				type: undefined,
			},
		] );
	} );

	it( 'extracts diagnostics without orchestration-specific fields', () => {
		expect(
			getRunDiagnosticSummaries(
				runEvent( 'diagnostic', {
					diagnostics: [ { level: 'warning', message: 'Retrying provider' } ],
					errors: [ 'Final artifact failed' ],
				} )
			)
		).toEqual( [
			{ level: 'warning', message: 'Retrying provider' },
			{ level: 'error', message: 'Final artifact failed' },
		] );
	} );

	it( 'accumulates ambient state across run events', () => {
		expect(
			getRunEventState( [
				runEvent( 'progress', {
					label: 'Generating preview',
					progress_current: 1,
					progress_total: 2,
				} ),
				runEvent(
					'artifact',
					{
						artifact: {
							id: 'preview',
							label: 'Preview',
							url: 'https://example.com/preview',
						},
					},
					'completed'
				),
			] )
		).toEqual( {
			runId: 'run-1',
			sessionId: 'session-1',
			status: 'completed',
			label: 'Generating preview',
			progress: {
				current: 1,
				total: 2,
				percent: undefined,
				unit: undefined,
				label: 'Generating preview',
			},
			timeline: [
				{
					id: 'run-1-progress',
					type: 'progress',
					label: 'Generating preview',
					status: 'running',
				},
			],
			artifacts: [
				{
					id: 'preview',
					label: 'Preview',
					url: 'https://example.com/preview',
					type: undefined,
				},
			],
			diagnostics: [],
		} );
	} );

	it( 'returns null when there are no events', () => {
		expect( getRunEventState( [] ) ).toBeNull();
	} );
} );
