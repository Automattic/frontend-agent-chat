<?php
/**
 * REST adapter for @extrachill/chat.
 *
 * @package FrontendAgentChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register REST routes expected by @extrachill/chat.
 *
 * @return void
 */
function frontend_agent_chat_register_rest_routes(): void {
	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_send_message',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/continue',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_continue_message',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/sessions',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'frontend_agent_chat_rest_list_sessions',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/sessions/(?P<session_id>[^/]+)/read',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_mark_session_read',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/(?P<session_id>[^/]+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'frontend_agent_chat_rest_get_session',
				'permission_callback' => 'frontend_agent_chat_rest_can_chat',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'frontend_agent_chat_rest_delete_session',
				'permission_callback' => 'frontend_agent_chat_rest_can_chat',
			),
		)
	);
}
add_action( 'rest_api_init', 'frontend_agent_chat_register_rest_routes' );

/**
 * Permission callback for widget REST routes.
 *
 * @return bool
 */
function frontend_agent_chat_rest_can_chat(): bool {
	$config = frontend_agent_chat_get_config();
	if ( empty( $config['enabled'] ) || empty( $config['agent_slug'] ) ) {
		return false;
	}

	$agent = frontend_agent_chat_resolve_agent( (string) $config['agent_slug'] );
	if ( ! $agent ) {
		return false;
	}

	return frontend_agent_chat_user_can_see( $agent );
}

/**
 * Send a message through the canonical Agents API chat ability.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_send_message( WP_REST_Request $request ) {
	$message = trim( (string) $request->get_param( 'message' ) );
	if ( '' === $message ) {
		return new WP_Error( 'frontend_agent_chat_empty_message', __( 'Message cannot be empty.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'agents/chat' ) : null;
	if ( ! $ability ) {
		return new WP_Error( 'frontend_agent_chat_agents_api_missing', __( 'The agents/chat ability is not available.', 'frontend-agent-chat' ), array( 'status' => 501 ) );
	}

	$config      = frontend_agent_chat_get_config();
	$agent_param = $request->get_param( 'agent' );
	$agent_slug  = sanitize_title( (string) ( '' !== (string) $agent_param ? $agent_param : $config['agent_slug'] ) );
	$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

	if ( '' === $session_id ) {
		$session_id = frontend_agent_chat_generate_session_id();
	}

	$attachments = $request->get_param( 'attachments' );
	if ( ! is_array( $attachments ) ) {
		$attachments = array();
	}

	$result = $ability->execute(
		array(
			'agent'          => $agent_slug,
			'message'        => $message,
			'session_id'     => $session_id,
			'attachments'    => $attachments,
			'client_context' => array(
				'source'       => 'rest',
				'client_name'  => 'frontend-agent-chat',
				'connector_id' => 'frontend-agent-chat',
			),
		),
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result_session_id = sanitize_text_field( (string) ( $result['session_id'] ?? $session_id ) );
	if ( '' === $result_session_id ) {
		$result_session_id = $session_id;
	}

	$conversation = frontend_agent_chat_normalize_result_messages( $result, $message );
	frontend_agent_chat_store_session( $result_session_id, $agent_slug, $conversation );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id'        => $result_session_id,
				'response'          => (string) ( $result['reply'] ?? '' ),
				'tool_calls'        => array(),
				'conversation'      => $conversation,
				'metadata'          => is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
				'completed'         => (bool) ( $result['completed'] ?? true ),
				'max_turns'         => 1,
				'turn_number'       => 1,
				'max_turns_reached' => false,
			),
		),
	);
}

/**
 * Continue a pending response.
 *
 * Agents API's canonical ability is single-turn today, so the adapter reports
 * no additional messages and lets runtimes return `completed=true` on send.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_continue_message( WP_REST_Request $request ): WP_REST_Response {
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id'        => sanitize_text_field( (string) $request->get_param( 'session_id' ) ),
				'new_messages'      => array(),
				'final_content'     => '',
				'tool_calls'        => array(),
				'completed'         => true,
				'turn_number'       => 1,
				'max_turns'         => 1,
				'max_turns_reached' => false,
			),
		),
	);
}

/**
 * List stored chat sessions for the current user.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_list_sessions( WP_REST_Request $request ): WP_REST_Response {
	$limit_param = $request->get_param( 'limit' );
	$limit       = max( 1, min( 100, (int) ( null !== $limit_param ? $limit_param : 20 ) ) );
	$sessions    = frontend_agent_chat_get_sessions();
	usort(
		$sessions,
		static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) )
	);

	$items = array_slice( array_map( 'frontend_agent_chat_session_summary', $sessions ), 0, $limit );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'sessions' => $items,
				'total'    => count( $sessions ),
				'limit'    => $limit,
				'offset'   => 0,
			),
		),
	);
}

/**
 * Get one stored session.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_get_session( WP_REST_Request $request ) {
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$session    = frontend_agent_chat_get_sessions()[ $session_id ] ?? null;
	if ( ! $session ) {
		return new WP_Error( 'frontend_agent_chat_session_not_found', __( 'Session not found.', 'frontend-agent-chat' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id'   => $session_id,
				'conversation' => is_array( $session['messages'] ?? null ) ? $session['messages'] : array(),
				'metadata'     => array( 'message_count' => count( $session['messages'] ?? array() ) ),
			),
		),
	);
}

/**
 * Delete one stored session.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_delete_session( WP_REST_Request $request ): WP_REST_Response {
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$sessions   = frontend_agent_chat_get_sessions();
	unset( $sessions[ $session_id ] );
	frontend_agent_chat_save_sessions( $sessions );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id' => $session_id,
				'deleted'    => true,
			),
		),
	);
}

/**
 * Mark one session as read.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_mark_session_read( WP_REST_Request $request ): WP_REST_Response {
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$sessions   = frontend_agent_chat_get_sessions();
	if ( isset( $sessions[ $session_id ] ) ) {
		$sessions[ $session_id ]['unread_count'] = 0;
		$sessions[ $session_id ]['last_read_at'] = gmdate( 'c' );
		frontend_agent_chat_save_sessions( $sessions );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(),
		)
	);
}

/**
 * Get current user's stored sessions.
 *
 * @return array<string,array>
 */
function frontend_agent_chat_get_sessions(): array {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return array();
	}

	$sessions = get_user_meta( $user_id, 'frontend_agent_chat_sessions', true );
	return is_array( $sessions ) ? $sessions : array();
}

/**
 * Save current user's stored sessions.
 *
 * @param array<string,array> $sessions Sessions.
 * @return void
 */
function frontend_agent_chat_save_sessions( array $sessions ): void {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	update_user_meta( $user_id, 'frontend_agent_chat_sessions', $sessions );
}

/**
 * Store one session projection.
 *
 * @param string $session_id Session ID.
 * @param string $agent_slug Agent slug.
 * @param array  $messages Messages.
 * @return void
 */
function frontend_agent_chat_store_session( string $session_id, string $agent_slug, array $messages ): void {
	$sessions = frontend_agent_chat_get_sessions();
	$now      = gmdate( 'c' );
	$existing = $sessions[ $session_id ] ?? array();

	$sessions[ $session_id ] = array(
		'session_id'   => $session_id,
		'agent'        => $agent_slug,
		'title'        => $existing['title'] ?? frontend_agent_chat_title_from_messages( $messages ),
		'created_at'   => $existing['created_at'] ?? $now,
		'updated_at'   => $now,
		'messages'     => $messages,
		'unread_count' => 0,
		'last_read_at' => $existing['last_read_at'] ?? null,
	);

	frontend_agent_chat_save_sessions( $sessions );
}

/**
 * Normalize canonical agents/chat result messages to @extrachill/chat messages.
 *
 * @param array  $result Runtime result.
 * @param string $user_message Original user message.
 * @return array<int,array{role:string,content:string}>
 */
function frontend_agent_chat_normalize_result_messages( array $result, string $user_message ): array {
	$messages = array();
	if ( is_array( $result['messages'] ?? null ) ) {
		foreach ( $result['messages'] as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}
			$role = (string) $message['role'];
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array(
				'role'    => $role,
				'content' => (string) $message['content'],
			);
		}
	}

	if ( empty( $messages ) ) {
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);
		$messages[] = array(
			'role'    => 'assistant',
			'content' => (string) ( $result['reply'] ?? '' ),
		);
	}

	return $messages;
}

/**
 * Build a session summary response.
 *
 * @param array $session Stored session.
 * @return array
 */
function frontend_agent_chat_session_summary( array $session ): array {
	$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
	return array(
		'session_id'    => (string) ( $session['session_id'] ?? '' ),
		'title'         => (string) ( $session['title'] ?? frontend_agent_chat_title_from_messages( $messages ) ),
		'context'       => 'frontend-agent-chat',
		'first_message' => frontend_agent_chat_first_user_message( $messages ),
		'message_count' => count( $messages ),
		'unread_count'  => (int) ( $session['unread_count'] ?? 0 ),
		'created_at'    => (string) ( $session['created_at'] ?? '' ),
		'updated_at'    => (string) ( $session['updated_at'] ?? '' ),
	);
}

/**
 * Generate a widget session ID.
 *
 * @return string
 */
function frontend_agent_chat_generate_session_id(): string {
	return 'fac_' . wp_generate_uuid4();
}

/**
 * Build a short title from messages.
 *
 * @param array $messages Messages.
 * @return string
 */
function frontend_agent_chat_title_from_messages( array $messages ): string {
	$first = frontend_agent_chat_first_user_message( $messages );
	return '' !== $first ? wp_html_excerpt( $first, 60, '...' ) : __( 'New chat', 'frontend-agent-chat' );
}

/**
 * Get the first user message.
 *
 * @param array $messages Messages.
 * @return string
 */
function frontend_agent_chat_first_user_message( array $messages ): string {
	foreach ( $messages as $message ) {
		if ( is_array( $message ) && 'user' === ( $message['role'] ?? '' ) ) {
			return (string) ( $message['content'] ?? '' );
		}
	}
	return '';
}
