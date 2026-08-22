<?php
/**
 * Plugin Name: Frontend Agent Chat Session Hydration Fixture
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'frontend_agent_chat_config',
	static function ( array $config ): array {
		$config['enabled']    = true;
		$config['agent_slug'] = 'hydration-fixture';
		$config['fab_label']  = 'Fixture Chat';
		return $config;
	}
);

add_filter( 'frontend_agent_chat_can_access_agent', '__return_true' );

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! wp_has_ability_category( 'session-hydration-fixture' ) ) {
			wp_register_ability_category( 'session-hydration-fixture', array( 'label' => 'Session hydration fixture' ) );
		}
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( 'agents/list-accessible-agents' ) ) {
			return;
		}
		wp_register_ability(
			'agents/list-accessible-agents',
			array(
				'label'               => 'List fixture agents',
				'description'         => 'Lists the hydration fixture agent.',
				'category'            => 'session-hydration-fixture',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function (): array {
					return array( 'agents' => array( array( 'agent_slug' => 'hydration-fixture', 'agent_name' => 'Hydration Fixture', 'agent_description' => 'Delayed transcript fixture' ) ) );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

add_filter(
	'rest_pre_dispatch',
	static function ( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );
		$route = $request->get_route();
		if ( '/frontend-agent-chat/v1/chat/sessions' === $route ) {
			usleep( 500000 );
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'sessions' => array( array( 'id' => 'restored-session', 'title' => 'Previous conversation', 'updated_at' => '2026-08-19T00:00:00Z' ) ) ) ) );
		}
		if ( '/frontend-agent-chat/v1/chat/restored-session' === $route ) {
			usleep( 1500000 );
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'session_id' => 'restored-session', 'conversation' => array( array( 'id' => 'historical-user-message', 'role' => 'user', 'content' => 'Historical fixture message' ) ) ) ) );
		}
		return $result;
	},
	10,
	3
);
