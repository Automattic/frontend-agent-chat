<?php
/**
 * Configuration for the frontend agent chat widget.
 *
 * @package FrontendAgentChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the frontend chat configuration for the current site.
 *
 * @return array
 */
function frontend_agent_chat_get_config(): array {
	$defaults = array(
		'agent_slug'           => '',
		'description'          => __( 'Your AI assistant.', 'frontend-agent-chat' ),
		'enabled'              => false,
		'fab_label'            => __( 'Agent Chat', 'frontend-agent-chat' ),
		'fab_icon'             => 'AI',
		'fab_icon_path'        => '',
		'fab_icon_view_box'    => '0 0 24 24',
		'expand_icon_path'     => '',
		'collapse_icon_path'   => '',
		'expand_icon_view_box' => '0 0 24 24',
		'loading_messages'     => true,
		'message_suggestions'  => array(),
		'layout'               => 'floating',
	);

	$saved = get_option( 'frontend_agent_chat_config', array() );
	if ( empty( $saved ) && is_multisite() ) {
		$saved = get_site_option( 'frontend_agent_chat_config', array() );
	}

	$config = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

	/**
	 * Filter the frontend chat config for the current site.
	 *
	 * @param array $config Current configuration.
	 */
	return apply_filters( 'frontend_agent_chat_config', $config );
}

/**
 * Check whether the current request principal can see the chat widget.
 *
 * @param array|null $agent Resolved agent descriptor.
 * @return bool
 */
function frontend_agent_chat_user_can_see( ?array $agent ): bool {
	$agent_slug = frontend_agent_chat_get_agent_access_slug( $agent );
	if ( '' === $agent_slug ) {
		return false;
	}

	$minimum_role = class_exists( 'WP_Agent_Access_Grant' ) ? WP_Agent_Access_Grant::ROLE_VIEWER : 'viewer';
	$allowed      = frontend_agent_chat_can_access_agent( $agent_slug, $minimum_role );

	/**
	 * Filter the frontend chat visibility decision.
	 *
	 * @param bool       $allowed Access decision from Agents API.
	 * @param array|null $agent   Resolved agent descriptor.
	 */
	return (bool) apply_filters( 'frontend_agent_chat_user_can_see', $allowed, $agent );
}

/**
 * List accessible Agents API agents for the current request principal.
 *
 * @return array<int,array{agent_slug:string,agent_name:string,agent_description:string,meta:array}>
 */
function frontend_agent_chat_list_accessible_agents(): array {
	$minimum_role = class_exists( 'WP_Agent_Access_Grant' ) ? WP_Agent_Access_Grant::ROLE_VIEWER : 'viewer';
	$result       = frontend_agent_chat_execute_ability( 'agents/list-accessible-agents', array( 'minimum_role' => $minimum_role ) );
	$agents       = is_array( $result ) && is_array( $result['agents'] ?? null ) ? $result['agents'] : array();
	/** @var array<int,array{agent_slug:string,agent_name:string,agent_description:string,meta:array}> $normalized */
	$normalized = array();

	foreach ( $agents as $agent ) {
		$agent = frontend_agent_chat_normalize_agent( is_array( $agent ) ? $agent : array() );
		if ( ! $agent ) {
			continue;
		}

		$normalized[] = $agent;
	}

	/**
	 * Filter the agents exposed in the frontend chat selector.
	 *
	 * Domain plugins can add bundled/public agents when they own access policy
	 * outside a persistent Agents API access store.
	 *
	 * @param array<int,array{agent_slug:string,agent_name:string,agent_description:string,meta:array}> $normalized   Accessible agents.
	 * @param string                                                                                $minimum_role Minimum requested access role.
	 */
	$filtered = apply_filters( 'frontend_agent_chat_accessible_agents', $normalized, $minimum_role );

	return is_array( $filtered ) ? array_values( $filtered ) : $normalized;
}

/**
 * Read the current user's active Data Machine agent preference when available.
 *
 * @return string Active agent slug or empty string.
 */
function frontend_agent_chat_get_active_agent_slug(): string {
	if ( ! is_user_logged_in() ) {
		$preferences = frontend_agent_chat_get_browser_preferences();
		return sanitize_title( (string) ( $preferences['active_agent_slug'] ?? '' ) );
	}

	$result = frontend_agent_chat_execute_ability( 'datamachine/get-active-agent', array() );
	if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] ) ) {
		return '';
	}

	return sanitize_title( (string) ( $result['agent_slug'] ?? '' ) );
}

/**
 * Resolve the default frontend chat agent for the current request.
 *
 * This is intentionally separate from the persisted active agent preference:
 * integrations can make the first-open default match the current page while
 * still allowing users to switch agents in the widget.
 *
 * @param array|null $config Frontend chat configuration, or null to load it.
 * @return string Default agent slug or empty string.
 */
function frontend_agent_chat_get_default_agent_slug( ?array $config = null ): string {
	$config       = is_array( $config ) ? $config : frontend_agent_chat_get_config();
	$default_slug = sanitize_title( (string) ( $config['agent_slug'] ?? '' ) );

	/**
	 * Filter the default frontend chat agent for the current request.
	 *
	 * @param string $default_slug Default agent slug.
	 * @param array  $config       Frontend chat configuration.
	 */
	return sanitize_title( (string) apply_filters( 'frontend_agent_chat_default_agent_slug', $default_slug, $config ) );
}

/**
 * Persist an anonymous browser preference keyed by the browser principal.
 *
 * Logged-in users keep using Data Machine's normal user-owned preference.
 *
 * @param string $agent_slug Active agent slug.
 * @return bool Whether the preference was stored.
 */
function frontend_agent_chat_set_browser_active_agent_slug( string $agent_slug ): bool {
	if ( is_user_logged_in() ) {
		return false;
	}

	$principal = frontend_agent_chat_get_browser_principal();
	if ( ! $principal ) {
		return false;
	}

	return update_option(
		frontend_agent_chat_browser_preferences_option_name( $principal['id'] ),
		array( 'active_agent_slug' => sanitize_title( $agent_slug ) ),
		false
	);
}

/**
 * Read anonymous browser preferences.
 *
 * @return array Browser preference map.
 */
function frontend_agent_chat_get_browser_preferences(): array {
	$principal = frontend_agent_chat_get_browser_principal();
	if ( ! $principal ) {
		return array();
	}

	$preferences = get_option( frontend_agent_chat_browser_preferences_option_name( $principal['id'] ), array() );
	return is_array( $preferences ) ? $preferences : array();
}

/**
 * Build the option name for browser-scoped preferences.
 *
 * @param string $principal_id Stable non-secret browser principal identifier.
 * @return string Option name.
 */
function frontend_agent_chat_browser_preferences_option_name( string $principal_id ): string {
	return 'frontend_agent_chat_browser_preferences_' . md5( $principal_id );
}

/**
 * Ensure an anonymous browser principal cookie exists for this response.
 *
 * @return array|null Browser principal descriptor, or null for logged-in users/failures.
 */
function frontend_agent_chat_ensure_browser_principal_cookie(): ?array {
	if ( is_user_logged_in() ) {
		return null;
	}

	$token = frontend_agent_chat_get_browser_principal_token();
	if ( '' === $token ) {
		$token = wp_generate_password( 64, false, false );
	}

	frontend_agent_chat_set_browser_principal_cookie( $token );
	return frontend_agent_chat_browser_principal_from_token( $token );
}

/**
 * Get the current anonymous browser principal descriptor.
 *
 * @return array|null Browser principal descriptor, or null when unavailable.
 */
function frontend_agent_chat_get_browser_principal(): ?array {
	if ( is_user_logged_in() ) {
		return null;
	}

	$token = frontend_agent_chat_get_browser_principal_token();
	return '' === $token ? null : frontend_agent_chat_browser_principal_from_token( $token );
}

/**
 * Read and sanitize the browser principal token from the HttpOnly cookie.
 *
 * @return string Cookie token, or empty string.
 */
function frontend_agent_chat_get_browser_principal_token(): string {
	$token = isset( $_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] ) : '';
	return preg_match( '/^[A-Za-z0-9]{32,128}$/', $token ) ? $token : '';
}

/**
 * Convert a cookie token into a non-secret principal descriptor.
 *
 * @param string $token Opaque browser token.
 * @return array Browser principal descriptor.
 */
function frontend_agent_chat_browser_principal_from_token( string $token ): array {
	return array(
		'type'    => 'browser',
		'id'      => 'browser:' . hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
		'context' => 'frontend-agent-chat',
	);
}

/**
 * Set the anonymous browser principal cookie.
 *
 * @param string $token Opaque browser token.
 * @return void
 */
function frontend_agent_chat_set_browser_principal_cookie( string $token ): void {
	$secure = (bool) apply_filters( 'frontend_agent_chat_browser_cookie_secure', true );
	$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';

	setcookie(
		FRONTEND_AGENT_CHAT_BROWSER_COOKIE,
		$token,
		array(
			'expires'  => time() + YEAR_IN_SECONDS,
			'path'     => $path,
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);

	$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] = $token;
}

/**
 * Add browser-principal context to ability input when available.
 *
 * @param array $input Ability input.
 * @return array Ability input with browser principal context.
 */
function frontend_agent_chat_add_browser_principal_input( array $input ): array {
	$principal = frontend_agent_chat_get_browser_principal();
	if ( ! $principal ) {
		return $input;
	}

	$agent_slug = sanitize_title( (string) ( $input['agent'] ?? '' ) );

	$input['principal']         = array(
		'acting_user_id'     => 0,
		'effective_agent_id' => '' !== $agent_slug ? $agent_slug : 'frontend-agent-chat',
		'auth_source'        => 'audience',
		'request_context'    => 'rest',
		'client_id'          => 'frontend-agent-chat',
		'audience_id'        => 'browser',
		'audience_claims'    => array(
			'browser_principal_id' => $principal['id'],
		),
		'owner_type'         => 'browser',
		'owner_key'          => $principal['id'],
	);
	$input['browser_principal'] = $principal;
	$input['transcript_owner']  = array(
		'type'  => 'browser',
		'key'   => $principal['id'],
		'label' => 'Browser chat session',
	);
	return $input;
}

/**
 * Resolve the current anonymous browser cookie as an Agents API principal.
 *
 * REST conversation-session abilities intentionally ignore caller-declared
 * principal arrays, so the browser principal must be resolved from the current
 * request before the generic session store can list, read, or delete sessions.
 *
 * @param mixed $resolved        Existing resolved principal.
 * @param array $request_context Agents API principal request context.
 * @return mixed Resolved principal array or existing principal.
 */
function frontend_agent_chat_resolve_browser_execution_principal( $resolved, array $request_context ) {
	if ( null !== $resolved || is_user_logged_in() ) {
		return $resolved;
	}

	if ( 'rest' !== (string) ( $request_context['request_context'] ?? '' ) ) {
		return $resolved;
	}

	$principal = frontend_agent_chat_get_browser_principal();
	if ( ! $principal ) {
		return $resolved;
	}

	$agent_slug = sanitize_title( (string) ( $request_context['agent'] ?? $request_context['agent_slug'] ?? $request_context['effective_agent_id'] ?? '' ) );
	if ( '' === $agent_slug ) {
		$agent_slug = sanitize_title( (string) ( frontend_agent_chat_get_config()['agent_slug'] ?? '' ) );
	}
	if ( '' === $agent_slug ) {
		$agent_slug = 'frontend-agent-chat';
	}

	return array(
		'acting_user_id'     => 0,
		'effective_agent_id' => $agent_slug,
		'auth_source'        => 'audience',
		'request_context'    => 'rest',
		'client_id'          => 'frontend-agent-chat',
		'audience_id'        => 'browser',
		'audience_claims'    => array(
			'browser_principal_id' => $principal['id'],
		),
		'owner_type'         => 'browser',
		'owner_key'          => $principal['id'],
	);
}

/**
 * Allow anonymous browser principals to use their own chat-session store.
 *
 * Agents API's generic session abilities default to logged-in users. The
 * frontend widget scopes anonymous sessions to a signed browser principal, so
 * allow only inputs whose owner fields match the current browser cookie and an
 * agent the current visitor may see.
 *
 * @param bool  $allowed Existing permission decision.
 * @param array $input   Ability input.
 * @return bool Whether the ability may run.
 */
function frontend_agent_chat_allow_browser_conversation_sessions( bool $allowed, array $input ): bool {
	if ( $allowed || is_user_logged_in() ) {
		return $allowed;
	}

	$principal = frontend_agent_chat_get_browser_principal();
	if ( ! $principal ) {
		return false;
	}

	$input_principal  = is_array( $input['principal'] ?? null ) ? $input['principal'] : array();
	$transcript_owner = is_array( $input['transcript_owner'] ?? null ) ? $input['transcript_owner'] : array();
	if (
		'browser' !== (string) ( $input_principal['owner_type'] ?? '' ) ||
		(string) ( $input_principal['owner_key'] ?? '' ) !== $principal['id'] ||
		'browser' !== (string) ( $transcript_owner['type'] ?? '' ) ||
		(string) ( $transcript_owner['key'] ?? '' ) !== $principal['id']
	) {
		return false;
	}

	$agent_slug = sanitize_title( (string) ( $input['agent'] ?? $input_principal['effective_agent_id'] ?? '' ) );
	if ( '' === $agent_slug ) {
		return false;
	}

	return frontend_agent_chat_user_can_see( frontend_agent_chat_resolve_agent( $agent_slug ) );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'agents_api_execution_principal', 'frontend_agent_chat_resolve_browser_execution_principal', 10, 2 );
	add_filter( 'agents_conversation_sessions_permission', 'frontend_agent_chat_allow_browser_conversation_sessions', 10, 2 );
}

/**
 * Normalize an Agents API descriptor for frontend chat use.
 *
 * @param array $agent Raw agent descriptor.
 * @return array{agent_slug:string,agent_name:string,agent_description:string,meta:array}|null Normalized descriptor or null.
 */
function frontend_agent_chat_normalize_agent( array $agent ): ?array {
	$slug = sanitize_title( (string) ( $agent['slug'] ?? $agent['agent_slug'] ?? '' ) );
	if ( '' === $slug ) {
		return null;
	}

	return array(
		'agent_slug'        => $slug,
		'agent_name'        => (string) ( $agent['label'] ?? $agent['agent_name'] ?? $slug ),
		'agent_description' => (string) ( $agent['description'] ?? $agent['agent_description'] ?? '' ),
		'meta'              => is_array( $agent['meta'] ?? null ) ? $agent['meta'] : array(),
	);
}

/**
 * Check agent access through Agents API.
 *
 * @param string $agent_slug   Registered agent slug/id.
 * @param string $minimum_role Minimum access role.
 * @return bool
 */
function frontend_agent_chat_can_access_agent( string $agent_slug, string $minimum_role ): bool {
	$result = frontend_agent_chat_execute_ability(
		'agents/can-access-agent',
		array(
			'agent'        => $agent_slug,
			'minimum_role' => $minimum_role,
		)
	);

	$allowed = ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['allowed'] );

	/**
	 * Filter frontend chat access for a resolved agent.
	 *
	 * @param bool   $allowed      Access decision from Agents API.
	 * @param string $agent_slug   Agent slug/id.
	 * @param string $minimum_role Minimum requested access role.
	 */
	return (bool) apply_filters( 'frontend_agent_chat_can_access_agent', $allowed, $agent_slug, $minimum_role );
}

/**
 * Resolve the registered agent slug/id used by Agents API access checks.
 *
 * @param array|null $agent Resolved agent descriptor.
 * @return string
 */
function frontend_agent_chat_get_agent_access_slug( ?array $agent ): string {
	if ( ! is_array( $agent ) ) {
		return '';
	}

	return sanitize_title( (string) ( $agent['agent_slug'] ?? $agent['slug'] ?? '' ) );
}

/**
 * Resolve an accessible registered agent by slug.
 *
 * @param string $slug Agent slug to resolve.
 * @return array|null Agent descriptor or null.
 */
function frontend_agent_chat_resolve_agent( string $slug ): ?array {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}

	foreach ( frontend_agent_chat_list_accessible_agents() as $agent ) {
		if ( $agent['agent_slug'] !== $slug ) {
			continue;
		}

		return $agent;
	}

	return null;
}

/**
 * Get a required WordPress ability.
 *
 * @param string $name Ability name.
 * @return WP_Ability|WP_Error Ability object or error.
 */
function frontend_agent_chat_get_required_ability( string $name ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
	if ( $ability ) {
		return $ability;
	}

	return new WP_Error(
		'frontend_agent_chat_missing_ability',
		sprintf(
			/* translators: %s: ability name. */
			__( 'The %s ability is not available.', 'frontend-agent-chat' ),
			$name
		),
		array( 'status' => 501 )
	);
}

/**
 * Execute a required Agents API ability.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed|WP_Error
 */
function frontend_agent_chat_execute_ability( string $name, array $input ) {
	$ability = frontend_agent_chat_get_required_ability( $name );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$result = $ability->execute( $input );
	return is_wp_error( $result ) ? $result : $result;
}
