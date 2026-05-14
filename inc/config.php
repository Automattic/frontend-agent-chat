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
		'agent_slug'       => '',
		'description'      => __( 'Your AI assistant.', 'frontend-agent-chat' ),
		'enabled'          => false,
		'loading_messages' => true,
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
 * Check whether the current user can see the chat widget.
 *
 * @param array|null $agent Resolved agent descriptor.
 * @return bool
 */
function frontend_agent_chat_user_can_see( ?array $agent ): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

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
 * List accessible Agents API agents for the current user.
 *
 * @return array<int,array{agent_slug:string,agent_name:string,agent_description:string,meta:array}>
 */
function frontend_agent_chat_list_accessible_agents(): array {
	$minimum_role = class_exists( 'WP_Agent_Access_Grant' ) ? WP_Agent_Access_Grant::ROLE_VIEWER : 'viewer';
	$result       = frontend_agent_chat_execute_ability( 'agents/list-accessible-agents', array( 'minimum_role' => $minimum_role ) );
	$agents       = is_array( $result ) && is_array( $result['agents'] ?? null ) ? $result['agents'] : array();
	$normalized   = array();

	foreach ( $agents as $agent ) {
		$agent = frontend_agent_chat_normalize_agent( is_array( $agent ) ? $agent : array() );
		if ( ! $agent ) {
			continue;
		}

		$normalized[] = $agent;
	}

	return $normalized;
}

/**
 * Normalize an Agents API descriptor for frontend chat use.
 *
 * @param array $agent Raw agent descriptor.
 * @return array|null Normalized descriptor or null.
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

	return ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['allowed'] );
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
