<?php
/**
 * Smoke tests for chat collapse and structured renderer wiring.
 *
 * @package FrontendAgentChat\Tests
 */

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/AgentChat.tsx' );
$css    = file_get_contents( $root . '/src/agent-chat.css' );
$enqueue = file_get_contents( $root . '/inc/enqueue.php' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( false !== $source && false !== $css && false !== $enqueue, 'sources readable' );
$assert( str_contains( $source, 'collapsible?: boolean' ) && str_contains( $enqueue, "'collapsible'" ), 'collapsible config reaches the React surface' );
$assert( str_contains( $source, 'getQuestionPromptPayloadFromMessage' ) && str_contains( $source, 'normalizePresentQuestionPrompt' ), 'question-shaped messages render through QuestionCard' );
$assert( str_contains( $source, 'renderToolSummaryPayload' ) && str_contains( $css, 'frontend-agent-chat__tool-summary-json' ), 'object tool results render as structured summaries' );
$assert( str_contains( $css, '.frontend-agent-chat.is-inline.is-collapsed' ) && str_contains( $css, '.frontend-agent-chat__collapsed-tab' ), 'inline chat has collapsed styles' );
$assert( str_contains( $source, 'getMostRecentSession( chat.sessions )' ) && str_contains( $source, 'loadSession( latestSession.id )' ), 'latest conversation still restores on startup' );
$assert( str_contains( $source, 'chat.hasResolvedSessions' ) && str_contains( $source, 'chat.isLoadingSessions' ) && str_contains( $source, 'chat.isLoadingTranscript' ), 'session lifecycle signals bound restored-history loading' );
$assert( str_contains( $source, 'Loading previous conversation…' ) && str_contains( $css, '.frontend-agent-chat__session-loading' ), 'restored history is identified while it hydrates' );

echo "Frontend chat rendering/collapse smoke passed (8 assertions).\n";
