import type { AgentsApiRunEvent } from '@automattic/agenttic-client/agents-api';

export interface RunProgressSummary {
	current?: number;
	total?: number;
	percent?: number;
	unit?: string;
	label?: string;
}

export interface RunTimelineEntry {
	id: string;
	type: string;
	label: string;
	status?: string;
}

export interface RunArtifactSummary {
	id?: string;
	label: string;
	url?: string;
	type?: string;
}

export interface RunDiagnosticSummary {
	level: 'info' | 'warning' | 'error';
	message: string;
}

export interface RunEventState {
	runId: string;
	sessionId?: string;
	status?: string;
	label: string;
	progress?: RunProgressSummary;
	timeline: RunTimelineEntry[];
	artifacts: RunArtifactSummary[];
	diagnostics: RunDiagnosticSummary[];
}

export function getRunEventState(
	events: AgentsApiRunEvent[]
): RunEventState | null {
	return events.reduce< RunEventState | null >( ( state, event ) => {
		const nextState = state ?? createRunEventState( event );
		const metadata = asRecord( event.metadata );
		nextState.status = event.status ?? nextState.status;
		nextState.label = getEventLabel( event, metadata ) ?? nextState.label;

		const timelineEntry = getRunTimelineEntry( event, metadata );
		if ( timelineEntry && ! hasTimelineEntry( nextState.timeline, timelineEntry ) ) {
			nextState.timeline.push( timelineEntry );
		}

		const progress = getRunProgressSummary( event );
		if ( progress ) {
			nextState.progress = progress;
		}

		for ( const artifact of getRunArtifactSummaries( event ) ) {
			if ( ! hasArtifact( nextState.artifacts, artifact ) ) {
				nextState.artifacts.push( artifact );
			}
		}

		nextState.diagnostics.push( ...getRunDiagnosticSummaries( event ) );
		return nextState;
	}, null );
}

export function getRunProgressSummary(
	event: AgentsApiRunEvent
): RunProgressSummary | null {
	const metadata = asRecord( event.metadata );
	const envelope = getProgressEnvelope( event, metadata );
	const envelopeProgress = asRecord( envelope.progress );
	const current = readNumber(
		envelopeProgress.current,
		envelopeProgress.completed,
		envelopeProgress.done,
		envelopeProgress.value,
		envelopeProgress.count,
		envelope.current,
		envelope.completed,
		envelope.done,
		envelope.value,
		envelope.count,
		metadata.progress_current,
		metadata.progressCurrent,
		metadata.completed,
		metadata.done,
		metadata.count
	);
	const total = readNumber(
		envelopeProgress.total,
		envelopeProgress.max,
		envelope.total,
		envelope.max,
		metadata.progress_total,
		metadata.progressTotal,
		metadata.total
	);
	const percent = normalizePercent(
		readNumber(
			envelopeProgress.percent,
			envelopeProgress.percentage,
			envelopeProgress.pct,
			envelope.percent,
			envelope.percentage,
			envelope.pct,
			metadata.progress_percent,
			metadata.progressPercent,
			metadata.percent,
			metadata.percentage
		)
	);
	const label = readString(
		envelope.label,
		envelope.title,
		envelope.message,
		envelope.phase,
		metadata.label,
		metadata.message,
		event.raw.message
	);
	const unit = readString( envelope.unit, metadata.unit );
	const hasProgressType = event.type.includes( 'progress' );

	if (
		current === undefined &&
		total === undefined &&
		percent === undefined &&
		! label &&
		! hasProgressType
	) {
		return null;
	}

	return { current, total, percent, unit, label };
}

export function getRunArtifactSummaries(
	event: AgentsApiRunEvent
): RunArtifactSummary[] {
	const metadata = asRecord( event.metadata );
	const envelope = getProgressEnvelope( event, metadata );
	const candidates = [
		metadata.artifact,
		metadata.artifact_ref,
		metadata.artifactRef,
		metadata.artifact_url,
		metadata.artifactUrl,
		...( Array.isArray( metadata.artifacts ) ? metadata.artifacts : [] ),
		...( Array.isArray( envelope.artifacts ) ? envelope.artifacts : [] ),
	];

	return candidates
		.map( normalizeArtifact )
		.filter( ( artifact ): artifact is RunArtifactSummary => !! artifact );
}

export function getRunDiagnosticSummaries(
	event: AgentsApiRunEvent
): RunDiagnosticSummary[] {
	const metadata = asRecord( event.metadata );
	const envelope = getProgressEnvelope( event, metadata );
	const diagnostics = [
		metadata.diagnostic,
		envelope.diagnostic,
		...( Array.isArray( metadata.diagnostics ) ? metadata.diagnostics : [] ),
		...( Array.isArray( envelope.diagnostics ) ? envelope.diagnostics : [] ),
		...( Array.isArray( metadata.warnings )
			? metadata.warnings.map( ( warning ) => ( {
					level: 'warning',
					message: warning,
			  } ) )
			: [] ),
		...( Array.isArray( metadata.errors )
			? metadata.errors.map( ( error ) => ( {
					level: 'error',
					message: error,
			  } ) )
			: [] ),
	];

	if ( event.type.includes( 'diagnostic' ) ) {
		diagnostics.push( metadata.message ?? event.raw.message );
	}

	return diagnostics
		.map( normalizeDiagnostic )
		.filter(
			( diagnostic ): diagnostic is RunDiagnosticSummary => !! diagnostic
		);
}

function createRunEventState( event: AgentsApiRunEvent ): RunEventState {
	return {
		runId: event.run_id,
		sessionId: event.session_id,
		status: event.status,
		label: 'Run activity',
		timeline: [],
		artifacts: [],
		diagnostics: [],
	};
}

function getEventLabel(
	event: AgentsApiRunEvent,
	metadata: Record< string, unknown >
): string | undefined {
	const envelope = getProgressEnvelope( event, metadata );
	return readString(
		envelope.title,
		envelope.label,
		envelope.message,
		metadata.title,
		metadata.label,
		metadata.message,
		event.raw.message
	);
}

function getProgressEnvelope(
	event: AgentsApiRunEvent,
	metadata: Record< string, unknown >
): Record< string, unknown > {
	const raw = asRecord( event.raw );
	const candidates = [
		metadata.progress_envelope,
		metadata.normalized_progress,
		raw.normalized_progress,
		metadata.progress,
		raw.progress,
	];

	for ( const candidate of candidates ) {
		const envelope = asRecord( candidate );
		if ( Object.keys( envelope ).length ) {
			return envelope;
		}
	}

	return {};
}

function getRunTimelineEntry(
	event: AgentsApiRunEvent,
	metadata: Record< string, unknown >
): RunTimelineEntry | null {
	const label = getEventLabel( event, metadata );
	if ( ! label ) {
		return null;
	}

	return {
		id: readString( event.id, metadata.id ) ?? `${ event.type }:${ label }`,
		type: event.type,
		label,
		status: event.status,
	};
}

function normalizeArtifact( value: unknown ): RunArtifactSummary | null {
	if ( typeof value === 'string' && value.trim() ) {
		return {
			label: value,
			url: isLikelyUrl( value ) ? value : undefined,
		};
	}

	const record = asRecord( value );
	const url = readString( record.url, record.href, record.preview_url, record.previewUrl );
	const id = readString( record.id, record.ref, record.artifact_id, record.artifactId );
	const label = readString( record.label, record.title, record.name, id, url );
	if ( ! label ) {
		return null;
	}

	return {
		id,
		label,
		url,
		type: readString( record.type, record.kind ),
	};
}

function normalizeDiagnostic( value: unknown ): RunDiagnosticSummary | null {
	if ( typeof value === 'string' && value.trim() ) {
		return { level: 'info', message: value.trim() };
	}

	const record = asRecord( value );
	const message = readString( record.message, record.text, record.detail );
	if ( ! message ) {
		return null;
	}

	const level = readString( record.level, record.severity, record.type );
	return {
		level: normalizeDiagnosticLevel( level ),
		message,
	};
}

function normalizeDiagnosticLevel(
	level: string | undefined
): RunDiagnosticSummary[ 'level' ] {
	if ( level === 'error' || level === 'warning' ) {
		return level;
	}

	return 'info';
}

function hasArtifact(
	artifacts: RunArtifactSummary[],
	candidate: RunArtifactSummary
): boolean {
	return artifacts.some(
		( artifact ) =>
			( candidate.id && artifact.id === candidate.id ) ||
			( candidate.url && artifact.url === candidate.url ) ||
			artifact.label === candidate.label
	);
}

function hasTimelineEntry(
	timeline: RunTimelineEntry[],
	candidate: RunTimelineEntry
): boolean {
	return timeline.some( ( entry ) => entry.id === candidate.id );
}

function normalizePercent( value: number | undefined ): number | undefined {
	if ( value === undefined ) {
		return undefined;
	}

	const percent = value > 0 && value <= 1 ? value * 100 : value;
	return Math.max( 0, Math.min( 100, percent ) );
}

function readNumber( ...values: unknown[] ): number | undefined {
	for ( const value of values ) {
		if ( typeof value === 'number' && Number.isFinite( value ) ) {
			return value;
		}
		if ( typeof value === 'string' && value.trim() ) {
			const parsed = Number( value );
			if ( Number.isFinite( parsed ) ) {
				return parsed;
			}
		}
	}

	return undefined;
}

function readString( ...values: unknown[] ): string | undefined {
	for ( const value of values ) {
		if ( typeof value === 'string' && value.trim() ) {
			return value.trim();
		}
	}

	return undefined;
}

function asRecord( value: unknown ): Record< string, unknown > {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? ( value as Record< string, unknown > )
		: {};
}

function isLikelyUrl( value: string ): boolean {
	return /^https?:\/\//.test( value );
}
