/**
 * AgentChat — Floating agent chat panel with diff visualization.
 *
 * FAB button at bottom-right → slide-in drawer from the right.
 * The Chat component stays mounted when the drawer closes so session
 * state, messages, and scroll position survive open/close cycles.
 *
 * When AI uses a pending-action tool (edit_post_blocks, replace_post_blocks,
 * insert_content) with preview mode, the tool result is rendered as a
 * DiffCard with Accept/Reject buttons instead of raw JSON. Accept/Reject
 * hit the frontend adapter's Agents API pending-action resolution endpoint.
 *
 * @package
 * @since 0.3.0
 */

/**
 * External dependencies
 */
import {
	Chat,
	DiffCard,
	QuestionCard,
	ToolMessage,
	useClientContextMetadata,
	parseCanonicalDiffFromToolGroup,
} from '@extrachill/chat';
import type { ChatMessage, ChatMessageSuggestion, ToolGroup, DiffData, FetchFn, MediaUploadFn, ToolRendererContext, QuestionChoice, ChatRunCapabilities, CancelRunInput, QueueMessageInput, QueueMessageResult } from '@extrachill/chat';
import type { ChangeEvent, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { createElement, useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

interface AgentChatProps {
	agentSlug?: string;
	basePath: string;
	bootstrapPath?: string;
	agentsPath: string;
	agentName: string;
	agentDescription: string;
	fabLabel?: string;
	fabIcon?: string;
	fabIconPath?: string;
	fabIconViewBox?: string;
	expandIconPath?: string;
	collapseIconPath?: string;
	expandIconViewBox?: string;
	layout?: 'floating' | 'inline';
	isLoggedIn?: boolean;
	loadingMessages?: boolean | {
		mode?: 'default' | 'extend' | 'override';
		messages?: string[];
		interval?: number;
	};
	persistenceCta?: {
		message?: string;
		actionLabel?: string;
		actionUrl?: string;
	};
	messageSuggestions?: ChatMessageSuggestion[];
	capabilities?: {
		chat_run_status?: boolean;
		chat_run_cancel?: boolean;
		chat_message_queue?: boolean;
		chat_run_events?: boolean;
	};
}

interface BootstrapResponse {
	success?: boolean;
	data?: {
		authenticated?: boolean;
		browser_principal_ready?: boolean;
		had_browser_principal?: boolean;
		session_persistence_scope?: 'user' | 'browser';
		capabilities?: AgentChatProps['capabilities'];
	};
}

interface RunControlResponse {
	success?: boolean;
	data?: {
		run_id?: string;
		session_id?: string;
		status?: string;
		started_at?: string;
		updated_at?: string;
		metadata?: Record< string, unknown >;
		queued_message_id?: string;
		position?: number;
	};
}

interface ChatRunEvent {
	id: string;
	type: string;
	message?: string;
	created_at?: string;
	metadata?: Record< string, unknown >;
}

interface RunEventsResponse {
	success?: boolean;
	data?: {
		run_id?: string;
		session_id?: string;
		status?: string;
		events?: ChatRunEvent[];
		cursor?: string;
		has_more?: boolean;
	};
}

async function bootstrapBrowserPrincipal( bootstrapPath: string ): Promise< boolean > {
	const response = await apiFetch( { path: bootstrapPath, method: 'POST' } ) as BootstrapResponse;
	const data = response.data ?? {};
	if ( data.authenticated || data.had_browser_principal ) {
		return data.browser_principal_ready !== false;
	}

	const verificationResponse = await apiFetch( { path: bootstrapPath, method: 'POST' } ) as BootstrapResponse;
	const verificationData = verificationResponse.data ?? {};
	return verificationData.browser_principal_ready !== false && verificationData.had_browser_principal === true;
}

interface AgentSummary {
	slug: string;
	name: string;
	description: string;
}

interface AgentsResponse {
	success?: boolean;
	data?: {
		active_agent_slug?: string;
		default_agent_slug?: string;
		agents?: AgentSummary[];
	};
}

type ArtifactPhaseStatus = 'pending' | 'running' | 'ready' | 'completed' | 'failed' | 'retrying';

interface ArtifactThumbnail {
	url: string;
	alt?: string;
}

interface ArtifactStatusPayload {
	title: string;
	phase: string;
	status: ArtifactPhaseStatus;
	description?: string;
	diagnosticsCount?: number;
	previewUrl?: string;
	materializedUrl?: string;
	thumbnails: ArtifactThumbnail[];
	error?: string;
}

interface SourceCardPayload {
	title?: string;
	url?: string;
	snippet?: string;
	documentId?: string;
	chunkId?: string;
	accessibleLabel?: string;
}

const DEFAULT_EXPAND_ICON_PATH = 'M3 8V3h5M21 8V3h-5M3 16v5h5M21 16v5h-5';
const DEFAULT_COLLAPSE_ICON_PATH = 'M8 3v5H3M16 3v5h5M8 21v-5H3M16 21v-5h5';

/**
 * Parse a tool result into DiffData for DiffCard rendering.
 *
 * Returns null if the tool result is not a preview action (e.g. the
 * tool was called without preview=true, or the result is malformed).
 *
 * @param group Tool group.
 * @return Diff data when present.
 */
function parseDiffFromToolResult( group: ToolGroup ): DiffData | null {
	return parseCanonicalDiffFromToolGroup( group );
}

/**
 * Resolve a pending action by id.
 *
 * The server route dispatches to the canonical `agents/resolve-pending-action`
 * ability so tool preview resolution stays independent from the concrete
 * runtime/store implementation.
 *
 * @param actionId Pending action ID.
 * @param decision Resolution decision.
 */
function resolvePendingAction( actionId: string, decision: 'accepted' | 'rejected' ): void {
	apiFetch( {
		path: '/frontend-agent-chat/v1/chat/actions/resolve',
		method: 'POST',
		data: { action_id: actionId, decision },
	} ).catch( ( err: unknown ) => {
		// eslint-disable-next-line no-console
		console.error( 'AgentChat: failed to resolve pending action', actionId, err );
	} );
}

/**
 * Capture the page the widget is currently rendered on.
 *
 * Forwarded on POST requests so the backend can supply real page/location
 * context to the agent. This is intentionally generic: the widget only knows
 * "the current page" — it carries no product- or runtime-specific knowledge.
 * Domain plugins decide what to do with it (e.g. compose client context).
 *
 * @return Page context fields, or an empty object when unavailable.
 */
function getPageContext(): Record< string, string > {
	if ( typeof window === 'undefined' || ! window.location ) {
		return {};
	}

	const context: Record< string, string > = {
		page_url: window.location.href,
	};

	if ( typeof document !== 'undefined' && document.title ) {
		context.page_title = document.title;
	}

	return context;
}

function createAgentFetch( agentSlug: string ): FetchFn {
	return ( options ) => {
		const method = options.method ?? 'GET';
		const separator = options.path.includes( '?' ) ? '&' : '?';

		return apiFetch( {
			path: method === 'GET' || method === 'DELETE'
				? `${ options.path }${ separator }agent=${ encodeURIComponent( agentSlug ) }`
				: options.path,
			method: options.method,
			data: method === 'POST'
				? { ...getPageContext(), ...( options.data ?? {} ), agent: agentSlug }
				: options.data,
			headers: options.headers,
		} );
	};
}

function createRunCapabilities( capabilities?: AgentChatProps['capabilities'] ): ChatRunCapabilities {
	return {
		cancel: !! capabilities?.chat_run_cancel,
		queue: !! capabilities?.chat_message_queue,
	};
}

function getRunId( metadata: Record< string, unknown > ): string | null {
	const runId = metadata.run_id ?? metadata.runId;
	return typeof runId === 'string' && runId.trim() ? runId : null;
}

function createCancelRun( fetchFn: FetchFn, basePath: string ): ( input: CancelRunInput ) => Promise< void > {
	return async ( input ) => {
		await fetchFn( {
			path: `${ basePath }/runs/${ encodeURIComponent( input.runId ) }/cancel`,
			method: 'POST',
			data: { session_id: input.sessionId },
		} );
	};
}

function normalizeQueueResult( response: RunControlResponse ): QueueMessageResult {
	const data = response.data ?? {};
	return {
		queuedMessageId: data.queued_message_id,
		runId: data.run_id,
		sessionId: data.session_id,
		status: data.status as QueueMessageResult['status'],
		startedAt: data.started_at,
		updatedAt: data.updated_at,
		metadata: data.metadata,
		position: data.position,
	};
}

function createQueueMessage( fetchFn: FetchFn, uploadFn: MediaUploadFn, basePath: string ): ( input: QueueMessageInput ) => Promise< QueueMessageResult > {
	return async ( input ) => {
		const attachments = input.files?.length
			? await Promise.all( input.files.map( async ( file ) => {
				const uploaded = await uploadFn( file );
				return {
					filename: file.name,
					mime_type: file.type,
					url: uploaded.url,
					media_id: uploaded.media_id,
				};
			} ) )
			: [];

		const response = await fetchFn( {
			path: `${ basePath }/queue`,
			method: 'POST',
			data: {
				session_id: input.sessionId,
				run_id: input.runId,
				message: input.content,
				attachments,
			},
		} ) as RunControlResponse;

		return normalizeQueueResult( response );
	};
}

function persistActiveAgent( agentSlug: string ): void {
	apiFetch( {
		path: '/frontend-agent-chat/v1/agents/active',
		method: 'POST',
		data: { agent: agentSlug },
	} ).catch( ( err: unknown ) => {
		// eslint-disable-next-line no-console
		console.error( 'AgentChat: failed to persist active agent', agentSlug, err );
	} );
}

function dispatchResponseMetadata( metadata: Record< string, unknown > ): void {
	window.dispatchEvent(
		new CustomEvent( 'frontend-agent-chat:response-metadata', {
			detail: { metadata },
		} )
	);
}

function dispatchLifecycleEvent( phase: string, detail: Record< string, unknown > = {} ): void {
	window.dispatchEvent(
		new CustomEvent( 'frontend-agent-chat:lifecycle', {
			detail: {
				phase,
				...detail,
			},
		} )
	);
}

function dispatchRunEvent( event: ChatRunEvent, detail: Record< string, unknown > = {} ): void {
	window.dispatchEvent(
		new CustomEvent( 'frontend-agent-chat:run-event', {
			detail: {
				event,
				...detail,
			},
		} )
	);
	dispatchLifecycleEvent( `run:${ event.type }`, {
		...detail,
		event,
	} );
}

async function dispatchRunEvents( fetchFn: FetchFn, basePath: string, metadata: Record< string, unknown > ): Promise< void > {
	const runId = metadata.run_id ?? metadata.runId;
	const sessionId = metadata.session_id ?? metadata.sessionId;
	if ( typeof runId !== 'string' || ! runId || typeof sessionId !== 'string' || ! sessionId ) {
		return;
	}

	let cursor = '0';
	let hasMore = true;
	while ( hasMore ) {
		const separator = `${ basePath }/runs/${ encodeURIComponent( runId ) }/events`.includes( '?' ) ? '&' : '?';
		const response = await fetchFn( {
			path: `${ basePath }/runs/${ encodeURIComponent( runId ) }/events${ separator }session_id=${ encodeURIComponent( sessionId ) }&cursor=${ encodeURIComponent( cursor ) }`,
		} ) as RunEventsResponse;
		const data = response.data ?? {};
		for ( const event of data.events ?? [] ) {
			dispatchRunEvent( event, {
				run_id: runId,
				session_id: sessionId,
				status: data.status,
			} );
		}

		const nextCursor = data.cursor ?? cursor;
		hasMore = !! data.has_more && nextCursor !== cursor;
		cursor = nextCursor;
	}
}

/**
 * Upload a file to the WordPress Media Library.
 *
 * Uses the standard wp/v2/media endpoint via @wordpress/api-fetch,
 * which handles nonce auth automatically.
 *
 * @param file File to upload.
 * @return Uploaded media descriptor.
 */
const wpMediaUpload: MediaUploadFn = async ( file: File ) => {
	const formData = new FormData();
	formData.append( 'file', file );

	const media = await apiFetch( {
		path: '/wp/v2/media',
		method: 'POST',
		body: formData,
	} ) as { id: number; source_url: string };

	return {
		url: media.source_url,
		media_id: media.id,
	};
};

function renderDiffCard( group: ToolGroup ): ReactNode {
	const diff = parseDiffFromToolResult( group );
	if ( ! diff ) {
		return null;
	}

	return createElement( DiffCard, {
		diff,
		onAccept: ( actionId: string ) => resolvePendingAction( actionId, 'accepted' ),
		onReject: ( actionId: string ) => resolvePendingAction( actionId, 'rejected' ),
	} );
}

interface QuestionPayload {
	question?: string;
	choices?: QuestionChoice[];
	allow_freeform?: boolean;
	freeform_label?: string;
	freeform_placeholder?: string;
}

function parseJsonObject( value: string ): Record< string, unknown > | null {
	try {
		const parsed = JSON.parse( value );
		return parsed && typeof parsed === 'object' && ! Array.isArray( parsed )
			? parsed as Record< string, unknown >
			: null;
	} catch {
		return null;
	}
}

function asRecord( value: unknown ): Record< string, unknown > | null {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value as Record< string, unknown >
		: null;
}

function readString( source: Record< string, unknown >, keys: string[] ): string | undefined {
	for ( const key of keys ) {
		const value = source[ key ];
		if ( typeof value === 'string' && value.trim() ) {
			return value.trim();
		}
	}

	return undefined;
}

function readSourceCards( source: Record< string, unknown > ): SourceCardPayload[] {
	const rawSources = source.sources ?? source.citations ?? source.source_cards ?? source.sourceCards;
	const values = Array.isArray( rawSources ) ? rawSources : rawSources ? [ rawSources ] : [];

	return values
		.map( normalizeSourceCard )
		.filter( ( card ): card is SourceCardPayload => !! card );
}

function normalizeSourceCard( value: unknown ): SourceCardPayload | null {
	const source = typeof value === 'string' && value.trim()
		? { url: value.trim() }
		: asRecord( value );
	if ( ! source ) {
		return null;
	}

	const card: SourceCardPayload = {
		title: readString( source, [ 'title', 'source_title', 'sourceTitle', 'name', 'label' ] ),
		url: readString( source, [ 'url', 'source_url', 'sourceUrl', 'href', 'link' ] ),
		snippet: readString( source, [ 'snippet', 'excerpt', 'summary', 'text', 'content', 'quote' ] ),
		documentId: readString( source, [ 'document_id', 'documentId', 'doc_id', 'docId', 'document', 'id' ] ),
		chunkId: readString( source, [ 'chunk_id', 'chunkId', 'chunk', 'chunk_ref', 'chunkRef' ] ),
		accessibleLabel: readString( source, [ 'accessible_label', 'accessibleLabel', 'aria_label', 'ariaLabel' ] ),
	};

	return card.title || card.url || card.snippet ? card : null;
}

function sourceCardHref( url?: string ): string | undefined {
	if ( ! url ) {
		return undefined;
	}

	const trimmed = url.trim();
	if ( trimmed.startsWith( '/' ) || trimmed.startsWith( '#' ) ) {
		return trimmed;
	}

	try {
		const parsed = new URL( trimmed );
		return [ 'http:', 'https:' ].includes( parsed.protocol ) ? trimmed : undefined;
	} catch {
		return undefined;
	}
}

function sourceCardsFromToolGroup( group: ToolGroup ): SourceCardPayload[] {
	const sources = readSourceCards( group.parameters );
	const result = group.resultMessage ? parseJsonObject( group.resultMessage.content ) : null;
	if ( result ) {
		sources.push( ...readSourceCards( result ) );
		const nestedResult = asRecord( result.result );
		if ( nestedResult ) {
			sources.push( ...readSourceCards( nestedResult ) );
		}
		const nestedData = asRecord( result.data );
		if ( nestedData ) {
			sources.push( ...readSourceCards( nestedData ) );
		}
	}

	const seen = new Set< string >();
	return sources.filter( ( source ) => {
		const key = [ source.url, source.title, source.documentId, source.chunkId, source.snippet ].join( '\u0000' );
		if ( seen.has( key ) ) {
			return false;
		}

		seen.add( key );
		return true;
	} ).slice( 0, 8 );
}

function readNumber( source: Record< string, unknown >, keys: string[] ): number | undefined {
	for ( const key of keys ) {
		const value = source[ key ];
		if ( typeof value === 'number' && Number.isFinite( value ) ) {
			return value;
		}
	}

	return undefined;
}

function titleFromPhase( phase: string ): string {
	return phase
		.replace( /[-_]+/g, ' ' )
		.replace( /\b\w/g, ( match ) => match.toUpperCase() );
}

function normalizeArtifactStatus( status: unknown ): ArtifactPhaseStatus | null {
	if ( typeof status !== 'string' ) {
		return null;
	}

	const normalized = status.trim().toLowerCase();
	if ( [ 'pending', 'running', 'ready', 'completed', 'failed', 'retrying' ].includes( normalized ) ) {
		return normalized as ArtifactPhaseStatus;
	}

	return null;
}

function firstNestedRecord( source: Record< string, unknown >, keys: string[] ): Record< string, unknown > | null {
	for ( const key of keys ) {
		const record = asRecord( source[ key ] );
		if ( record ) {
			return record;
		}
	}

	return null;
}

function unwrapArtifactSource( source: Record< string, unknown > ): Record< string, unknown > {
	return firstNestedRecord( source, [ 'artifact_phase', 'artifactPhase', 'phase_metadata', 'phaseMetadata', 'artifact_status', 'artifactStatus' ] ) ?? source;
}

function collectArtifactSources( group: ToolGroup ): Record< string, unknown >[] {
	const sources: Record< string, unknown >[] = [ group.parameters ];
	const result = group.resultMessage ? parseJsonObject( group.resultMessage.content ) : null;
	if ( result ) {
		sources.push( result );
		const nestedResult = asRecord( result.result );
		if ( nestedResult ) {
			sources.push( nestedResult );
		}
		const nestedData = asRecord( result.data );
		if ( nestedData ) {
			sources.push( nestedData );
		}
	}

	return sources.map( unwrapArtifactSource );
}

function getArtifactSourceValue( sources: Record< string, unknown >[], keys: string[] ): unknown {
	for ( const source of sources ) {
		for ( const key of keys ) {
			if ( source[ key ] !== undefined && source[ key ] !== null ) {
				return source[ key ];
			}
		}
	}

	return undefined;
}

function readArtifactString( sources: Record< string, unknown >[], keys: string[] ): string | undefined {
	for ( const source of sources ) {
		const value = readString( source, keys );
		if ( value ) {
			return value;
		}
	}

	return undefined;
}

function readArtifactNumber( sources: Record< string, unknown >[], keys: string[] ): number | undefined {
	for ( const source of sources ) {
		const value = readNumber( source, keys );
		if ( value !== undefined ) {
			return value;
		}
	}

	return undefined;
}

function normalizeThumbnail( value: unknown ): ArtifactThumbnail | null {
	if ( typeof value === 'string' && value.trim() ) {
		return { url: value.trim() };
	}

	const record = asRecord( value );
	if ( ! record ) {
		return null;
	}

	const url = readString( record, [ 'thumbnail_url', 'thumbnailUrl', 'thumb_url', 'thumbUrl', 'url', 'src' ] );
	if ( ! url ) {
		return null;
	}

	return {
		url,
		alt: readString( record, [ 'alt', 'alt_text', 'altText', 'label', 'title' ] ),
	};
}

function collectArtifactThumbnails( sources: Record< string, unknown >[] ): ArtifactThumbnail[] {
	const thumbnails: ArtifactThumbnail[] = [];
	const thumbnailValue = getArtifactSourceValue( sources, [ 'thumbnails', 'thumbnail_urls', 'thumbnailUrls', 'assets', 'imported_assets', 'importedAssets' ] );
	let rawThumbnails: unknown[] = [];
	if ( Array.isArray( thumbnailValue ) ) {
		rawThumbnails = thumbnailValue;
	} else if ( thumbnailValue ) {
		rawThumbnails = [ thumbnailValue ];
	}

	for ( const value of rawThumbnails ) {
		const thumbnail = normalizeThumbnail( value );
		if ( thumbnail ) {
			thumbnails.push( thumbnail );
		}
	}

	return thumbnails.slice( 0, 4 );
}

function artifactDiagnosticsCount( sources: Record< string, unknown >[] ): number | undefined {
	const explicitCount = readArtifactNumber( sources, [ 'diagnostics_count', 'diagnosticsCount', 'diagnostic_count', 'diagnosticCount' ] );
	if ( explicitCount !== undefined ) {
		return explicitCount;
	}

	const diagnostics = getArtifactSourceValue( sources, [ 'diagnostics', 'issues', 'warnings' ] );
	if ( Array.isArray( diagnostics ) ) {
		return diagnostics.length;
	}

	const diagnosticsRecord = asRecord( diagnostics );
	return diagnosticsRecord ? Object.keys( diagnosticsRecord ).length : undefined;
}

function artifactErrorMessage( sources: Record< string, unknown >[] ): string | undefined {
	const explicitError = readArtifactString( sources, [ 'error', 'error_message', 'errorMessage', 'failure_reason', 'failureReason' ] );
	if ( explicitError ) {
		return explicitError;
	}

	for ( const source of sources ) {
		const error = asRecord( source.error );
		const message = error ? readString( error, [ 'message', 'detail' ] ) : undefined;
		if ( message ) {
			return message;
		}
	}

	return undefined;
}

function artifactPayloadFromToolGroup( group: ToolGroup ): ArtifactStatusPayload | null {
	const sources = collectArtifactSources( group );
	const phase = readArtifactString( sources, [ 'phase', 'artifact_phase', 'artifactPhase', 'step', 'stage' ] );
	let statusFromSuccess: ArtifactStatusPayload[ 'status' ] | null = null;
	if ( group.success === false ) {
		statusFromSuccess = 'failed';
	} else if ( group.success === true ) {
		statusFromSuccess = 'completed';
	}
	const status = normalizeArtifactStatus( getArtifactSourceValue( sources, [ 'status', 'state', 'phase_status', 'phaseStatus' ] ) )
		?? statusFromSuccess;

	if ( ! phase || ! status ) {
		return null;
	}

	const title = readArtifactString( sources, [ 'title', 'label', 'name' ] ) ?? titleFromPhase( phase );

	return {
		title,
		phase,
		status,
		description: readArtifactString( sources, [ 'description', 'message', 'summary', 'detail' ] ),
		diagnosticsCount: artifactDiagnosticsCount( sources ),
		previewUrl: readArtifactString( sources, [ 'preview_url', 'previewUrl', 'url' ] ),
		materializedUrl: readArtifactString( sources, [ 'materialized_url', 'materializedUrl', 'final_url', 'finalUrl', 'result_url', 'resultUrl' ] ),
		thumbnails: collectArtifactThumbnails( sources ),
		error: artifactErrorMessage( sources ),
	};
}

function normalizeQuestionChoice( value: unknown ): QuestionChoice | null {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	const choice = value as Record< string, unknown >;
	const label = typeof choice.label === 'string' ? choice.label.trim() : '';
	if ( ! label ) {
		return null;
	}

	return {
		label,
		message: typeof choice.message === 'string' ? choice.message : undefined,
		description: typeof choice.description === 'string' ? choice.description : undefined,
	};
}

function questionPayloadFromToolGroup( group: ToolGroup ): QuestionPayload | null {
	const result = group.resultMessage ? parseJsonObject( group.resultMessage.content ) : null;
	const source = result && typeof result.result === 'object' && ! Array.isArray( result.result )
		? result.result as Record< string, unknown >
		: result ?? group.parameters;
	const question = typeof source.question === 'string' ? source.question.trim() : '';
	if ( ! question ) {
		return null;
	}

	const choices = Array.isArray( source.choices )
		? source.choices.map( normalizeQuestionChoice ).filter( ( choice ): choice is QuestionChoice => !! choice )
		: [];

	return {
		question,
		choices,
		allow_freeform: source.allow_freeform !== false,
		freeform_label: typeof source.freeform_label === 'string' ? source.freeform_label : undefined,
		freeform_placeholder: typeof source.freeform_placeholder === 'string' ? source.freeform_placeholder : undefined,
	};
}

function renderQuestionCard( group: ToolGroup, context: ToolRendererContext ): ReactNode {
	const payload = questionPayloadFromToolGroup( group );
	if ( ! payload ) {
		return null;
	}

	return createElement( QuestionCard, {
		question: payload.question ?? '',
		choices: payload.choices,
		allowFreeform: false,
		freeformLabel: payload.freeform_label,
		freeformPlaceholder: payload.freeform_placeholder,
		disabled: context.isLoading,
		onSubmitAnswer: context.sendMessage,
	} );
}

function renderSourceCards( group: ToolGroup ): ReactNode {
	const sources = sourceCardsFromToolGroup( group );
	if ( sources.length === 0 ) {
		return null;
	}

	return createElement(
		'section',
		{ className: 'frontend-agent-chat__source-cards', 'aria-label': __( 'Sources', 'frontend-agent-chat' ) },
		createElement( 'div', { className: 'frontend-agent-chat__source-cards-title' }, __( 'Sources', 'frontend-agent-chat' ) ),
		sources.map( ( source, index ) => {
			const href = sourceCardHref( source.url );
			const title = source.title ?? source.url ?? sprintf(
				/* translators: %d: source index. */
				__( 'Source %d', 'frontend-agent-chat' ),
				index + 1
			);
			const accessibleLabel = source.accessibleLabel ?? sprintf(
				/* translators: %s: source title. */
				__( 'Open source: %s', 'frontend-agent-chat' ),
				title
			);

			return createElement(
				'article',
				{ key: `${ source.url ?? source.title ?? 'source' }-${ index }`, className: 'frontend-agent-chat__source-card' },
				createElement(
					'div',
					{ className: 'frontend-agent-chat__source-card-heading' },
					href ? createElement(
						'a',
						{
							className: 'frontend-agent-chat__source-card-link',
							href,
							target: href.startsWith( '#' ) || href.startsWith( '/' ) ? undefined : '_blank',
							rel: href.startsWith( '#' ) || href.startsWith( '/' ) ? undefined : 'noopener noreferrer',
							'aria-label': accessibleLabel,
						},
						title
					) : createElement( 'span', { className: 'frontend-agent-chat__source-card-title' }, title )
				),
				source.snippet && createElement( 'p', { className: 'frontend-agent-chat__source-card-snippet' }, source.snippet ),
				( source.documentId || source.chunkId ) && createElement(
					'dl',
					{ className: 'frontend-agent-chat__source-card-debug' },
					source.documentId && createElement( 'div', null,
						createElement( 'dt', null, __( 'Document', 'frontend-agent-chat' ) ),
						createElement( 'dd', null, source.documentId )
					),
					source.chunkId && createElement( 'div', null,
						createElement( 'dt', null, __( 'Chunk', 'frontend-agent-chat' ) ),
						createElement( 'dd', null, source.chunkId )
					)
				)
			);
		} )
	);
}

function artifactStatusLabel( status: ArtifactPhaseStatus ): string {
	switch ( status ) {
		case 'pending':
			return __( 'Pending', 'frontend-agent-chat' );
		case 'running':
			return __( 'Running', 'frontend-agent-chat' );
		case 'ready':
			return __( 'Ready', 'frontend-agent-chat' );
		case 'completed':
			return __( 'Completed', 'frontend-agent-chat' );
		case 'failed':
			return __( 'Failed', 'frontend-agent-chat' );
		case 'retrying':
			return __( 'Retrying', 'frontend-agent-chat' );
	}
}

function artifactStatusCopy( payload: ArtifactStatusPayload ): string {
	if ( payload.description ) {
		return payload.description;
	}

	switch ( payload.status ) {
		case 'pending':
			return __( 'Waiting to start this artifact phase.', 'frontend-agent-chat' );
		case 'running':
			return __( 'This artifact phase is in progress.', 'frontend-agent-chat' );
		case 'ready':
			return __( 'This artifact phase is ready.', 'frontend-agent-chat' );
		case 'completed':
			return __( 'This artifact phase completed successfully.', 'frontend-agent-chat' );
		case 'failed':
			return __( 'This artifact phase failed.', 'frontend-agent-chat' );
		case 'retrying':
			return __( 'Retrying this artifact phase.', 'frontend-agent-chat' );
	}
}

function renderArtifactStatusCard( group: ToolGroup ): ReactNode {
	const payload = artifactPayloadFromToolGroup( group );
	if ( ! payload ) {
		return createElement( ToolMessage, { group } );
	}

	const hasError = payload.status === 'failed';
	const linkUrl = payload.materializedUrl ?? payload.previewUrl;
	const linkLabel = payload.materializedUrl
		? __( 'Open result', 'frontend-agent-chat' )
		: __( 'Open preview', 'frontend-agent-chat' );

	return createElement(
		'div',
		{ className: `frontend-agent-chat__tool-card frontend-agent-chat__artifact-card is-${ payload.status }${ hasError ? ' has-error' : '' }` },
		createElement(
			'div',
			{ className: 'frontend-agent-chat__artifact-card-header' },
			createElement( 'div', null,
				createElement( 'div', { className: 'frontend-agent-chat__tool-card-title' }, payload.title ),
				createElement( 'div', { className: 'frontend-agent-chat__artifact-card-phase' }, payload.phase )
			),
			createElement( 'span', { className: 'frontend-agent-chat__artifact-card-status' }, artifactStatusLabel( payload.status ) )
		),
		createElement(
			'p',
			{ className: 'frontend-agent-chat__tool-card-copy' },
			artifactStatusCopy( payload )
		),
		payload.thumbnails.length > 0 && createElement(
			'div',
			{ className: 'frontend-agent-chat__artifact-card-thumbnails', 'aria-label': __( 'Imported assets', 'frontend-agent-chat' ) },
			payload.thumbnails.map( ( thumbnail, index ) => createElement( 'img', {
				key: `${ thumbnail.url }-${ index }`,
				src: thumbnail.url,
				alt: thumbnail.alt ?? '',
				loading: 'lazy',
			} ) )
		),
		( payload.diagnosticsCount !== undefined || linkUrl ) && createElement(
			'div',
			{ className: 'frontend-agent-chat__artifact-card-meta' },
			payload.diagnosticsCount !== undefined && createElement(
				'span',
				{ className: 'frontend-agent-chat__artifact-card-meta-item' },
				sprintf(
					/* translators: %d: number of diagnostics. */
					__( '%d diagnostics', 'frontend-agent-chat' ),
					payload.diagnosticsCount
				)
			),
			linkUrl && createElement(
				'a',
				{
					className: 'frontend-agent-chat__artifact-card-link',
					href: linkUrl,
					target: '_blank',
					rel: 'noreferrer',
				},
				linkLabel
			)
		),
		hasError && payload.error && createElement( 'p', { className: 'frontend-agent-chat__tool-card-error' }, payload.error )
	);
}

function renderExpandIcon( path: string, viewBox: string ): ReactNode {
	return createElement(
		'svg',
		{
			className: 'frontend-agent-chat__expand-icon',
			viewBox,
			width: 18,
			height: 18,
			'aria-hidden': true,
			focusable: false,
		},
		createElement( 'path', {
			d: path,
			fill: 'none',
			stroke: 'currentColor',
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			strokeWidth: 2,
		} )
	);
}

function renderFabIcon( icon: string, path: string, viewBox: string ): ReactNode {
	if ( path ) {
		return createElement(
			'svg',
			{
				className: 'frontend-agent-chat__fab-svg',
				viewBox,
				width: 22,
				height: 22,
				'aria-hidden': true,
				focusable: false,
			},
			createElement( 'path', { d: path, fill: 'currentColor' } )
		);
	}

	return '' !== icon
		? createElement( 'span', { className: 'frontend-agent-chat__fab-icon', 'aria-hidden': true }, icon )
		: null;
}

export default function AgentChat( {
	agentSlug,
	basePath,
	bootstrapPath = '/frontend-agent-chat/v1/bootstrap',
	agentsPath,
	agentName,
	agentDescription,
	fabLabel = __( 'Agent Chat', 'frontend-agent-chat' ),
	fabIcon = 'AI',
	fabIconPath = '',
	fabIconViewBox = '0 0 24 24',
	expandIconPath,
	collapseIconPath,
	expandIconViewBox = '0 0 24 24',
	layout = 'floating',
	isLoggedIn = false,
	loadingMessages = true,
	persistenceCta,
	messageSuggestions,
	capabilities,
}: AgentChatProps ) {
	const isInline = layout === 'inline';
	const [ isOpen, setIsOpen ] = useState( isInline );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ unreadCount, setUnreadCount ] = useState( 0 );
	const [ browserBootstrapReady, setBrowserBootstrapReady ] = useState( isLoggedIn );
	const [ browserBootstrapFailed, setBrowserBootstrapFailed ] = useState( false );
	const [ agents, setAgents ] = useState< AgentSummary[] >( () => agentSlug ? [ {
		slug: agentSlug,
		name: agentName,
		description: agentDescription,
	} ] : [] );
	const [ selectedAgentSlug, setSelectedAgentSlug ] = useState( agentSlug ?? '' );
	const metadata = useClientContextMetadata();
	const selectedAgent = useMemo(
		() => agents.find( ( agent ) => agent.slug === selectedAgentSlug ),
		[ agents, selectedAgentSlug ]
	);
	const activeAgentSlug = selectedAgent?.slug ?? '';
	const activeAgentName = selectedAgent?.name ?? agentName;
	const activeAgentDescription = selectedAgent?.description ?? agentDescription;
	const agentFetch = useMemo( () => createAgentFetch( activeAgentSlug ), [ activeAgentSlug ] );
	const runCapabilities = useMemo( () => createRunCapabilities( capabilities ), [ capabilities ] );
	const cancelRun = useMemo( () => createCancelRun( agentFetch, basePath ), [ agentFetch, basePath ] );
	const queueMessage = useMemo( () => createQueueMessage( agentFetch, wpMediaUpload, basePath ), [ agentFetch, basePath ] );
	const open = useCallback( () => setIsOpen( true ), [] );
	const close = useCallback( () => {
		if ( isInline ) {
			return;
		}

		setIsOpen( false );
		setIsExpanded( false );
	}, [ isInline ] );
	const toggleExpanded = useCallback( () => setIsExpanded( ( expanded ) => ! expanded ), [] );
	const switchAgent = useCallback( ( event: ChangeEvent< HTMLSelectElement > ) => {
		const nextAgentSlug = event.target.value;
		setSelectedAgentSlug( nextAgentSlug );
		persistActiveAgent( nextAgentSlug );
	}, [] );
	const handleMessage = useCallback( ( message: ChatMessage ) => {
		if ( message.role !== 'user' ) {
			return;
		}

		dispatchLifecycleEvent( 'message-submitted', {
			agent: activeAgentSlug,
			message_id: message.id,
			has_attachments: !! message.attachments?.length,
		} );
	}, [ activeAgentSlug ] );
	const handleError = useCallback( ( error: Error ) => {
		dispatchLifecycleEvent( 'error', {
			agent: activeAgentSlug,
			message: error.message,
		} );
	}, [ activeAgentSlug ] );
	const handleResponseMetadata = useCallback( ( responseMetadata: Record< string, unknown > ) => {
		dispatchLifecycleEvent( 'response-metadata', {
			agent: activeAgentSlug,
			metadata: responseMetadata,
		} );
		dispatchResponseMetadata( responseMetadata );
		if ( capabilities?.chat_run_events ) {
			dispatchRunEvents( agentFetch, basePath, responseMetadata ).catch( ( err: unknown ) => {
				// eslint-disable-next-line no-console
				console.error( 'AgentChat: failed to fetch chat run events', err );
			} );
		}
	}, [ activeAgentSlug, agentFetch, basePath, capabilities?.chat_run_events ] );

	useEffect( () => {
		if ( isInline ) {
			setIsOpen( true );
			setIsExpanded( false );
		}
	}, [ isInline ] );

	useEffect( () => {
		if ( isLoggedIn ) {
			setBrowserBootstrapReady( true );
			return;
		}

		bootstrapBrowserPrincipal( bootstrapPath )
			.then( ( ready ) => {
				setBrowserBootstrapReady( ready );
				setBrowserBootstrapFailed( ! ready );
			} )
			.catch( ( err: unknown ) => {
				setBrowserBootstrapReady( false );
				setBrowserBootstrapFailed( true );
				// eslint-disable-next-line no-console
				console.error( 'AgentChat: failed to bootstrap browser chat storage', err );
			} );
	}, [ bootstrapPath, isLoggedIn ] );

	useEffect( () => {
		apiFetch( { path: agentsPath } )
			.then( ( response ) => {
				const data = ( response as AgentsResponse ).data ?? {};
				const nextAgents = data.agents ?? [];
				if ( nextAgents.length === 0 ) {
					return;
				}

				setAgents( nextAgents );
				setSelectedAgentSlug( ( current ) => {
					if ( current && nextAgents.some( ( agent ) => agent.slug === current ) ) {
						return current;
					}

					const defaultAgentSlug = data.default_agent_slug ?? '';
					if ( defaultAgentSlug && nextAgents.some( ( agent ) => agent.slug === defaultAgentSlug ) ) {
						return defaultAgentSlug;
					}

					const preferredAgentSlug = data.active_agent_slug ?? '';
					if ( preferredAgentSlug && nextAgents.some( ( agent ) => agent.slug === preferredAgentSlug ) ) {
						return preferredAgentSlug;
					}

					return nextAgents[0].slug;
				} );
			} )
			.catch( ( err: unknown ) => {
				// eslint-disable-next-line no-console
				console.error( 'AgentChat: failed to load accessible agents', err );
			} );
	}, [ agentsPath ] );

	useEffect( () => {
		setUnreadCount( 0 );
	}, [ activeAgentSlug ] );

	// Escape exits expanded mode first, then closes the drawer.
	useEffect( () => {
		function handleKeyDown( e: KeyboardEvent ) {
			if ( isInline || e.key !== 'Escape' || ! isOpen ) {
				return;
			}

			if ( isExpanded ) {
				setIsExpanded( false );
				return;
			}

			setIsOpen( false );
		}
		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ isExpanded, isInline, isOpen ] );

	const toolRenderers = useMemo(
		() => ( {
			source_cards: renderSourceCards,
			sources: renderSourceCards,
			citations: renderSourceCards,
			artifact_phase: renderArtifactStatusCard,
			start_artifact_generation: renderArtifactStatusCard,
			artifact_status: renderArtifactStatusCard,
			artifact_status_update: renderArtifactStatusCard,
			artifact_task_status: renderArtifactStatusCard,
			edit_post_blocks: renderDiffCard,
			replace_post_blocks: renderDiffCard,
			insert_content: renderDiffCard,
			present_question: renderQuestionCard,
		} ),
		[]
	);
	const hasPersistenceCta = !! (
		persistenceCta?.message ||
		( persistenceCta?.actionUrl && persistenceCta?.actionLabel )
	);
	const persistenceMessage = browserBootstrapFailed
		? __( 'Chat works, but this browser is blocking secure chat-history cookies.', 'frontend-agent-chat' )
		: persistenceCta?.message;
	const chatStorageReady = isLoggedIn || browserBootstrapReady;
	const expandedButtonLabel = isExpanded
		? __( 'Exit expanded chat view', 'frontend-agent-chat' )
		: __( 'Expand chat to viewport', 'frontend-agent-chat' );
	const expandButtonIconPath = isExpanded
		? ( collapseIconPath || DEFAULT_COLLAPSE_ICON_PATH )
		: ( expandIconPath || DEFAULT_EXPAND_ICON_PATH );

	return createElement(
		'div',
		{ className: `frontend-agent-chat is-${ layout }` },
		! isInline && createElement(
			'button',
			{
				type: 'button',
				className: `frontend-agent-chat__fab${ isOpen ? ' is-hidden' : '' }`,
				onClick: open,
				'aria-label': sprintf(
					/* translators: %s: agent name. */
					__( 'Open %s chat', 'frontend-agent-chat' ),
					activeAgentName
				),
			},
			renderFabIcon( fabIcon, fabIconPath, fabIconViewBox ),
			createElement( 'span', { className: 'frontend-agent-chat__fab-label' }, fabLabel ),
			unreadCount > 0 &&
				createElement(
					'span',
					{ className: 'frontend-agent-chat__fab-badge' },
					unreadCount > 99 ? '99+' : unreadCount
				)
		),
		createElement(
			'div',
			{
				className: `frontend-agent-chat__drawer${ isOpen ? ' is-open' : '' }${ isExpanded ? ' is-expanded' : '' }${ isInline ? ' is-inline' : '' }`,
				'aria-hidden': ! isOpen,
			},
			createElement(
				'div',
				{ className: 'frontend-agent-chat__header' },
				! isInline && createElement(
					'div',
					{ className: 'frontend-agent-chat__agent' },
					agents.length > 1 ? createElement(
						'select',
						{
							className: 'frontend-agent-chat__agent-select',
							value: activeAgentSlug,
							onChange: switchAgent,
							'aria-label': __( 'Select chat agent', 'frontend-agent-chat' ),
						},
						agents.map( ( agent ) => createElement(
							'option',
							{ key: agent.slug, value: agent.slug },
							agent.name
						) )
					) : createElement(
						'span',
						{ className: 'frontend-agent-chat__title' },
						activeAgentName
					)
				),
				createElement(
					'div',
					{ className: 'frontend-agent-chat__header-actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'frontend-agent-chat__expand',
							onClick: toggleExpanded,
							'aria-label': expandedButtonLabel,
							'aria-pressed': isExpanded,
						},
						renderExpandIcon( expandButtonIconPath, expandIconViewBox )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'frontend-agent-chat__close',
							onClick: close,
							'aria-label': __( 'Close', 'frontend-agent-chat' ),
						},
						'\u00D7'
					)
				)
			),
			createElement(
				'div',
				{ className: 'frontend-agent-chat__body' },
				! isLoggedIn && ( browserBootstrapFailed || hasPersistenceCta ) && createElement(
					'div',
					{ className: `frontend-agent-chat__persistence${ browserBootstrapFailed ? ' has-warning' : '' }` },
					persistenceMessage,
					! browserBootstrapFailed && persistenceCta?.actionUrl && persistenceCta?.actionLabel && createElement(
						'a',
						{
							className: 'frontend-agent-chat__persistence-action',
							href: persistenceCta.actionUrl,
						},
						persistenceCta.actionLabel
					)
				),
				activeAgentSlug && chatStorageReady && createElement( Chat, {
					key: activeAgentSlug,
					basePath,
					fetchFn: agentFetch,
					showTools: true,
					showSessions: true,
					toolRenderers,
					placeholder: sprintf(
						/* translators: %s: agent name. */
						__( 'Ask %s anything…', 'frontend-agent-chat' ),
						activeAgentName
					),
					metadata,
					onMessage: handleMessage,
					onError: handleError,
					onResponseMetadata: handleResponseMetadata,
					isVisible: isOpen,
					onUnreadChange: setUnreadCount,
					emptyState: createElement(
						'div',
						{ className: 'frontend-agent-chat__empty' },
						createElement( 'h3', null, activeAgentName ),
						createElement( 'p', null, activeAgentDescription )
					),
					messageSuggestions,
					messageSuggestionsLabel: __( 'Try asking', 'frontend-agent-chat' ),
					loadingMessages,
					mediaUploadFn: wpMediaUpload,
					runCapabilities,
					getRunId,
					onCancelRun: cancelRun,
					onQueueMessage: queueMessage,
					cancelLabel: __( 'Stop', 'frontend-agent-chat' ),
					processingLabel: ( turnCount: number ) =>
						sprintf(
							/* translators: %d: processing turn count. */
							__( 'Working… (turn %d)', 'frontend-agent-chat' ),
							turnCount
						),
				} )
			)
		)
	);
}
