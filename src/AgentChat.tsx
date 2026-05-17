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
	useClientContextMetadata,
	parseCanonicalDiffFromToolGroup,
} from '@extrachill/chat';
import type { ToolGroup, DiffData, FetchFn, MediaUploadFn } from '@extrachill/chat';
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
}

interface BootstrapResponse {
	success?: boolean;
	data?: {
		authenticated?: boolean;
		browser_principal_ready?: boolean;
		had_browser_principal?: boolean;
		session_persistence_scope?: 'user' | 'browser';
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
				? { ...( options.data ?? {} ), agent: agentSlug }
				: options.data,
			headers: options.headers,
		} );
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

export default function AgentChat( {
	agentSlug,
	basePath,
	bootstrapPath = '/frontend-agent-chat/v1/bootstrap',
	agentsPath,
	agentName,
	agentDescription,
	isLoggedIn = false,
	loadingMessages = true,
	persistenceCta,
}: AgentChatProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
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
	const fabLabel = __( 'Brain Chat', 'frontend-agent-chat' );
	const agentFetch = useMemo( () => createAgentFetch( activeAgentSlug ), [ activeAgentSlug ] );
	const open = useCallback( () => setIsOpen( true ), [] );
	const close = useCallback( () => setIsOpen( false ), [] );
	const switchAgent = useCallback( ( event: ChangeEvent< HTMLSelectElement > ) => {
		const nextAgentSlug = event.target.value;
		setSelectedAgentSlug( nextAgentSlug );
		persistActiveAgent( nextAgentSlug );
	}, [] );

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

	// Close drawer on Escape key.
	useEffect( () => {
		function handleKeyDown( e: KeyboardEvent ) {
			if ( e.key === 'Escape' && isOpen ) {
				setIsOpen( false );
			}
		}
		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ isOpen ] );

	const toolRenderers = useMemo(
		() => ( {
			edit_post_blocks: renderDiffCard,
			replace_post_blocks: renderDiffCard,
			insert_content: renderDiffCard,
		} ),
		[]
	);
	const persistenceMessage = browserBootstrapFailed
		? __( 'Chat works, but this browser is blocking secure chat-history cookies.', 'frontend-agent-chat' )
		: ( persistenceCta?.message || __( 'This browser can keep chat history with a secure cookie.', 'frontend-agent-chat' ) );

	return createElement(
		'div',
		{ className: 'frontend-agent-chat' },
		createElement(
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
			createElement( 'span', { className: 'frontend-agent-chat__fab-icon', 'aria-hidden': true }, '🧠' ),
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
				className: `frontend-agent-chat__drawer${ isOpen ? ' is-open' : '' }`,
				'aria-hidden': ! isOpen,
			},
			createElement(
				'div',
				{ className: 'frontend-agent-chat__header' },
				createElement(
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
					'button',
					{
						type: 'button',
						className: 'frontend-agent-chat__close',
						onClick: close,
						'aria-label': __( 'Close', 'frontend-agent-chat' ),
					},
					'\u00D7'
				)
			),
			createElement(
				'div',
				{ className: 'frontend-agent-chat__body' },
				! isLoggedIn && createElement(
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
				activeAgentSlug && createElement( Chat, {
					key: activeAgentSlug,
					basePath,
					fetchFn: agentFetch,
					showTools: true,
					showSessions: isLoggedIn || browserBootstrapReady,
					toolRenderers,
					placeholder: sprintf(
						/* translators: %s: agent name. */
						__( 'Ask %s anything…', 'frontend-agent-chat' ),
						activeAgentName
					),
					metadata,
					isVisible: isOpen,
					onUnreadChange: setUnreadCount,
					emptyState: createElement(
						'div',
						{ className: 'frontend-agent-chat__empty' },
						createElement( 'h3', null, activeAgentName ),
						createElement( 'p', null, activeAgentDescription )
					),
					loadingMessages,
					mediaUploadFn: wpMediaUpload,
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
