<?php
/**
 * Smoke checks for answered question card collapse behavior.
 *
 * @package FrontendAgentChat
 */

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/AgentChat.tsx' );
$css    = file_get_contents( $root . '/src/agent-chat.css' );

$assert = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}

	fwrite( STDOUT, "PASS: {$label}\n" );
};

$assert( false !== $source, 'agent-chat-source-readable' );
$assert( false !== $css, 'agent-chat-css-readable' );
$assert( str_contains( $source, 'const [ answeredQuestions, setAnsweredQuestions ]' ), 'question-card-tracks-answered-state' );
$assert( str_contains( $source, 'setAnsweredQuestions( ( current ) => ( {' ), 'question-card-records-selected-answer' );
$assert( str_contains( $source, 'isAnswered: ( groupId ) => answeredQuestions[ groupId ]' ), 'question-card-renderer-receives-answered-state' );
$assert( str_contains( $source, "'frontend-agent-chat__question-card is-answered'" ), 'answered-question-card-uses-collapsed-class' );
$assert( str_contains( $source, "__( 'Answered: %s', 'frontend-agent-chat' )" ), 'answered-question-card-renders-answer-summary' );
$assert( str_contains( $source, 'setAnsweredQuestions( {} );' ) && str_contains( $source, '[ chat.sessionId ]' ), 'answered-question-state-resets-on-session-change' );
$assert( str_contains( $css, '.frontend-agent-chat__question-card.is-answered' ), 'answered-question-card-has-collapsed-styles' );
$assert( str_contains( $css, '.frontend-agent-chat__question-answer' ), 'answered-question-summary-is-styled' );

echo "Question card collapse smoke passed.\n";
