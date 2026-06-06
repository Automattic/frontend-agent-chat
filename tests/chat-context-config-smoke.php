<?php
/**
 * Smoke tests for generic chat context config sanitation.
 *
 * @package FrontendAgentChat\Tests
 */

function frontend_agent_chat_context_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/enqueue.php';

$context = frontend_agent_chat_sanitize_chat_context(
	array(
		'brain_slug'       => 'core-brain',
		'source_scope'     => 'wiki <strong>corpus</strong>',
		'source_ids'       => array( 'wiki', 'memory', array( 'skip' ) ),
		'include_restricted_sources' => true,
		'empty_value'      => '',
		'unsupported_json' => array( array( 'nested' => 'skip' ) ),
	)
);

frontend_agent_chat_context_assert( 'core-brain' === ( $context['brain_slug'] ?? '' ), 'Brain slug context is preserved.' );
frontend_agent_chat_context_assert( 'wiki corpus' === ( $context['source_scope'] ?? '' ), 'String context values are sanitized.' );
frontend_agent_chat_context_assert( array( 'wiki', 'memory' ) === ( $context['source_ids'] ?? array() ), 'Scalar context arrays are preserved.' );
frontend_agent_chat_context_assert( true === ( $context['include_restricted_sources'] ?? false ), 'Boolean context values are preserved.' );
frontend_agent_chat_context_assert( array_key_exists( 'unsupported_json', $context ) === false, 'Nested unsupported context is omitted.' );

echo "Frontend chat-context config smoke passed (5 assertions).\n";
