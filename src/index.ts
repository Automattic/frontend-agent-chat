/**
 * Frontend Agent Chat — Entry point.
 *
 * Standalone script enqueued on frontend pages for eligible users.
 * Mounts a configurable WordPress agent chat widget.
 *
 * @package
 * @since 0.4.0
 */

/**
 * External dependencies
 */
import '@extrachill/chat/css';
import type { ReactElement } from 'react';

/**
 * WordPress dependencies
 */
import { createElement, createRoot, render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './agent-chat.css';
import AgentChat from './AgentChat';

declare global {
	interface Window {
		frontendAgentChatConfig?: {
			agentSlug?: string;
			basePath: string;
			bootstrapPath?: string;
			agentsPath: string;
			agentName: string;
			agentDescription: string;
			fabLabel?: string;
			fabIcon?: string;
			expandIconPath?: string;
			collapseIconPath?: string;
			expandIconViewBox?: string;
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
		};
	}
}

const MOUNT_SELECTOR = '[data-frontend-agent-chat]';

function mount( container: HTMLElement, component: ReactElement ): void {
	if ( typeof createRoot === 'function' ) {
		createRoot( container ).render( component );
		return;
	}
	if ( typeof render === 'function' ) {
		render( component, container );
	}
}

function init(): void {
	const el = document.querySelector< HTMLElement >( MOUNT_SELECTOR );
	if ( ! el || el.dataset.ecMounted === 'true' ) {
		return;
	}

	const config = window.frontendAgentChatConfig;
	if ( ! config?.basePath || ! config?.agentsPath ) {
		return;
	}

	el.dataset.ecMounted = 'true';
	mount(
		el,
		createElement( AgentChat, {
			agentSlug: config.agentSlug,
			basePath: config.basePath,
			bootstrapPath: config.bootstrapPath,
			agentsPath: config.agentsPath,
			agentName: config.agentName,
			agentDescription: config.agentDescription,
			fabLabel: config.fabLabel,
			fabIcon: config.fabIcon,
			expandIconPath: config.expandIconPath,
			collapseIconPath: config.collapseIconPath,
			expandIconViewBox: config.expandIconViewBox,
			isLoggedIn: config.isLoggedIn ?? false,
			loadingMessages: config.loadingMessages ?? true,
			persistenceCta: config.persistenceCta,
		} )
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
