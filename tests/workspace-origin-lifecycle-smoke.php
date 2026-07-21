<?php
/**
 * Pure-PHP smoke test for workspace lifecycle and pending-action origin seams.
 *
 * Run with: php tests/workspace-origin-lifecycle-smoke.php
 *
 * @package FrontendAgentChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE' ) ) {
	define( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE', 'frontend_agent_chat_browser' );
}

class WP_Error {
	public function __construct( public string $code, public string $message = '', public array $data = array() ) {}
}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
	public const DELETABLE = 'DELETE';
}

class WP_REST_Response {}

class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array() ) {}

	public function get_param( string $name ) {
		return $this->params[ $name ] ?? null;
	}

	public function get_route(): string {
		return '';
	}

	public function offsetExists( mixed $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->params[ $offset ] ?? null;
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->params[ $offset ] = $value;
	}

	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

class FrontendAgentChatWorkspaceFakeAbility {
	public function __construct( private string $name ) {}

	public function execute( array $input ) {
		$GLOBALS['frontend_agent_chat_workspace_calls'][] = array( $this->name, $input );

		return match ( $this->name ) {
			'agents/chat' => array( 'session_id' => 'session-1', 'messages' => array() ),
			'agents/queue-chat-message' => array( 'queued_message_id' => 'queued-1', 'session_id' => 'session-1', 'run_id' => 'run-1' ),
			'agents/list-conversation-sessions' => array( 'sessions' => array() ),
			'agents/get-conversation-session' => array( 'session' => array( 'session_id' => 'session-1', 'messages' => array() ) ),
			'agents/delete-conversation-session' => array( 'deleted' => true ),
			'agents/update-conversation-session-title' => array( 'session' => array( 'session_id' => 'session-1', 'title' => 'Renamed' ) ),
			'agents/get-chat-run' => array( 'run_id' => 'run-1', 'session_id' => 'session-1', 'status' => 'running' ),
			'agents/list-chat-run-events' => array( 'run_id' => 'run-1', 'session_id' => 'session-1', 'status' => 'running', 'events' => array() ),
			'agents/cancel-chat-run' => array( 'run_id' => 'run-1', 'session_id' => 'session-1', 'status' => 'cancelling', 'cancelled' => true ),
			'agents/resolve-pending-action' => array( 'action_id' => 'action-1', 'decision' => 'accepted' ),
			default => array(),
		};
	}
}

function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function sanitize_title( $value ) {
	return trim( preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $value ) ), '-' );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function apply_filters( $hook, $value ) {
	$args = func_get_args();
	if ( 'frontend_agent_chat_ability_input' === $hook ) {
		$value['workspace'] = $GLOBALS['frontend_agent_chat_trusted_workspace'];
		return $value;
	}

	if ( 'frontend_agent_chat_pending_action_resolve_input' === $hook ) {
		$GLOBALS['frontend_agent_chat_received_origin'] = $args[3] ?? array();
		$value['context'] = $GLOBALS['frontend_agent_chat_trusted_context'];
		return $value;
	}

	return $value;
}

function add_action() {}
function register_rest_route() {}
function add_filter() {}

function get_option( $name, $default = false ) {
	return 'frontend_agent_chat_config' === $name ? array( 'default_agent_slug' => 'demo-agent' ) : $default;
}

function is_multisite() {
	return false;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function wp_get_ability( string $name ) {
	return new FrontendAgentChatWorkspaceFakeAbility( $name );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function is_user_logged_in() {
	return false;
}

function get_current_user_id() {
	return 0;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_salt( $scheme = 'auth' ) {
	unset( $scheme );
	return 'frontend-agent-chat-workspace-smoke-salt';
}

function rest_ensure_response( $response ) {
	return $response;
}

require_once dirname( __DIR__ ) . '/inc/config.php';
require_once dirname( __DIR__ ) . '/inc/rest.php';

$failures = array();
$passes   = 0;

function frontend_agent_chat_workspace_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	$failures[] = $message;
}

echo "frontend-agent-chat-workspace-origin-lifecycle-smoke\n";

$GLOBALS['frontend_agent_chat_workspace_calls']    = array();
$GLOBALS['frontend_agent_chat_trusted_workspace']  = array( 'workspace_type' => 'network', 'workspace_id' => 'principal-7' );
$GLOBALS['frontend_agent_chat_trusted_context']    = array( 'wordpress' => array( 'blog_id' => 7 ) );
$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ]     = str_repeat( 'c', 64 );

frontend_agent_chat_rest_send_message( new WP_REST_Request( array( 'message' => 'hello', 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_rest_queue_message( new WP_REST_Request( array( 'message' => 'next', 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_rest_list_sessions( new WP_REST_Request( array( 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_rest_get_session( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );
frontend_agent_chat_rest_update_session_title( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'title' => 'Renamed' ) ) );
frontend_agent_chat_rest_get_run( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_rest_list_run_events( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_rest_cancel_run( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_rest_delete_session( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );

foreach ( $GLOBALS['frontend_agent_chat_workspace_calls'] as [ $ability, $input ] ) {
	frontend_agent_chat_workspace_assert(
		$GLOBALS['frontend_agent_chat_trusted_workspace'] === ( $input['workspace'] ?? null ),
		$ability . ' did not receive the configured workspace',
		$failures,
		$passes
	);
}

$forged_origin = array(
	'workspace' => array( 'workspace_type' => 'site', 'workspace_id' => 'forged' ),
	'context'   => array( 'wordpress' => array( 'blog_id' => 999 ) ),
	'metadata'  => array( 'opaque' => 'preserved' ),
);
frontend_agent_chat_rest_resolve_pending_action(
	new WP_REST_Request(
		array(
			'action_id' => 'action-1',
			'decision'  => 'accepted',
			'origin'    => $forged_origin,
			'context'   => array( 'wordpress' => array( 'blog_id' => 1000 ) ),
		)
	)
);

$resolve_call = end( $GLOBALS['frontend_agent_chat_workspace_calls'] );
frontend_agent_chat_workspace_assert( $forged_origin === $GLOBALS['frontend_agent_chat_received_origin'], 'Canonical approval origin was not preserved for server validation', $failures, $passes );
frontend_agent_chat_workspace_assert( $GLOBALS['frontend_agent_chat_trusted_workspace'] === ( $resolve_call[1]['workspace'] ?? null ), 'Forged workspace overrode configured workspace', $failures, $passes );
frontend_agent_chat_workspace_assert( $GLOBALS['frontend_agent_chat_trusted_context'] === ( $resolve_call[1]['context'] ?? null ), 'Forged context overrode server-owned resolver context', $failures, $passes );

$source = file_get_contents( dirname( __DIR__ ) . '/src/AgentChat.tsx' );
frontend_agent_chat_workspace_assert( false !== strpos( $source, "[ 'workspace', 'context', 'metadata' ]" ), 'Client does not preserve canonical pending-action origin fields', $failures, $passes );
frontend_agent_chat_workspace_assert( false !== strpos( $source, 'data: { action_id: actionId, decision, origin }' ), 'Client does not return pending-action origin for server validation', $failures, $passes );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Frontend workspace/origin lifecycle smoke passed ({$passes} assertions).\n";
