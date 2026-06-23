<?php
/**
 * Smoke tests for frontend chat header controls config sanitation.
 *
 * @package FrontendAgentChat\Tests
 */

function frontend_agent_chat_header_controls_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/enqueue.php';

$defaults = frontend_agent_chat_sanitize_header_controls( null );
frontend_agent_chat_header_controls_assert( true === $defaults['agentSelector'], 'Agent selector defaults on.' );
frontend_agent_chat_header_controls_assert( true === $defaults['sessionControls'], 'Session controls default on.' );
frontend_agent_chat_header_controls_assert( true === $defaults['expandButton'], 'Expand button defaults on.' );
frontend_agent_chat_header_controls_assert( true === $defaults['closeButton'], 'Close button defaults on.' );

$controls = frontend_agent_chat_sanitize_header_controls(
	array(
		'agent_selector'    => false,
		'session_controls' => false,
		'expand_button'    => false,
		'close_button'     => false,
	)
);

frontend_agent_chat_header_controls_assert( false === $controls['agentSelector'], 'Agent selector can be disabled.' );
frontend_agent_chat_header_controls_assert( false === $controls['sessionControls'], 'Session controls can be disabled.' );
frontend_agent_chat_header_controls_assert( false === $controls['expandButton'], 'Expand button can be disabled.' );
frontend_agent_chat_header_controls_assert( false === $controls['closeButton'], 'Close button can be disabled.' );

echo "Frontend header-controls config smoke passed (8 assertions).\n";
