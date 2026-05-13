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
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { createElement, useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

interface AgentChatProps {
	agentSlug: string;
	basePath: string;
	agentName: string;
	agentDescription: string;
	loadingMessages?: boolean | {
		mode?: 'default' | 'extend' | 'override';
		messages?: string[];
		interval?: number;
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
	return ( options ) => apiFetch( {
		path: options.path,
		method: options.method,
		data: options.method === 'POST'
			? { ...( options.data ?? {} ), agent: agentSlug }
			: options.data,
		headers: options.headers,
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
	agentName,
	agentDescription,
	loadingMessages = true,
}: AgentChatProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ unreadCount, setUnreadCount ] = useState( 0 );
	const metadata = useClientContextMetadata();
	const agentFetch = useMemo( () => createAgentFetch( agentSlug ), [ agentSlug ] );
	const open = useCallback( () => setIsOpen( true ), [] );
	const close = useCallback( () => setIsOpen( false ), [] );

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
					agentName
				),
			},
			agentName,
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
					'span',
					{ className: 'frontend-agent-chat__title' },
					agentName
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
				createElement( Chat, {
					basePath,
					fetchFn: agentFetch,
					showTools: true,
					showSessions: true,
					toolRenderers,
					placeholder: sprintf(
						/* translators: %s: agent name. */
						__( 'Ask %s anything…', 'frontend-agent-chat' ),
						agentName
					),
					metadata,
					isVisible: isOpen,
					onUnreadChange: setUnreadCount,
					emptyState: createElement(
						'div',
						{ className: 'frontend-agent-chat__empty' },
						createElement( 'h3', null, agentName ),
						createElement( 'p', null, agentDescription )
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
