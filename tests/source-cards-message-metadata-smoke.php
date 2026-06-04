<?php
/**
 * Smoke tests for citation/source-card message metadata normalization.
 *
 * @package FrontendAgentChat\Tests
 */

function frontend_agent_chat_source_cards_assert( bool $condition, string $message ): void {
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
				'role'     => 'assistant',
				'content'  => 'Here is the answer.',
				'metadata' => array(
					'citations' => array(
						array(
							'title'       => 'Public docs',
							'url'         => 'https://example.com/docs',
							'snippet'     => 'A short excerpt from the source.',
							'source_id'   => 'source-1',
							'item_id'     => 'item-123',
							'fragment_id' => 'fragment-4',
						),
					),
				),
			),
		),
	)
);

frontend_agent_chat_source_cards_assert( 3 === count( $messages ), 'Citation metadata should add a source-card tool pair.' );
frontend_agent_chat_source_cards_assert( 'assistant' === $messages[0]['role'], 'Original assistant message remains first.' );
frontend_agent_chat_source_cards_assert( 'tool_call' === ( $messages[1]['metadata']['type'] ?? '' ), 'Source cards include a tool call envelope.' );
frontend_agent_chat_source_cards_assert( 'source_cards' === ( $messages[1]['metadata']['tool_name'] ?? '' ), 'Source-card tool name is generic.' );
frontend_agent_chat_source_cards_assert( 'Public docs' === ( $messages[1]['metadata']['parameters']['sources'][0]['title'] ?? '' ), 'Source title is normalized.' );
frontend_agent_chat_source_cards_assert( 'https://example.com/docs' === ( $messages[1]['metadata']['parameters']['sources'][0]['url'] ?? '' ), 'Source URL is normalized.' );
frontend_agent_chat_source_cards_assert( 'source-1' === ( $messages[1]['metadata']['parameters']['sources'][0]['source_id'] ?? '' ), 'Source id is preserved.' );
frontend_agent_chat_source_cards_assert( 'item-123' === ( $messages[1]['metadata']['parameters']['sources'][0]['item_id'] ?? '' ), 'Item id is preserved.' );
frontend_agent_chat_source_cards_assert( 'tool_result' === ( $messages[2]['metadata']['type'] ?? '' ), 'Source cards include a tool result envelope.' );

$tool_result = json_decode( (string) $messages[2]['content'], true );
frontend_agent_chat_source_cards_assert( is_array( $tool_result ), 'Source-card result content is JSON.' );
frontend_agent_chat_source_cards_assert( 'fragment-4' === ( $tool_result['sources'][0]['fragment_id'] ?? '' ), 'Fragment id survives session reload result payload.' );

echo "Frontend source-card metadata smoke passed (11 assertions).\n";
