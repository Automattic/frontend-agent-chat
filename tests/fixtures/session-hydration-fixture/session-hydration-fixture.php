<?php
/**
 * Plugin Name: Frontend Agent Chat Session Hydration Fixture
 * Description: Delayed conversation APIs for disposable browser fuzz coverage.
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

add_filter(
	'rest_pre_dispatch',
	static function ( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );
		$route = $request->get_route();
		if ( '/frontend-agent-chat/v1/chat/sessions' === $route ) {
			usleep( 500000 );
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'sessions' => array(
							array(
								'id'           => 'restored-session',
								'session_id'   => 'restored-session',
								'title'        => 'Previous conversation',
								'updated_at'   => '2026-08-19T00:00:00Z',
								'unread_count' => 0,
							),
						),
						'total'  => 1,
						'limit'  => 20,
						'offset' => 0,
					),
				)
			);
		}

		if ( '/frontend-agent-chat/v1/chat' === $route && 'POST' === $request->get_method() ) {
			usleep( 1500000 );
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'session_id' => 'late-send-session',
						'response'   => 'Late fixture reply',
						'metadata'   => array(),
					),
				)
			);
		}

		if ( '/frontend-agent-chat/v1/chat/restored-session' === $route ) {
			usleep( 1500000 );
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'session_id'   => 'restored-session',
						'conversation' => array(
							array(
								'id'         => 'historical-user-message',
								'role'       => 'user',
								'content'    => 'Historical fixture message',
								'created_at' => '2026-08-18T00:00:00Z',
							),
						),
						'metadata'     => array(),
					),
				)
			);
		}

		if ( '/frontend-agent-chat/v1/chat/sessions/restored-session/read' === $route ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'session_id' => 'restored-session',
						'persisted'  => true,
					),
				)
			);
		}

		return $result;
	},
	10,
	3
);

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! wp_has_ability_category( 'session-hydration-fixture' ) ) {
			wp_register_ability_category(
				'session-hydration-fixture',
				array(
					'label'       => 'Session Hydration Fixture',
					'description' => 'Disposable browser fuzz fixture abilities.',
				)
			);
		}
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! wp_has_ability( 'agents/list-accessible-agents' ) ) {
			wp_register_ability(
				'agents/list-accessible-agents',
				array(
					'label'               => 'List Fixture Agents',
					'description'         => 'Lists the disposable hydration fixture agent.',
					'category'            => 'session-hydration-fixture',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'agents' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'agent_slug'        => array( 'type' => 'string' ),
										'agent_name'        => array( 'type' => 'string' ),
										'agent_description' => array( 'type' => 'string' ),
										'meta'              => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
					'execute_callback'    => static function (): array {
						return array(
							'agents' => array(
								array(
									'agent_slug'        => 'hydration-fixture',
									'agent_name'        => 'Hydration Fixture',
									'agent_description' => 'Delayed transcript fixture',
									'meta'              => array(),
								),
							),
						);
					},
					'permission_callback' => '__return_true',
				)
			);
		}
	}
);
