<?php
/**
 * Smoke tests for frontend transcript message filtering.
 *
 * @package FrontendAgentChat\Tests
 */

function frontend_agent_chat_tool_filter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		return true;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/rest.php';

$messages = frontend_agent_chat_session_messages(
	array(
		'messages' => array(
			array(
				'role'    => 'user',
				'type'    => 'text',
				'content' => 'What do you know?',
			),
			array(
				'role'    => 'assistant',
				'type'    => 'tool_call',
				'content' => 'AI ACTION (Turn 1): Executing Data Lookup.',
				'payload' => array(
					'tool_name'  => 'lookup_data',
					'parameters' => array( 'query' => 'status' ),
				),
			),
			array(
				'role'    => 'user',
				'type'    => 'tool_result',
				'content' => 'TOOL RESPONSE (Turn 1): SUCCESS.',
				'payload' => array(
					'tool_name' => 'lookup_data',
					'success'   => true,
					'result'    => array( 'count' => 2 ),
				),
			),
			array(
				'role'    => 'assistant',
				'type'    => 'text',
				'content' => 'Here is the answer.',
			),
		),
	)
);

frontend_agent_chat_tool_filter_assert(
	count( $messages ) === 4 && 'lookup_data' === ( $messages[1]['metadata']['tool_name'] ?? '' ) && true === ( $messages[2]['metadata']['success'] ?? false ),
	'Frontend transcript output should preserve renderable typed tool call/result messages.'
);

echo "Frontend tool transcript message projection smoke passed (1 assertion).\n";
