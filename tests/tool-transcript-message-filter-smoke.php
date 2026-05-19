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
			),
			array(
				'role'    => 'user',
				'type'    => 'tool_result',
				'content' => 'TOOL RESPONSE (Turn 1): SUCCESS.',
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
	array(
		array(
			'role'    => 'user',
			'content' => 'What do you know?',
		),
		array(
			'role'    => 'assistant',
			'content' => 'Here is the answer.',
		),
	) === $messages,
	'Frontend transcript output should omit typed tool call/result messages.'
);

echo "Frontend tool transcript message filter smoke passed (1 assertion).\n";
