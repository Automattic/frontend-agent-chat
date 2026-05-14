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
	agentsPath: string;
	agentName: string;
	agentDescription: string;
	loadingMessages?: boolean | {
		mode?: 'default' | 'extend' | 'override';
		messages?: string[];
		interval?: number;
	};
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
	agentsPath,
	agentName,
	agentDescription,
	loadingMessages = true,
}: AgentChatProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ unreadCount, setUnreadCount ] = useState( 0 );
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
	const fabLabel = __( 'Consult Intelligence', 'frontend-agent-chat' );
	const agentFetch = useMemo( () => createAgentFetch( activeAgentSlug ), [ activeAgentSlug ] );
	const open = useCallback( () => setIsOpen( true ), [] );
	const close = useCallback( () => setIsOpen( false ), [] );
	const switchAgent = useCallback( ( event: ChangeEvent< HTMLSelectElement > ) => {
		const nextAgentSlug = event.target.value;
		setSelectedAgentSlug( nextAgentSlug );
		persistActiveAgent( nextAgentSlug );
	}, [] );

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

					const activeAgentSlug = data.active_agent_slug ?? '';
					if ( activeAgentSlug && nextAgents.some( ( agent ) => agent.slug === activeAgentSlug ) ) {
						return activeAgentSlug;
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
			fabLabel,
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
				activeAgentSlug && createElement( Chat, {
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
