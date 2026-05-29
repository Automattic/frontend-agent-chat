<?php
/**
 * Pure-PHP smoke test for Agents API chat run-control adapter support.
 *
 * Run with: php tests/run-control-smoke.php
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
		return (string) ( $this->params['_route'] ?? '' );
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

class FrontendAgentChatRunControlFakeAbility {
	public function __construct( private string $name ) {}

	public function execute( array $input ) {
		$GLOBALS['frontend_agent_chat_run_control_calls'][] = array( $this->name, $input );

		if ( 'agents/list-accessible-agents' === $this->name ) {
			return array( 'agents' => $GLOBALS['frontend_agent_chat_run_control_agents'] );
		}

		if ( 'agents/can-access-agent' === $this->name ) {
			return array( 'allowed' => true );
		}

		if ( 'agents/get-chat-run' === $this->name ) {
			return array(
				'run_id'     => $input['run_id'],
				'session_id' => $input['session_id'],
				'status'     => 'running',
				'updated_at' => '2026-05-29T00:00:00Z',
				'metadata'   => array( 'source' => 'smoke' ),
			);
		}

		if ( 'agents/cancel-chat-run' === $this->name ) {
			return array(
				'run_id'     => $input['run_id'],
				'session_id' => $input['session_id'],
				'cancelled'  => true,
				'status'     => 'cancelling',
			);
		}

		if ( 'agents/queue-chat-message' === $this->name ) {
			return array(
				'queued_message_id' => 'queued-1',
				'session_id'        => $input['session_id'],
				'run_id'            => 'run-next',
				'position'          => 1,
				'status'            => 'queued',
			);
		}

		return array();
	}
}

function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function sanitize_title( $value ) {
	$value = strtolower( (string) $value );
	$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function add_action() {}
function register_rest_route() {}
function add_filter() {}

function get_option( $name, $default = false ) {
	unset( $name );
	return $default;
}

function is_multisite() {
	return false;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function wp_has_ability( string $name ) {
	return in_array( $name, $GLOBALS['frontend_agent_chat_run_control_abilities'], true );
}

function wp_get_ability( string $name ) {
	return wp_has_ability( $name ) ? new FrontendAgentChatRunControlFakeAbility( $name ) : null;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function is_user_logged_in() {
	return false;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_salt( $scheme = 'auth' ) {
	unset( $scheme );
	return 'frontend-agent-chat-run-control-smoke-salt';
}

function rest_ensure_response( $response ) {
	return $response;
}

require_once dirname( __DIR__ ) . '/inc/config.php';
require_once dirname( __DIR__ ) . '/inc/rest.php';

$failures = array();
$passes   = 0;

function frontend_agent_chat_run_control_assert_equals( $expected, $actual, string $message, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		return;
	}

	$failures[] = $message . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true );
}

echo "frontend-agent-chat-run-control-smoke\n";

$GLOBALS['frontend_agent_chat_run_control_calls']     = array();
$GLOBALS['frontend_agent_chat_run_control_agents']    = array(
	array(
		'slug'        => 'demo-agent',
		'label'       => 'Demo Agent',
		'description' => 'Answers user questions.',
	),
);
$GLOBALS['frontend_agent_chat_run_control_abilities'] = array(
	'agents/list-accessible-agents',
	'agents/can-access-agent',
	'agents/get-chat-run',
	'agents/cancel-chat-run',
	'agents/queue-chat-message',
);

$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] = str_repeat( 'b', 64 );

$capabilities = frontend_agent_chat_get_run_control_capabilities( 'demo-agent' );
frontend_agent_chat_run_control_assert_equals( true, $capabilities['chat_run_status'], 'status capability follows ability availability', $failures, $passes );
frontend_agent_chat_run_control_assert_equals( true, $capabilities['chat_run_cancel'], 'cancel capability follows ability availability', $failures, $passes );
frontend_agent_chat_run_control_assert_equals( true, $capabilities['chat_message_queue'], 'queue capability follows ability availability', $failures, $passes );

$run_response = frontend_agent_chat_rest_get_run( new WP_REST_Request( array( 'run_id' => 'run-1', 'session_id' => 'session-1', 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_run_control_assert_equals( 'running', $run_response['data']['status'] ?? '', 'run status is normalized', $failures, $passes );

$cancel_response = frontend_agent_chat_rest_cancel_run( new WP_REST_Request( array( 'run_id' => 'run-1', 'session_id' => 'session-1', 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_run_control_assert_equals( true, $cancel_response['data']['cancelled'] ?? false, 'cancel response is normalized', $failures, $passes );

$queue_response = frontend_agent_chat_rest_queue_message( new WP_REST_Request( array( 'message' => 'next', 'session_id' => 'session-1', 'run_id' => 'run-1', 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_run_control_assert_equals( 'queued-1', $queue_response['data']['queued_message_id'] ?? '', 'queue response is normalized', $failures, $passes );

$last_call = end( $GLOBALS['frontend_agent_chat_run_control_calls'] );
frontend_agent_chat_run_control_assert_equals( 'agents/queue-chat-message', $last_call[0] ?? '', 'queue route calls canonical ability', $failures, $passes );
frontend_agent_chat_run_control_assert_equals( 'browser', $last_call[1]['transcript_owner']['type'] ?? '', 'queue preserves browser owner', $failures, $passes );
frontend_agent_chat_run_control_assert_equals( 'run-1', $last_call[1]['run_id'] ?? '', 'queue forwards active run id', $failures, $passes );

$GLOBALS['frontend_agent_chat_run_control_abilities'] = array( 'agents/list-accessible-agents', 'agents/can-access-agent' );
$capabilities = frontend_agent_chat_get_run_control_capabilities( 'demo-agent' );
frontend_agent_chat_run_control_assert_equals( false, $capabilities['chat_run_status'], 'missing upstream ability disables status capability', $failures, $passes );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Frontend run-control smoke passed ({$passes} assertions).\n";
