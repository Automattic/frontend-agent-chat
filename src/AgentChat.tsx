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
	createAgentsApiChatAdapter,
	createPresentQuestionToolRenderers,
	renderToolGroups,
	useAgentsApiChat,
	normalizeRunEvent,
} from '@automattic/agenttic-client/agents-api';
import type {
	AgentsApiFetch as FetchFn,
	AgentsApiMediaUpload as MediaUploadFn,
	AgentsApiMessage,
	AgentsApiQueueMessageResult as QueueMessageResult,
	AgentsApiRunAdapter as ChatRunAdapter,
	AgentsApiRunCapabilities as ChatRunCapabilities,
	AgentsApiRunEvent as ChatRunEvent,
	AgentsApiToolRenderers,
	AgentsApiToolGroup,
} from '@automattic/agenttic-client/agents-api';
import { AgentUI, QuestionCard } from '@automattic/agenttic-ui/embedded-agent-ui';
import type { Suggestion as ChatMessageSuggestion } from '@automattic/agenttic-ui/embedded-agent-ui';
import type { ChangeEvent, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import {
	createElement,
	useState,
	useCallback,
	useMemo,
	useEffect,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	getOperatorDiagnosticsPanel,
	shouldRenderOperatorDiagnostics,
} from './operator-diagnostics';
import { getRetrievalState } from './retrieval-state';
import type { RetrievalState } from './retrieval-state';

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
	loadingMessages?:
		| boolean
		| {
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
	chatContext?: Record< string, unknown >;
	canUploadFiles?: boolean;
	capabilities?: {
		chat_run_status?: boolean;
		chat_run_cancel?: boolean;
		chat_message_queue?: boolean;
		chat_run_events?: boolean;
		operator_diagnostics?: boolean;
	};
	operatorDiagnosticsEnabled?: boolean;
}

interface BootstrapResponse {
	success?: boolean;
	data?: {
		authenticated?: boolean;
		browser_principal_ready?: boolean;
		had_browser_principal?: boolean;
		session_persistence_scope?: 'user' | 'browser';
		capabilities?: AgentChatProps[ 'capabilities' ];
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

interface RunEventsResponse {
	success?: boolean;
	data?: {
		run_id?: string;
		session_id?: string;
		status?: string;
		events?: Record< string, unknown >[];
		cursor?: string;
		has_more?: boolean;
	};
}

type ArtifactStatus =
	| 'pending'
	| 'running'
	| 'ready'
	| 'completed'
	| 'failed'
	| 'retrying';

interface ArtifactStatusPayload {
	title: string;
	phase: string;
	status: ArtifactStatus;
	description?: string;
	previewUrl?: string;
	resultUrl?: string;
	diagnosticsCount?: number;
	error?: string;
	thumbnails: Array< { url: string; alt?: string } >;
}

function getToolPayload( group: AgentsApiToolGroup ): Record< string, unknown > {
	const result = group.result?.result;
	if ( result && typeof result === 'object' ) {
		return result as Record< string, unknown >;
	}
	const raw = group.result?.message?.raw ?? group.call?.message?.raw ?? {};
	return raw && typeof raw === 'object' ? raw : {};
}

function parseArtifactStatusPayload(
	group: AgentsApiToolGroup
): ArtifactStatusPayload | null {
	const payload = getToolPayload( group );
	const status = payload.status;
	if (
		status !== 'pending' &&
		status !== 'running' &&
		status !== 'ready' &&
		status !== 'completed' &&
		status !== 'failed' &&
		status !== 'retrying'
	) {
		return null;
	}

	const thumbnails = Array.isArray( payload.thumbnails )
		? payload.thumbnails
				.filter(
					( thumbnail ): thumbnail is Record< string, unknown > =>
						!! thumbnail && typeof thumbnail === 'object'
				)
				.map( ( thumbnail ) => ( {
					url: String( thumbnail.url ?? '' ),
					alt:
						typeof thumbnail.alt === 'string' ? thumbnail.alt : undefined,
				} ) )
				.filter( ( thumbnail ) => thumbnail.url )
		: [];

	return {
		title: String( payload.title ?? group.name ),
		phase: String( payload.phase ?? group.name ),
		status,
		description:
			typeof payload.description === 'string'
				? payload.description
				: undefined,
		previewUrl:
			typeof payload.preview_url === 'string'
				? payload.preview_url
				: typeof payload.previewUrl === 'string'
				? payload.previewUrl
				: undefined,
		resultUrl:
			typeof payload.result_url === 'string'
				? payload.result_url
				: typeof payload.resultUrl === 'string'
				? payload.resultUrl
				: undefined,
		diagnosticsCount:
			typeof payload.diagnostics_count === 'number'
				? payload.diagnostics_count
				: typeof payload.diagnosticsCount === 'number'
				? payload.diagnosticsCount
				: undefined,
		error: typeof payload.error === 'string' ? payload.error : undefined,
		thumbnails,
	};
}

async function bootstrapBrowserPrincipal(
	bootstrapPath: string
): Promise< boolean > {
	const response = ( await apiFetch( {
		path: bootstrapPath,
		method: 'POST',
	} ) ) as BootstrapResponse;
	const data = response.data ?? {};
	if ( data.authenticated || data.had_browser_principal ) {
		return data.browser_principal_ready !== false;
	}

	const verificationResponse = ( await apiFetch( {
		path: bootstrapPath,
		method: 'POST',
	} ) ) as BootstrapResponse;
	const verificationData = verificationResponse.data ?? {};
	return (
		verificationData.browser_principal_ready !== false &&
		verificationData.had_browser_principal === true
	);
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

const DEFAULT_EXPAND_ICON_PATH = 'M3 8V3h5M21 8V3h-5M3 16v5h5M21 16v5h-5';
const DEFAULT_COLLAPSE_ICON_PATH = 'M8 3v5H3M16 3v5h5M8 21v-5H3M16 21v-5h5';

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
function resolvePendingAction(
	actionId: string,
	decision: 'accepted' | 'rejected'
): void {
	apiFetch( {
		path: '/frontend-agent-chat/v1/chat/actions/resolve',
		method: 'POST',
		data: { action_id: actionId, decision },
	} ).catch( ( err: unknown ) => {
		// eslint-disable-next-line no-console
		console.error(
			'AgentChat: failed to resolve pending action',
			actionId,
			err
		);
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

function createAgentFetch(
	agentSlug: string,
	chatContext?: Record< string, unknown >
): FetchFn {
	return ( options ) => {
		const method = options.method ?? 'GET';
		const separator = options.path.includes( '?' ) ? '&' : '?';
		const hasAgentParam = /[?&]agent=/.test( options.path );
		const data =
			options.data &&
			typeof options.data === 'object' &&
			! Array.isArray( options.data )
				? ( options.data as Record< string, unknown > )
				: {};

		return apiFetch( {
			path:
				( method === 'GET' || method === 'DELETE' ) && ! hasAgentParam
					? `${
							options.path
					  }${ separator }agent=${ encodeURIComponent( agentSlug ) }`
					: options.path,
			method: options.method,
			data:
				method === 'POST'
					? {
							...getPageContext(),
							...data,
							agent: agentSlug,
							client_context: {
								...( data.client_context &&
								typeof data.client_context === 'object' &&
								! Array.isArray( data.client_context )
									? ( data.client_context as Record< string, unknown > )
									: {} ),
								...( chatContext ?? {} ),
							},
						}
					: options.data,
			headers: options.headers,
		} );
	};
}

function createRunCapabilities(
	capabilities?: AgentChatProps[ 'capabilities' ]
): ChatRunCapabilities {
	return {
		cancel: !! capabilities?.chat_run_cancel,
		queue: !! capabilities?.chat_message_queue,
		status: !! capabilities?.chat_run_status,
		events: !! capabilities?.chat_run_events,
	};
}

function getRunId( metadata: Record< string, unknown > ): string | null {
	const runId = metadata.run_id ?? metadata.runId;
	return typeof runId === 'string' && runId.trim() ? runId : null;
}

function normalizeQueueResult(
	response: RunControlResponse
): QueueMessageResult {
	const data = response.data ?? {};
	return {
		queuedMessageId: data.queued_message_id,
		runId: data.run_id,
		sessionId: data.session_id,
		status: data.status as QueueMessageResult[ 'status' ],
		startedAt: data.started_at,
		updatedAt: data.updated_at,
		metadata: data.metadata,
		position: data.position,
	};
}

function getSessionLabel(
	session: { title?: string; updatedAt?: string; id: string },
	index: number
): string {
	if ( session.title ) {
		return session.title;
	}
	if ( session.updatedAt ) {
		return sprintf(
			/* translators: %s: formatted date/time. */
			__( 'Chat from %s', 'frontend-agent-chat' ),
			new Date( session.updatedAt ).toLocaleString()
		);
	}
	return sprintf(
		/* translators: %d: chat session number. */
		__( 'Chat %d', 'frontend-agent-chat' ),
		index + 1
	);
}

function createFrontendRunAdapter(
	fetchFn: FetchFn,
	uploadFn: MediaUploadFn,
	basePath: string,
	capabilities?: AgentChatProps[ 'capabilities' ]
): ChatRunAdapter {
	return {
		capabilities: createRunCapabilities( capabilities ),
		getRunId,
		async cancel( input ) {
			await fetchFn( {
				path: `${ basePath }/runs/${ encodeURIComponent(
					input.runId
				) }/cancel`,
				method: 'POST',
				data: { session_id: input.sessionId },
			} );
		},
		async queue( input ) {
			const attachments = input.attachments?.length
				? input.attachments
				: input.files?.length
				? await Promise.all(
						input.files.map( async ( file ) => {
							const uploaded = await uploadFn( file );
							return {
								filename: file.name,
								mime_type: file.type,
								url: uploaded.url,
								media_id: uploaded.media_id,
							};
						} )
				  )
				: [];

			const response = ( await fetchFn( {
				path: `${ basePath }/queue`,
				method: 'POST',
				data: {
					session_id: input.sessionId,
					run_id: input.runId,
					message: input.content,
					attachments,
				},
			} ) ) as RunControlResponse;

			return normalizeQueueResult( response );
		},
		async listEvents( input ) {
			let cursor = '0';
			let hasMore = true;
			const events: ChatRunEvent[] = [];
			while ( hasMore ) {
				const response = ( await fetchFn( {
					path: `${ basePath }/runs/${ encodeURIComponent(
						input.runId
					) }/events?session_id=${ encodeURIComponent(
						input.sessionId
					) }&cursor=${ encodeURIComponent( cursor ) }`,
				} ) ) as RunEventsResponse;
				const data = response.data ?? {};
				for ( const event of data.events ?? [] ) {
					const normalized = normalizeRunEvent(
						{
							...event,
							run_id: input.runId,
							session_id: input.sessionId,
							status: data.status,
						},
						input.runId
					);
					if ( normalized ) {
						events.push( normalized );
					}
				}

				const nextCursor = data.cursor ?? cursor;
				hasMore = !! data.has_more && nextCursor !== cursor;
				cursor = nextCursor;
			}

			return events;
		},
	};
}

function persistActiveAgent( agentSlug: string ): void {
	apiFetch( {
		path: '/frontend-agent-chat/v1/agents/active',
		method: 'POST',
		data: { agent: agentSlug },
	} ).catch( ( err: unknown ) => {
		// eslint-disable-next-line no-console
		console.error(
			'AgentChat: failed to persist active agent',
			agentSlug,
			err
		);
	} );
}

function dispatchResponseMetadata( metadata: Record< string, unknown > ): void {
	window.dispatchEvent(
		new CustomEvent( 'frontend-agent-chat:response-metadata', {
			detail: { metadata },
		} )
	);
}

function dispatchLifecycleEvent(
	phase: string,
	detail: Record< string, unknown > = {}
): void {
	window.dispatchEvent(
		new CustomEvent( 'frontend-agent-chat:lifecycle', {
			detail: {
				phase,
				...detail,
			},
		} )
	);
}

function dispatchRunEvent(
	event: ChatRunEvent,
	detail: Record< string, unknown > = {}
): void {
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

async function dispatchRunEvents(
	runAdapter: ChatRunAdapter,
	metadata: Record< string, unknown >
): Promise< void > {
	const runId = metadata.run_id ?? metadata.runId;
	const sessionId = metadata.session_id ?? metadata.sessionId;
	if (
		! runAdapter.listEvents ||
		typeof runId !== 'string' ||
		! runId ||
		typeof sessionId !== 'string' ||
		! sessionId
	) {
		return;
	}

	const events = await runAdapter.listEvents( { runId, sessionId } );
	for ( const event of events ) {
		dispatchRunEvent( event, {
			run_id: runId,
			session_id: sessionId,
			status: event.status,
		} );
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

	const media = ( await apiFetch( {
		path: '/wp/v2/media',
		method: 'POST',
		body: formData,
	} ) ) as { id: number; source_url: string };

	return {
		url: media.source_url,
		media_id: media.id,
	};
};

function artifactStatusLabel( status: ArtifactStatus ): string {
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
			return __(
				'Waiting to start this artifact phase.',
				'frontend-agent-chat'
			);
		case 'running':
			return __(
				'This artifact phase is in progress.',
				'frontend-agent-chat'
			);
		case 'ready':
			return __( 'This artifact phase is ready.', 'frontend-agent-chat' );
		case 'completed':
			return __(
				'This artifact phase completed successfully.',
				'frontend-agent-chat'
			);
		case 'failed':
			return __( 'This artifact phase failed.', 'frontend-agent-chat' );
		case 'retrying':
			return __( 'Retrying this artifact phase.', 'frontend-agent-chat' );
	}
}

function renderArtifactStatusPayload(
	payload: ArtifactStatusPayload
): ReactNode {
	const hasError = payload.status === 'failed';
	const linkUrl = payload.resultUrl ?? payload.previewUrl;
	const linkLabel = payload.resultUrl
		? __( 'Open result', 'frontend-agent-chat' )
		: __( 'Open preview', 'frontend-agent-chat' );

	return createElement(
		'div',
		{
			className: `frontend-agent-chat__tool-card frontend-agent-chat__artifact-card is-${
				payload.status
			}${ hasError ? ' has-error' : '' }`,
		},
		createElement(
			'div',
			{ className: 'frontend-agent-chat__artifact-card-header' },
			createElement(
				'div',
				null,
				createElement(
					'div',
					{ className: 'frontend-agent-chat__tool-card-title' },
					payload.title
				),
				createElement(
					'div',
					{ className: 'frontend-agent-chat__artifact-card-phase' },
					payload.phase
				)
			),
			createElement(
				'span',
				{ className: 'frontend-agent-chat__artifact-card-status' },
				artifactStatusLabel( payload.status )
			)
		),
		createElement(
			'p',
			{ className: 'frontend-agent-chat__tool-card-copy' },
			artifactStatusCopy( payload )
		),
		payload.thumbnails.length > 0 &&
			createElement(
				'div',
				{
					className: 'frontend-agent-chat__artifact-card-thumbnails',
					'aria-label': __(
						'Imported assets',
						'frontend-agent-chat'
					),
				},
				payload.thumbnails.map( ( thumbnail, index ) =>
					createElement( 'img', {
						key: `${ thumbnail.url }-${ index }`,
						src: thumbnail.url,
						alt: thumbnail.alt ?? '',
						loading: 'lazy',
					} )
				)
			),
		( payload.diagnosticsCount !== undefined || linkUrl ) &&
			createElement(
				'div',
				{ className: 'frontend-agent-chat__artifact-card-meta' },
				payload.diagnosticsCount !== undefined &&
					createElement(
						'span',
						{
							className:
								'frontend-agent-chat__artifact-card-meta-item',
						},
						sprintf(
							/* translators: %d: number of diagnostics. */
							__( '%d diagnostics', 'frontend-agent-chat' ),
							payload.diagnosticsCount
						)
					),
				linkUrl &&
					createElement(
						'a',
						{
							className:
								'frontend-agent-chat__artifact-card-link',
							href: linkUrl,
							target: '_blank',
							rel: 'noreferrer',
						},
						linkLabel
					)
			),
		hasError &&
			payload.error &&
			createElement(
				'p',
				{ className: 'frontend-agent-chat__tool-card-error' },
				payload.error
			)
	);
}

function renderOperatorDiagnosticsPanel(
	metadata: Record< string, unknown > | null
): ReactNode {
	if ( ! metadata ) {
		return null;
	}

	const panel = getOperatorDiagnosticsPanel( metadata );
	if ( ! panel ) {
		return null;
	}

	return createElement(
		'details',
		{ className: 'frontend-agent-chat__operator-diagnostics' },
		createElement( 'summary', null, panel.title ),
		createElement(
			'dl',
			null,
			panel.rows.map( ( row ) =>
				createElement(
					'div',
					{
						key: row.label,
						className:
							'frontend-agent-chat__operator-diagnostics-row',
					},
					createElement( 'dt', null, row.label ),
					createElement( 'dd', null, row.value )
				)
			)
		)
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

function renderFabIcon(
	icon: string,
	path: string,
	viewBox: string
): ReactNode {
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
		? createElement(
				'span',
				{
					className: 'frontend-agent-chat__fab-icon',
					'aria-hidden': true,
				},
				icon
		  )
		: null;
}

function renderRetrievalState( state: RetrievalState | null ): ReactNode {
	if ( ! state ) {
		return null;
	}

	return createElement(
		'div',
		{
			className: `frontend-agent-chat__retrieval-state is-${ state.kind.replace(
				'_',
				'-'
			) }`,
			role: state.kind === 'error' ? 'status' : undefined,
		},
		createElement(
			'span',
			{ className: 'frontend-agent-chat__retrieval-state-label' },
			state.label
		),
		createElement(
			'span',
			{ className: 'frontend-agent-chat__retrieval-state-description' },
			state.description
		)
	);
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
	chatContext,
	canUploadFiles = false,
	capabilities,
	operatorDiagnosticsEnabled,
}: AgentChatProps ) {
	const isInline = layout === 'inline';
	const [ isOpen, setIsOpen ] = useState( isInline );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ unreadCount, setUnreadCount ] = useState( 0 );
	const [ browserBootstrapReady, setBrowserBootstrapReady ] =
		useState( isLoggedIn );
	const [ browserBootstrapFailed, setBrowserBootstrapFailed ] =
		useState( false );
	const [ operatorDiagnosticsMetadata, setOperatorDiagnosticsMetadata ] =
		useState< Record< string, unknown > | null >( null );
	const [ retrievalState, setRetrievalState ] =
		useState< RetrievalState | null >( null );
	const [ agents, setAgents ] = useState< AgentSummary[] >( () =>
		agentSlug
			? [
					{
						slug: agentSlug,
						name: agentName,
						description: agentDescription,
					},
			  ]
			: []
	);
	const [ selectedAgentSlug, setSelectedAgentSlug ] = useState(
		agentSlug ?? ''
	);
	const selectedAgent = useMemo(
		() => agents.find( ( agent ) => agent.slug === selectedAgentSlug ),
		[ agents, selectedAgentSlug ]
	);
	const activeAgentSlug = selectedAgent?.slug ?? '';
	const activeAgentName = selectedAgent?.name ?? agentName;
	const activeAgentDescription =
		selectedAgent?.description ?? agentDescription;
	const agentFetch = useMemo(
		() => createAgentFetch( activeAgentSlug, chatContext ),
		[ activeAgentSlug, chatContext ]
	);
	const chatStorageReady = isLoggedIn || browserBootstrapReady;
	const canShowOperatorDiagnostics =
		!! operatorDiagnosticsEnabled || !! capabilities?.operator_diagnostics;
	const runAdapter = useMemo(
		() =>
			createFrontendRunAdapter(
				agentFetch,
				wpMediaUpload,
				basePath,
				capabilities
			),
		[ agentFetch, basePath, capabilities ]
	);
	const open = useCallback( () => setIsOpen( true ), [] );
	const close = useCallback( () => {
		if ( isInline ) {
			return;
		}

		setIsOpen( false );
		setIsExpanded( false );
	}, [ isInline ] );
	const toggleExpanded = useCallback(
		() => setIsExpanded( ( expanded ) => ! expanded ),
		[]
	);
	const switchAgent = useCallback(
		( event: ChangeEvent< HTMLSelectElement > ) => {
			const nextAgentSlug = event.target.value;
			setSelectedAgentSlug( nextAgentSlug );
			persistActiveAgent( nextAgentSlug );
		},
		[]
	);
	const handleMessage = useCallback(
		( message: AgentsApiMessage ) => {
			if ( message.role !== 'user' ) {
				return;
			}

			setRetrievalState( null );

			dispatchLifecycleEvent( 'message-submitted', {
				agent: activeAgentSlug,
				message_id: message.id,
				has_attachments: !! message.attachments?.length,
			} );
		},
		[ activeAgentSlug ]
	);
	const handleError = useCallback(
		( error: Error ) => {
			dispatchLifecycleEvent( 'error', {
				agent: activeAgentSlug,
				message: error.message,
			} );
		},
		[ activeAgentSlug ]
	);
	const handleResponseMetadata = useCallback(
		( responseMetadata: Record< string, unknown > ) => {
			setRetrievalState( getRetrievalState( responseMetadata ) );
			dispatchLifecycleEvent( 'response-metadata', {
				agent: activeAgentSlug,
				metadata: responseMetadata,
			} );
			dispatchResponseMetadata( responseMetadata );
			setOperatorDiagnosticsMetadata(
				shouldRenderOperatorDiagnostics(
					canShowOperatorDiagnostics,
					responseMetadata
				)
					? responseMetadata
					: null
			);
			if ( capabilities?.chat_run_events ) {
				dispatchRunEvents( runAdapter, responseMetadata ).catch(
					( err: unknown ) => {
						// eslint-disable-next-line no-console
						console.error(
							'AgentChat: failed to fetch chat run events',
							err
						);
					}
				);
			}
		},
		[
			activeAgentSlug,
			canShowOperatorDiagnostics,
			capabilities?.chat_run_events,
			runAdapter,
		]
	);

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
				console.error(
					'AgentChat: failed to bootstrap browser chat storage',
					err
				);
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
					if (
						current &&
						nextAgents.some( ( agent ) => agent.slug === current )
					) {
						return current;
					}

					const defaultAgentSlug = data.default_agent_slug ?? '';
					if (
						defaultAgentSlug &&
						nextAgents.some(
							( agent ) => agent.slug === defaultAgentSlug
						)
					) {
						return defaultAgentSlug;
					}

					const preferredAgentSlug = data.active_agent_slug ?? '';
					if (
						preferredAgentSlug &&
						nextAgents.some(
							( agent ) => agent.slug === preferredAgentSlug
						)
					) {
						return preferredAgentSlug;
					}

					return nextAgents[ 0 ].slug;
				} );
			} )
			.catch( ( err: unknown ) => {
				// eslint-disable-next-line no-console
				console.error(
					'AgentChat: failed to load accessible agents',
					err
				);
			} );
	}, [ agentsPath ] );

	useEffect( () => {
		setUnreadCount( 0 );
		setRetrievalState( null );
		setOperatorDiagnosticsMetadata( null );
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

	const emptyView = useMemo(
		() =>
			createElement(
				'div',
				{ className: 'frontend-agent-chat__empty' },
				createElement( 'h3', null, activeAgentName ),
				createElement( 'p', null, activeAgentDescription )
			),
		[ activeAgentDescription, activeAgentName ]
	);
	const acceptedFileTypes = useMemo(
		() => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
		[]
	);
	const thinkingMessage = useMemo( () => {
		if ( loadingMessages === false ) {
			return undefined;
		}
		if (
			typeof loadingMessages === 'object' &&
			loadingMessages.messages?.length
		) {
			return loadingMessages.messages[ 0 ];
		}
		return __( 'Working…', 'frontend-agent-chat' );
	}, [ loadingMessages ] );
	const renderChatHeader = useCallback( () => {
		const retrievalStateNode = renderRetrievalState( retrievalState );
		const operatorDiagnosticsNode = canShowOperatorDiagnostics
			? renderOperatorDiagnosticsPanel( operatorDiagnosticsMetadata )
			: null;

		if ( ! retrievalStateNode && ! operatorDiagnosticsNode ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'frontend-agent-chat__chat-header' },
			retrievalStateNode,
			operatorDiagnosticsNode
		);
	}, [
		canShowOperatorDiagnostics,
		operatorDiagnosticsMetadata,
		retrievalState,
	] );
	const chatAdapter = useMemo(
		() =>
			createAgentsApiChatAdapter( {
				agent: activeAgentSlug,
				basePath,
				fetchFn: agentFetch,
			} ),
		[ activeAgentSlug, agentFetch, basePath ]
	);
	const chat = useAgentsApiChat( {
		adapter: chatAdapter,
		mediaUploadFn: canUploadFiles ? wpMediaUpload : undefined,
		runAdapter,
		getRunId,
		onMessage: handleMessage,
		onError: handleError,
		onResponseMetadata: handleResponseMetadata,
		onUnreadChange: setUnreadCount,
		isVisible: isOpen && !! activeAgentSlug && chatStorageReady,
	} );
	const toolRenderers = useMemo< AgentsApiToolRenderers >( () => {
		const artifactRenderer = ( group: AgentsApiToolGroup ) => {
			const payload = parseArtifactStatusPayload( group );
			return payload
				? renderArtifactStatusPayload( payload )
				: createElement(
						'pre',
						{ className: 'frontend-agent-chat__tool-card' },
						JSON.stringify( getToolPayload( group ), null, 2 )
				  );
		};
		const diffRenderer = ( group: AgentsApiToolGroup ) => {
			const payload = getToolPayload( group );
			const actionId = String(
				payload.action_id ?? payload.actionId ?? payload.pending_action_id ?? ''
			);
			return createElement(
				'div',
				{ className: 'frontend-agent-chat__tool-card' },
				createElement(
					'div',
					{ className: 'frontend-agent-chat__tool-card-title' },
					String( payload.title ?? group.name )
				),
				createElement(
					'pre',
					null,
					JSON.stringify( payload.diff ?? payload, null, 2 )
				),
				actionId &&
					createElement(
						'div',
						{ className: 'frontend-agent-chat__tool-card-actions' },
						createElement(
							'button',
							{
								type: 'button',
								onClick: () =>
									resolvePendingAction( actionId, 'accepted' ),
							},
							__( 'Accept', 'frontend-agent-chat' )
						),
						createElement(
							'button',
							{
								type: 'button',
								onClick: () =>
									resolvePendingAction( actionId, 'rejected' ),
							},
							__( 'Reject', 'frontend-agent-chat' )
						)
					)
			);
		};
		const questionRenderers = createPresentQuestionToolRenderers( {
			QuestionCard,
			disabled: () => chat.isProcessing,
			onAnswer: ( answer ) => chat.sendMessage( answer ),
		} );

		return {
			artifact_phase: artifactRenderer,
			start_artifact_generation: artifactRenderer,
			artifact_status: artifactRenderer,
			artifact_status_update: artifactRenderer,
			artifact_task_status: artifactRenderer,
			edit_post_blocks: diffRenderer,
			replace_post_blocks: diffRenderer,
			insert_content: diffRenderer,
			...questionRenderers,
		};
	}, [ chat.isProcessing, chat.sendMessage ] );
	const displayMessages = useMemo(
		() =>
			chat.messages.map( ( message ) => {
				const toolName = String(
					message.raw?.tool_name ?? message.raw?.name ?? ''
				);
				if ( ! toolName ) {
					return message;
				}

				const renderedTools = renderToolGroups(
					[
						{
							id: message.id,
							name: toolName,
							result: {
								id: message.id,
								message,
								result:
									message.raw && typeof message.raw === 'object'
										? message.raw
										: {},
							},
						},
					],
					toolRenderers
				).filter( Boolean );

				if ( renderedTools.length === 0 ) {
					return message;
				}

				const ToolComponent = () =>
					createElement( 'div', null, ...renderedTools );

				return {
					...message,
					content: [
						{
							type: 'component' as const,
							component: ToolComponent,
						},
					],
				};
			} ),
		[ chat.messages, toolRenderers ]
	);
	const submitMessage = useCallback(
		( message: string, files?: File[] ) =>
			chat.sendMessage( message, canUploadFiles ? files : undefined ),
		[ canUploadFiles, chat.sendMessage ]
	);
	useEffect( () => {
		chat.newSession();
		setUnreadCount( 0 );
	}, [ activeAgentSlug, chat.newSession ] );
	const hasPersistenceCta = !! (
		persistenceCta?.message ||
		( persistenceCta?.actionUrl && persistenceCta?.actionLabel )
	);
	const persistenceMessage = browserBootstrapFailed
		? __(
				'Chat works, but this browser is blocking secure chat-history cookies.',
				'frontend-agent-chat'
		  )
		: persistenceCta?.message;
	const showSessionControls = chatStorageReady && activeAgentSlug;
	const expandedButtonLabel = isExpanded
		? __( 'Exit expanded chat view', 'frontend-agent-chat' )
		: __( 'Expand chat to viewport', 'frontend-agent-chat' );
	const expandButtonIconPath = isExpanded
		? collapseIconPath || DEFAULT_COLLAPSE_ICON_PATH
		: expandIconPath || DEFAULT_EXPAND_ICON_PATH;

	return createElement(
		'div',
		{ className: `frontend-agent-chat is-${ layout }` },
		! isInline &&
			createElement(
				'button',
				{
					type: 'button',
					className: `frontend-agent-chat__fab${
						isOpen ? ' is-hidden' : ''
					}`,
					onClick: open,
					'aria-label': sprintf(
						/* translators: %s: agent name. */
						__( 'Open %s chat', 'frontend-agent-chat' ),
						activeAgentName
					),
				},
				renderFabIcon( fabIcon, fabIconPath, fabIconViewBox ),
				createElement(
					'span',
					{ className: 'frontend-agent-chat__fab-label' },
					fabLabel
				),
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
				className: `frontend-agent-chat__drawer${
					isOpen ? ' is-open' : ''
				}${ isExpanded ? ' is-expanded' : '' }${
					isInline ? ' is-inline' : ''
				}`,
				'aria-hidden': ! isOpen,
			},
			createElement(
				'div',
				{ className: 'frontend-agent-chat__header' },
				! isInline &&
					createElement(
						'div',
						{ className: 'frontend-agent-chat__agent' },
						agents.length > 1
							? createElement(
									'select',
									{
										className:
											'frontend-agent-chat__agent-select',
										value: activeAgentSlug,
										onChange: switchAgent,
										'aria-label': __(
											'Select chat agent',
											'frontend-agent-chat'
										),
									},
									agents.map( ( agent ) =>
										createElement(
											'option',
											{
												key: agent.slug,
												value: agent.slug,
											},
											agent.name
										)
									)
							  )
							: createElement(
									'span',
									{ className: 'frontend-agent-chat__title' },
									activeAgentName
							  )
					),
				createElement(
					'div',
					{ className: 'frontend-agent-chat__header-actions' },
					showSessionControls &&
						createElement(
							'div',
							{
								className:
									'frontend-agent-chat__session-controls',
							},
							createElement(
								'button',
								{
									type: 'button',
									className:
										'frontend-agent-chat__session-new',
									onClick: chat.newSession,
								},
								__( 'New', 'frontend-agent-chat' )
							),
							chat.sessions.length > 0 &&
								createElement(
									'select',
									{
										className:
											'frontend-agent-chat__session-select',
										value: chat.sessionId ?? '',
										onChange: (
											event: ChangeEvent< HTMLSelectElement >
										) => {
											const nextSessionId = event.target.value;
											if ( nextSessionId ) {
												chat.loadSession( nextSessionId );
											} else {
												chat.newSession();
											}
										},
										'aria-label': __(
											'Select chat session',
											'frontend-agent-chat'
										),
									},
									createElement(
										'option',
										{ value: '' },
										__( 'Current chat', 'frontend-agent-chat' )
									),
									chat.sessions.map( ( session, index ) =>
										createElement(
											'option',
											{
												key: session.id,
												value: session.id,
											},
											getSessionLabel( session, index )
										)
									)
								)
						),
					createElement(
						'button',
						{
							type: 'button',
							className: 'frontend-agent-chat__expand',
							onClick: toggleExpanded,
							'aria-label': expandedButtonLabel,
							'aria-pressed': isExpanded,
						},
						renderExpandIcon(
							expandButtonIconPath,
							expandIconViewBox
						)
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
				! isLoggedIn &&
					( browserBootstrapFailed || hasPersistenceCta ) &&
					createElement(
						'div',
						{
							className: `frontend-agent-chat__persistence${
								browserBootstrapFailed ? ' has-warning' : ''
							}`,
						},
						persistenceMessage,
						! browserBootstrapFailed &&
							persistenceCta?.actionUrl &&
							persistenceCta?.actionLabel &&
							createElement(
								'a',
								{
									className:
										'frontend-agent-chat__persistence-action',
									href: persistenceCta.actionUrl,
								},
								persistenceCta.actionLabel
							)
					),
				renderChatHeader(),
				activeAgentSlug &&
					chatStorageReady &&
					createElement( AgentUI, {
						key: activeAgentSlug,
						messages: displayMessages,
						isProcessing: chat.isProcessing,
						error: chat.error,
						onSubmit: submitMessage,
						onStop: chat.cancelRun,
						placeholder: sprintf(
							/* translators: %s: agent name. */
							__( 'Ask %s anything…', 'frontend-agent-chat' ),
							activeAgentName
						),
						emptyView,
						suggestions: messageSuggestions,
						clearSuggestions: () => undefined,
						thinkingMessage,
						allowAttachments: canUploadFiles,
						acceptedFileTypes,
					} )
			)
		)
	);
}
