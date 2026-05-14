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
		'/agents',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'frontend_agent_chat_rest_list_agents',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/agents/active',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'frontend_agent_chat_rest_get_active_agent',
				'permission_callback' => 'frontend_agent_chat_rest_can_chat',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'frontend_agent_chat_rest_set_active_agent',
				'permission_callback' => 'frontend_agent_chat_rest_can_chat',
			),
		)
	);

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
		'/chat/actions/resolve',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_resolve_pending_action',
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
function frontend_agent_chat_rest_can_chat( ?WP_REST_Request $request = null ): bool {
	$config = frontend_agent_chat_get_config();
	if ( empty( $config['enabled'] ) ) {
		return false;
	}
	if ( $request && '/frontend-agent-chat/v1/agents' === $request->get_route() ) {
		return ! empty( frontend_agent_chat_list_accessible_agents() );
	}

	$agent_slug = $request ? frontend_agent_chat_rest_get_agent_slug( $request, (string) ( $config['agent_slug'] ?? '' ) ) : (string) ( $config['agent_slug'] ?? '' );
	if ( '' === $agent_slug ) {
		return ! empty( frontend_agent_chat_list_accessible_agents() );
	}

	$agent = frontend_agent_chat_resolve_agent( $agent_slug );
	if ( ! $agent ) {
		return false;
	}

	return frontend_agent_chat_user_can_see( $agent );
}

/**
 * List accessible agents for the selector.
 *
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_list_agents(): WP_REST_Response {
	$agents      = frontend_agent_chat_list_accessible_agents();
	$active_slug = frontend_agent_chat_get_active_agent_slug();
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'active_agent_slug' => $active_slug,
				'agents'            => array_map( 'frontend_agent_chat_rest_agent_summary', $agents ),
			),
		)
	);
}

/**
 * Get the current user's active Data Machine agent preference.
 *
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_get_active_agent(): WP_REST_Response {
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'agent_slug' => frontend_agent_chat_get_active_agent_slug(),
			),
		)
	);
}

/**
 * Persist the current user's active Data Machine agent preference.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_set_active_agent( WP_REST_Request $request ) {
	$agent_slug = sanitize_title( (string) $request->get_param( 'agent' ) );
	if ( '' === $agent_slug ) {
		$agent_slug = sanitize_title( (string) $request->get_param( 'agent_slug' ) );
	}
	if ( '' === $agent_slug ) {
		return new WP_Error( 'frontend_agent_chat_missing_agent', __( 'Agent is required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$result = frontend_agent_chat_execute_ability( 'datamachine/set-active-agent', array( 'agent' => $agent_slug ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( empty( $result['success'] ) ) {
		return new WP_Error( 'frontend_agent_chat_active_agent_failed', (string) ( $result['error'] ?? __( 'Failed to set active agent.', 'frontend-agent-chat' ) ), array( 'status' => 400 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'agent_slug' => sanitize_title( (string) ( $result['agent_slug'] ?? $agent_slug ) ),
			),
		)
	);
}

/**
 * Build a REST-safe agent summary.
 *
 * @param array $agent Normalized agent descriptor.
 * @return array
 */
function frontend_agent_chat_rest_agent_summary( array $agent ): array {
	return array(
		'slug'        => (string) ( $agent['agent_slug'] ?? '' ),
		'name'        => (string) ( $agent['agent_name'] ?? $agent['agent_slug'] ?? '' ),
		'description' => (string) ( $agent['agent_description'] ?? '' ),
		'meta'        => is_array( $agent['meta'] ?? null ) ? $agent['meta'] : array(),
	);
}

/**
 * Resolve the requested agent slug, falling back to a configured default.
 *
 * @param WP_REST_Request $request      REST request.
 * @param string          $default_slug Optional configured default.
 * @return string
 */
function frontend_agent_chat_rest_get_agent_slug( WP_REST_Request $request, string $default_slug = '' ): string {
	$agent = $request->get_param( 'agent' );
	if ( null === $agent || '' === (string) $agent ) {
		$agent = $request->get_param( 'agent_slug' );
	}
	if ( null === $agent || '' === (string) $agent ) {
		$agent = $default_slug;
	}

	return sanitize_title( (string) $agent );
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

	$config      = frontend_agent_chat_get_config();
	$agent_slug  = frontend_agent_chat_rest_get_agent_slug( $request, (string) ( $config['agent_slug'] ?? '' ) );
	$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
	if ( '' === $agent_slug ) {
		return new WP_Error( 'frontend_agent_chat_missing_agent', __( 'Agent is required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	if ( '' === $session_id ) {
		$created = frontend_agent_chat_execute_ability(
			'agents/create-conversation-session',
			array(
				'agent'   => $agent_slug,
				'context' => 'frontend-agent-chat',
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$session_id = frontend_agent_chat_extract_session_id( is_array( $created['session'] ?? null ) ? $created['session'] : array() );
	}

	if ( '' === $session_id ) {
		return new WP_Error( 'frontend_agent_chat_session_create_failed', __( 'The conversation session ability did not return a session ID.', 'frontend-agent-chat' ), array( 'status' => 500 ) );
	}

	$attachments = $request->get_param( 'attachments' );
	$chat_input  = array(
		'agent'          => $agent_slug,
		'message'        => $message,
		'session_id'     => $session_id,
		'attachments'    => is_array( $attachments ) ? $attachments : array(),
		'client_context' => array(
			'source'       => 'rest',
			'client_name'  => 'frontend-agent-chat',
			'connector_id' => 'frontend-agent-chat',
		),
	);

	/**
	 * Filter the canonical agents/chat input sent by the frontend chat widget.
	 *
	 * Domain plugins can use this to add runtime context, such as a Data Machine
	 * execution mode, without hardcoding product-specific behavior here.
	 *
	 * @param array           $chat_input Canonical agents/chat input.
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $agent_slug Selected agent slug.
	 * @param array           $config     Frontend chat configuration.
	 */
	$chat_input = apply_filters( 'frontend_agent_chat_chat_input', $chat_input, $request, $agent_slug, $config );

	$result = frontend_agent_chat_execute_ability( 'agents/chat', is_array( $chat_input ) ? $chat_input : array() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result_session_id = sanitize_text_field( (string) ( $result['session_id'] ?? $session_id ) );
	$conversation      = frontend_agent_chat_normalize_result_messages( $result, $message );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id'        => $result_session_id,
				'response'          => (string) ( $result['reply'] ?? '' ),
				'tool_calls'        => is_array( $result['tool_calls'] ?? null ) ? $result['tool_calls'] : array(),
				'conversation'      => $conversation,
				'metadata'          => is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
				'completed'         => (bool) ( $result['completed'] ?? true ),
				'max_turns'         => 1,
				'turn_number'       => 1,
				'max_turns_reached' => false,
			),
		)
	);
}

/**
 * Continue a pending response.
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
		)
	);
}

/**
 * Resolve a pending action through Agents API.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_resolve_pending_action( WP_REST_Request $request ) {
	$action_id = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
	$decision  = sanitize_text_field( (string) $request->get_param( 'decision' ) );
	if ( '' === $action_id || '' === $decision ) {
		return new WP_Error( 'frontend_agent_chat_invalid_pending_action', __( 'action_id and decision are required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$result = frontend_agent_chat_execute_ability(
		'agents/resolve-pending-action',
		array(
			'action_id' => $action_id,
			'decision'  => $decision,
			'resolver'  => frontend_agent_chat_current_resolver_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $result,
		)
	);
}

/**
 * List chat sessions through Agents API.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_list_sessions( WP_REST_Request $request ) {
	$config      = frontend_agent_chat_get_config();
	$limit_param = $request->get_param( 'limit' );
	$limit       = max( 1, min( 100, (int) ( null !== $limit_param ? $limit_param : 20 ) ) );
	$agent_slug  = frontend_agent_chat_rest_get_agent_slug( $request, (string) ( $config['agent_slug'] ?? '' ) );
	if ( '' === $agent_slug ) {
		return new WP_Error( 'frontend_agent_chat_missing_agent', __( 'Agent is required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$result      = frontend_agent_chat_execute_ability(
		'agents/list-conversation-sessions',
		array(
			'limit'   => $limit,
			'agent'   => $agent_slug,
			'context' => 'frontend-agent-chat',
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$sessions = is_array( $result['sessions'] ?? null ) ? $result['sessions'] : array();
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'sessions' => array_map( 'frontend_agent_chat_session_summary', $sessions ),
				'total'    => count( $sessions ),
				'limit'    => $limit,
				'offset'   => 0,
			),
		)
	);
}

/**
 * Get one stored session through Agents API.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_get_session( WP_REST_Request $request ) {
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$result     = frontend_agent_chat_execute_ability( 'agents/get-conversation-session', array( 'session_id' => $session_id ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$session = is_array( $result['session'] ?? null ) ? $result['session'] : array();
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id'   => frontend_agent_chat_extract_session_id( $session ),
				'conversation' => frontend_agent_chat_session_messages( $session ),
				'metadata'     => is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array(),
			),
		)
	);
}

/**
 * Delete one stored session through Agents API.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_delete_session( WP_REST_Request $request ) {
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$result     = frontend_agent_chat_execute_ability( 'agents/delete-conversation-session', array( 'session_id' => $session_id ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id' => $session_id,
				'deleted'    => ! empty( $result['deleted'] ),
			),
		)
	);
}

/**
 * Mark one session as read.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_mark_session_read( WP_REST_Request $request ): WP_REST_Response {
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'session_id' => sanitize_text_field( (string) $request['session_id'] ),
			),
		)
	);
}

/**
 * Build a stable resolver identifier for pending actions.
 *
 * @return string
 */
function frontend_agent_chat_current_resolver_id(): string {
	$user_id = get_current_user_id();
	return $user_id > 0 ? 'user:' . $user_id : 'frontend-agent-chat';
}

/**
 * Extract a session ID from a session descriptor.
 *
 * @param array $session Session descriptor.
 * @return string
 */
function frontend_agent_chat_extract_session_id( array $session ): string {
	return sanitize_text_field( (string) ( $session['session_id'] ?? $session['id'] ?? '' ) );
}

/**
 * Normalize canonical agents/chat result messages to @extrachill/chat messages.
 *
 * @param array  $result       Runtime result.
 * @param string $user_message Original user message.
 * @return array<int,array{role:string,content:string}>
 */
function frontend_agent_chat_normalize_result_messages( array $result, string $user_message ): array {
	$messages = frontend_agent_chat_session_messages( $result );
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
 * Extract chat messages from a session or runtime result.
 *
 * @param array $source Session or runtime result.
 * @return array<int,array{role:string,content:string}>
 */
function frontend_agent_chat_session_messages( array $source ): array {
	$messages = array();
	foreach ( is_array( $source['messages'] ?? null ) ? $source['messages'] : array() as $message ) {
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

	return $messages;
}

/**
 * Build a session summary response.
 *
 * @param array $session Stored session.
 * @return array
 */
function frontend_agent_chat_session_summary( array $session ): array {
	$messages = frontend_agent_chat_session_messages( $session );
	return array(
		'session_id'    => frontend_agent_chat_extract_session_id( $session ),
		'title'         => (string) ( $session['title'] ?? frontend_agent_chat_title_from_messages( $messages ) ),
		'context'       => (string) ( $session['context'] ?? 'frontend-agent-chat' ),
		'first_message' => frontend_agent_chat_first_user_message( $messages ),
		'message_count' => count( $messages ),
		'unread_count'  => (int) ( $session['unread_count'] ?? 0 ),
		'created_at'    => (string) ( $session['created_at'] ?? '' ),
		'updated_at'    => (string) ( $session['updated_at'] ?? '' ),
	);
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
