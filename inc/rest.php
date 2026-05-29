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
		'/bootstrap',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_bootstrap',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

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
		'/chat/queue',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_queue_message',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/runs/(?P<run_id>[^/]+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'frontend_agent_chat_rest_get_run',
			'permission_callback' => 'frontend_agent_chat_rest_can_chat',
		)
	);

	register_rest_route(
		'frontend-agent-chat/v1',
		'/chat/runs/(?P<run_id>[^/]+)/cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'frontend_agent_chat_rest_cancel_run',
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
 * Bootstrap browser-scoped state before anonymous session APIs are used.
 *
 * @return WP_REST_Response
 */
function frontend_agent_chat_rest_bootstrap(): WP_REST_Response {
	$had_principal = null !== frontend_agent_chat_get_browser_principal();
	$principal     = frontend_agent_chat_ensure_browser_principal_cookie();
	$config        = frontend_agent_chat_get_config();
	$agent_slug    = frontend_agent_chat_get_default_agent_slug( $config );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'authenticated'             => is_user_logged_in(),
				'browser_principal_ready'   => is_user_logged_in() || null !== $principal,
				'had_browser_principal'     => $had_principal,
				'browser_principal_id'      => is_array( $principal ) ? $principal['id'] : '',
				'browser_principal_secret'  => false,
				'session_persistence_scope' => is_user_logged_in() ? 'user' : 'browser',
				'capabilities'              => frontend_agent_chat_get_run_control_capabilities( $agent_slug ),
			),
		)
	);
}

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

	$default_agent_slug = frontend_agent_chat_get_default_agent_slug( $config );
	$agent_slug         = $request ? frontend_agent_chat_rest_get_agent_slug( $request, $default_agent_slug ) : $default_agent_slug;
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
	$config       = frontend_agent_chat_get_config();
	$agents       = frontend_agent_chat_list_accessible_agents();
	$active_slug  = frontend_agent_chat_get_active_agent_slug();
	$default_slug = frontend_agent_chat_get_default_agent_slug( $config );
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'active_agent_slug'  => $active_slug,
				'default_agent_slug' => $default_slug,
				'agents'             => array_map( 'frontend_agent_chat_rest_agent_summary', $agents ),
			),
		)
	);
}

/**
 * Get the current user's active agent preference.
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
 * Persist the current user's active agent preference.
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

	if ( ! is_user_logged_in() ) {
		if ( ! frontend_agent_chat_get_browser_principal() ) {
			return new WP_Error( 'frontend_agent_chat_browser_principal_required', __( 'Browser chat storage needs cookies enabled.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
		}

		frontend_agent_chat_set_browser_active_agent_slug( $agent_slug );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'agent_slug' => $agent_slug,
				),
			)
		);
	}

	if ( ! frontend_agent_chat_set_user_active_agent_slug( $agent_slug ) ) {
		return new WP_Error( 'frontend_agent_chat_active_agent_failed', __( 'Failed to set active agent.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'agent_slug' => $agent_slug,
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

	$config     = frontend_agent_chat_get_config();
	$agent_slug = frontend_agent_chat_rest_get_agent_slug( $request, frontend_agent_chat_get_default_agent_slug( $config ) );
	$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
	if ( '' === $agent_slug ) {
		return new WP_Error( 'frontend_agent_chat_missing_agent', __( 'Agent is required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
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
	 * Domain plugins can use this to add runtime context without hardcoding
	 * product-specific behavior here.
	 *
	 * @param array           $chat_input Canonical agents/chat input.
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $agent_slug Selected agent slug.
	 * @param array           $config     Frontend chat configuration.
	 */
	/** @var mixed $chat_input */
	$chat_input = apply_filters( 'frontend_agent_chat_chat_input', $chat_input, $request, $agent_slug, $config );

	$chat_input = frontend_agent_chat_add_browser_principal_input( is_array( $chat_input ) ? $chat_input : array() );
	$result     = frontend_agent_chat_execute_ability( 'agents/chat', $chat_input );

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
				'run_id'            => sanitize_text_field( (string) ( $result['run_id'] ?? '' ) ),
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
 * Get the status for a canonical Agents API chat run.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_get_run( WP_REST_Request $request ) {
	$run_id     = sanitize_text_field( (string) $request['run_id'] );
	$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
	if ( '' === $run_id || '' === $session_id ) {
		return new WP_Error( 'frontend_agent_chat_invalid_run', __( 'run_id and session_id are required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$result = frontend_agent_chat_execute_ability(
		'agents/get-chat-run',
		frontend_agent_chat_add_browser_principal_input(
			array(
				'run_id'     => $run_id,
				'session_id' => $session_id,
			)
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => frontend_agent_chat_normalize_run_control_result( is_array( $result ) ? $result : array(), $run_id, $session_id ),
		)
	);
}

/**
 * Cancel a canonical Agents API chat run.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_cancel_run( WP_REST_Request $request ) {
	$run_id     = sanitize_text_field( (string) $request['run_id'] );
	$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
	if ( '' === $run_id || '' === $session_id ) {
		return new WP_Error( 'frontend_agent_chat_invalid_run', __( 'run_id and session_id are required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$result = frontend_agent_chat_execute_ability(
		'agents/cancel-chat-run',
		frontend_agent_chat_add_browser_principal_input(
			array(
				'run_id'     => $run_id,
				'session_id' => $session_id,
			)
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$data = frontend_agent_chat_normalize_run_control_result( is_array( $result ) ? $result : array(), $run_id, $session_id );
	$data['cancelled'] = (bool) ( is_array( $result ) && ( $result['cancelled'] ?? false ) );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $data,
		)
	);
}

/**
 * Queue a follow-up chat message through the canonical Agents API queue ability.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function frontend_agent_chat_rest_queue_message( WP_REST_Request $request ) {
	$message = trim( (string) $request->get_param( 'message' ) );
	if ( '' === $message ) {
		return new WP_Error( 'frontend_agent_chat_empty_message', __( 'Message cannot be empty.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$config     = frontend_agent_chat_get_config();
	$agent_slug = frontend_agent_chat_rest_get_agent_slug( $request, frontend_agent_chat_get_default_agent_slug( $config ) );
	$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
	if ( '' === $agent_slug || '' === $session_id ) {
		return new WP_Error( 'frontend_agent_chat_invalid_queue_message', __( 'agent and session_id are required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$attachments = $request->get_param( 'attachments' );
	$queue_input = array(
		'agent'          => $agent_slug,
		'session_id'     => $session_id,
		'run_id'         => sanitize_text_field( (string) $request->get_param( 'run_id' ) ),
		'message'        => $message,
		'attachments'    => is_array( $attachments ) ? $attachments : array(),
		'client_context' => array(
			'source'       => 'rest',
			'client_name'  => 'frontend-agent-chat',
			'connector_id' => 'frontend-agent-chat',
		),
	);

	/**
	 * Filter the canonical agents/queue-chat-message input sent by the frontend chat widget.
	 *
	 * @param array           $queue_input Canonical queue input.
	 * @param WP_REST_Request $request     REST request.
	 * @param string          $agent_slug  Selected agent slug.
	 * @param array           $config      Frontend chat configuration.
	 */
	/** @var mixed $queue_input */
	$queue_input = apply_filters( 'frontend_agent_chat_queue_input', $queue_input, $request, $agent_slug, $config );

	$result = frontend_agent_chat_execute_ability( 'agents/queue-chat-message', frontend_agent_chat_add_browser_principal_input( is_array( $queue_input ) ? $queue_input : array() ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = is_array( $result ) ? $result : array();
	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'queued_message_id' => sanitize_text_field( (string) ( $result['queued_message_id'] ?? '' ) ),
				'session_id'        => sanitize_text_field( (string) ( $result['session_id'] ?? $session_id ) ),
				'run_id'            => sanitize_text_field( (string) ( $result['run_id'] ?? '' ) ),
				'position'          => (int) ( $result['position'] ?? 0 ),
				'status'            => sanitize_key( (string) ( $result['status'] ?? 'queued' ) ),
			)
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
		frontend_agent_chat_add_browser_principal_input( array(
			'action_id' => $action_id,
			'decision'  => $decision,
			'resolver'  => frontend_agent_chat_current_resolver_id(),
		) )
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
	$agent_slug  = frontend_agent_chat_rest_get_agent_slug( $request, frontend_agent_chat_get_default_agent_slug( $config ) );
	if ( '' === $agent_slug ) {
		return new WP_Error( 'frontend_agent_chat_missing_agent', __( 'Agent is required.', 'frontend-agent-chat' ), array( 'status' => 400 ) );
	}

	$list_input = array(
		'limit'   => $limit,
		'agent'   => $agent_slug,
		'context' => 'chat',
	);

	/**
	 * Filter the canonical agents/list-conversation-sessions input sent by the frontend chat widget.
	 *
	 * Domain plugins can keep transcript listing aligned with any custom chat
	 * execution context they add via frontend_agent_chat_chat_input.
	 *
	 * @param array           $list_input Canonical agents/list-conversation-sessions input.
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $agent_slug Selected agent slug.
	 * @param array           $config     Frontend chat configuration.
	 */
	/** @var mixed $list_input */
	$list_input = apply_filters( 'frontend_agent_chat_session_list_input', $list_input, $request, $agent_slug, $config );

	$result = frontend_agent_chat_execute_ability(
		'agents/list-conversation-sessions',
		frontend_agent_chat_add_browser_principal_input( is_array( $list_input ) ? $list_input : array() )
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
	$config     = frontend_agent_chat_get_config();
	$agent_slug = frontend_agent_chat_rest_get_agent_slug( $request, frontend_agent_chat_get_default_agent_slug( $config ) );
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$input      = array( 'session_id' => $session_id );
	if ( '' !== $agent_slug ) {
		$input['agent'] = $agent_slug;
	}
	$result = frontend_agent_chat_execute_ability( 'agents/get-conversation-session', frontend_agent_chat_add_browser_principal_input( $input ) );
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
	$config     = frontend_agent_chat_get_config();
	$agent_slug = frontend_agent_chat_rest_get_agent_slug( $request, frontend_agent_chat_get_default_agent_slug( $config ) );
	$session_id = sanitize_text_field( (string) $request['session_id'] );
	$input      = array( 'session_id' => $session_id );
	if ( '' !== $agent_slug ) {
		$input['agent'] = $agent_slug;
	}
	$result = frontend_agent_chat_execute_ability( 'agents/delete-conversation-session', frontend_agent_chat_add_browser_principal_input( $input ) );
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
 * Normalize canonical chat run-control ability output for REST clients.
 *
 * @param array  $result     Ability result.
 * @param string $run_id     Fallback run id.
 * @param string $session_id Fallback session id.
 * @return array
 */
function frontend_agent_chat_normalize_run_control_result( array $result, string $run_id, string $session_id ): array {
	return array(
		'run_id'     => sanitize_text_field( (string) ( $result['run_id'] ?? $run_id ) ),
		'session_id' => sanitize_text_field( (string) ( $result['session_id'] ?? $session_id ) ),
		'status'     => sanitize_key( (string) ( $result['status'] ?? '' ) ),
		'started_at' => sanitize_text_field( (string) ( $result['started_at'] ?? '' ) ),
		'updated_at' => sanitize_text_field( (string) ( $result['updated_at'] ?? '' ) ),
		'metadata'   => is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
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
 * Normalizes both classic single-string content and multimodal content
 * (an array of text/image parts produced by user image uploads) into the
 * @extrachill/chat wire shape: `content` is always a plain string, and
 * media is surfaced separately via `metadata.attachments` so the frontend
 * normalizer can render image previews on session reload.
 *
 * @param array $source Session or runtime result.
 * @return array<int,array{role:string,content:string,metadata?:array}>
 */
function frontend_agent_chat_session_messages( array $source ): array {
	$messages = array();
	foreach ( is_array( $source['messages'] ?? null ) ? $source['messages'] : array() as $message ) {
		if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
			continue;
		}

		if ( in_array( (string) ( $message['type'] ?? '' ), array( 'tool_call', 'tool_result' ), true ) ) {
			$tool_message = frontend_agent_chat_tool_message( $message );
			if ( null !== $tool_message ) {
				$messages[] = $tool_message;
			}
			continue;
		}

		$role = (string) $message['role'];
		if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
			continue;
		}

		$normalized = array(
			'role'    => $role,
			'content' => frontend_agent_chat_flatten_message_content( $message['content'] ),
		);

		// Pass metadata through so the frontend normalizer can surface
		// attachments (user image uploads, tool-produced media) on session
		// reload. Without this, multimodal messages would render text-only.
		if ( isset( $message['metadata'] ) && is_array( $message['metadata'] ) ) {
			$normalized['metadata'] = $message['metadata'];
		}

		$messages[] = $normalized;
	}

	return $messages;
}

/**
 * Normalize a canonical tool envelope into the chat package wire shape.
 *
 * @param array $message Canonical message envelope.
 * @return array{role:string,content:string,metadata:array}|null Normalized message.
 */
function frontend_agent_chat_tool_message( array $message ): ?array {
	$type = (string) ( $message['type'] ?? '' );
	if ( ! in_array( $type, array( 'tool_call', 'tool_result' ), true ) ) {
		return null;
	}

	$payload   = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
	$metadata  = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
	$tool_name = (string) ( $payload['tool_name'] ?? $metadata['tool_name'] ?? '' );
	if ( '' === $tool_name ) {
		return null;
	}

	$metadata = array_merge(
		$metadata,
		array(
			'type'         => $type,
			'tool_name'    => $tool_name,
			'tool_call_id' => (string) ( $payload['tool_call_id'] ?? $metadata['tool_call_id'] ?? '' ),
			'parameters'   => is_array( $payload['parameters'] ?? null ) ? $payload['parameters'] : array(),
			'tool_data'    => $payload,
		)
	);

	if ( 'tool_result' === $type ) {
		$metadata['success'] = (bool) ( $payload['success'] ?? $metadata['success'] ?? false );
	}

	return array(
		'role'     => 'tool_call' === $type ? 'assistant' : 'user',
		'content'  => frontend_agent_chat_flatten_message_content( $message['content'] ?? '' ),
		'metadata' => $metadata,
	);
}

/**
 * Flatten a message content payload into a plain string for the wire.
 *
 * Multimodal messages store content as an array of `{type, text}` and
 * `{type, image_url}` parts (the canonical agents-api.message v1 shape).
 * Concatenate the text parts; image parts are surfaced separately via
 * `metadata.attachments` and don't need to appear in `content`.
 *
 * @param mixed $content Raw content (string or array of parts).
 * @return string
 */
function frontend_agent_chat_flatten_message_content( $content ): string {
	if ( is_string( $content ) ) {
		return $content;
	}

	if ( ! is_array( $content ) ) {
		return '';
	}

	$texts = array();
	foreach ( $content as $part ) {
		if ( ! is_array( $part ) ) {
			continue;
		}

		$type = (string) ( $part['type'] ?? '' );
		if ( 'text' === $type && isset( $part['text'] ) && is_string( $part['text'] ) ) {
			$texts[] = $part['text'];
		}
	}

	return implode( "\n\n", $texts );
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
