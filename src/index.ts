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
			agentSlug: string;
			basePath: string;
			agentName: string;
			agentDescription: string;
			loadingMessages?: boolean | {
				mode?: 'default' | 'extend' | 'override';
				messages?: string[];
				interval?: number;
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
	if ( ! config?.agentSlug ) {
		return;
	}

	el.dataset.ecMounted = 'true';
	mount(
		el,
		createElement( AgentChat, {
			agentSlug: config.agentSlug,
			basePath: config.basePath,
			agentName: config.agentName,
			agentDescription: config.agentDescription,
			loadingMessages: config.loadingMessages ?? true,
		} )
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
