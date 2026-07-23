<?php
/**
 * Stateful adapter smoke test for workspace lifecycle and approval origin.
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

	public function get_error_code(): string {
		return $this->code;
	}
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

/**
 * Stateful host adapter behind the real FAC ability execution path.
 *
 * It enforces the same workspace boundary expected from canonical abilities,
 * so route tests prove behavior rather than only inspecting forwarded input.
 */
class FrontendAgentChatWorkspaceAbility {
	public function __construct( private string $name ) {}

	public function execute( array $input ) {
		$GLOBALS['frontend_agent_chat_workspace_calls'][] = array( $this->name, $input );
		$workspace = is_array( $input['workspace'] ?? null ) ? $input['workspace'] : array();

		if ( 'agents/chat' === $this->name ) {
			$session_id = (string) ( $input['session_id'] ?? '' );
			if ( '' === $session_id ) {
				$session_id = 'session-1';
				$GLOBALS['frontend_agent_chat_sessions'][ $session_id ] = self::session( $session_id, $workspace );
			}
			if ( ! self::owns( $session_id, $workspace ) ) {
				return new WP_Error( 'workspace_mismatch' );
			}
			return array( 'session_id' => $session_id, 'messages' => $GLOBALS['frontend_agent_chat_sessions'][ $session_id ]['messages'] );
		}

		if ( 'agents/list-conversation-sessions' === $this->name ) {
			return array(
				'sessions' => array_values(
					array_filter(
						$GLOBALS['frontend_agent_chat_sessions'],
						static fn( array $session ): bool => self::workspace_matches( $session, $workspace )
					)
				),
			);
		}

		$session_id = (string) ( $input['session_id'] ?? '' );
		if ( in_array( $this->name, self::session_abilities(), true ) && ! self::owns( $session_id, $workspace ) ) {
			return new WP_Error( 'workspace_mismatch' );
		}

		return match ( $this->name ) {
			'agents/queue-chat-message' => self::queue( $session_id, $input ),
			'agents/get-conversation-session' => array( 'session' => $GLOBALS['frontend_agent_chat_sessions'][ $session_id ] ),
			'agents/mark-conversation-session-read' => self::mark_read( $session_id ),
			'agents/delete-conversation-session' => self::delete( $session_id ),
			'agents/update-conversation-session-title' => self::title( $session_id, (string) ( $input['title'] ?? '' ) ),
			'agents/get-chat-run' => self::run( $session_id, $input, 'running' ),
			'agents/list-chat-run-events' => self::events( $session_id, $input ),
			'agents/cancel-chat-run' => self::cancel( $session_id, $input ),
			'agents/resolve-pending-action' => array( 'action_id' => 'action-1', 'decision' => 'accepted' ),
			default => array(),
		};
	}

	/** @return array<int,string> */
	private static function session_abilities(): array {
		return array(
			'agents/queue-chat-message',
			'agents/get-conversation-session',
			'agents/mark-conversation-session-read',
			'agents/delete-conversation-session',
			'agents/update-conversation-session-title',
			'agents/get-chat-run',
			'agents/list-chat-run-events',
			'agents/cancel-chat-run',
		);
	}

	private static function session( string $session_id, array $workspace ): array {
		return array(
			'session_id'     => $session_id,
			'workspace_type' => (string) ( $workspace['workspace_type'] ?? '' ),
			'workspace_id'   => (string) ( $workspace['workspace_id'] ?? '' ),
			'title'          => '',
			'messages'       => array( array( 'role' => 'user', 'content' => 'hello' ) ),
			'metadata'       => array(),
		);
	}

	private static function workspace_matches( array $session, array $workspace ): bool {
		return ( $session['workspace_type'] ?? '' ) === ( $workspace['workspace_type'] ?? '' )
			&& ( $session['workspace_id'] ?? '' ) === ( $workspace['workspace_id'] ?? '' );
	}

	private static function owns( string $session_id, array $workspace ): bool {
		$session = $GLOBALS['frontend_agent_chat_sessions'][ $session_id ] ?? null;
		return is_array( $session ) && self::workspace_matches( $session, $workspace );
	}

	private static function queue( string $session_id, array $input ): array {
		$GLOBALS['frontend_agent_chat_sessions'][ $session_id ]['messages'][] = array( 'role' => 'user', 'content' => (string) ( $input['message'] ?? '' ) );
		return array( 'queued_message_id' => 'queued-1', 'session_id' => $session_id, 'run_id' => 'run-1' );
	}

	private static function mark_read( string $session_id ): array {
		$last_read_at = '2026-07-21T12:00:00Z';
		$GLOBALS['frontend_agent_chat_sessions'][ $session_id ]['last_read_at'] = $last_read_at;
		return array( 'persisted' => true, 'last_read_at' => $last_read_at );
	}

	private static function delete( string $session_id ): array {
		unset( $GLOBALS['frontend_agent_chat_sessions'][ $session_id ] );
		return array( 'deleted' => true );
	}

	private static function title( string $session_id, string $title ): array {
		$GLOBALS['frontend_agent_chat_sessions'][ $session_id ]['title'] = $title;
		return array( 'session' => $GLOBALS['frontend_agent_chat_sessions'][ $session_id ] );
	}

	private static function run( string $session_id, array $input, string $status ): array {
		return array( 'run_id' => (string) ( $input['run_id'] ?? '' ), 'session_id' => $session_id, 'status' => $status );
	}

	private static function events( string $session_id, array $input ): array {
		return self::run( $session_id, $input, 'running' ) + array( 'events' => array(), 'cursor' => '', 'has_more' => false );
	}

	private static function cancel( string $session_id, array $input ): array {
		return self::run( $session_id, $input, 'cancelling' ) + array( 'cancelled' => true );
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

function wp_html_excerpt( string $text, int $count, string $more = '' ): string {
	return strlen( $text ) > $count ? substr( $text, 0, $count ) . $more : $text;
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

function wp_has_ability( string $name ): bool {
	return in_array( $name, $GLOBALS['frontend_agent_chat_available_abilities'], true );
}

function wp_get_ability( string $name ) {
	return wp_has_ability( $name ) ? new FrontendAgentChatWorkspaceAbility( $name ) : null;
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
$GLOBALS['frontend_agent_chat_available_abilities'] = array(
	'agents/chat',
	'agents/queue-chat-message',
	'agents/list-conversation-sessions',
	'agents/get-conversation-session',
	'agents/delete-conversation-session',
	'agents/update-conversation-session-title',
	'agents/get-chat-run',
	'agents/list-chat-run-events',
	'agents/cancel-chat-run',
	'agents/resolve-pending-action',
);
$GLOBALS['frontend_agent_chat_sessions'] = array(
	'other-workspace' => array(
		'session_id'     => 'other-workspace',
		'workspace_type' => 'site',
		'workspace_id'   => 'forged',
		'title'          => 'Isolated',
		'messages'       => array(),
		'metadata'       => array(),
	),
);
$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] = str_repeat( 'c', 64 );

$created = frontend_agent_chat_rest_send_message( new WP_REST_Request( array( 'message' => 'hello', 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_workspace_assert( 'session-1' === ( $created['data']['session_id'] ?? '' ), 'Chat creation did not return the workspace-owned session', $failures, $passes );
frontend_agent_chat_workspace_assert( 'principal-7' === ( $GLOBALS['frontend_agent_chat_sessions']['session-1']['workspace_id'] ?? '' ), 'Chat creation did not persist the configured workspace', $failures, $passes );

$queued = frontend_agent_chat_rest_queue_message( new WP_REST_Request( array( 'message' => 'next', 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_workspace_assert( 'queued-1' === ( $queued['data']['queued_message_id'] ?? '' ), 'Queue did not resolve the workspace-owned session', $failures, $passes );

$listed = frontend_agent_chat_rest_list_sessions( new WP_REST_Request( array( 'agent' => 'demo-agent' ) ) );
frontend_agent_chat_workspace_assert( 1 === ( $listed['data']['total'] ?? 0 ), 'List leaked a session from another workspace', $failures, $passes );
frontend_agent_chat_workspace_assert( 'session-1' === ( $listed['data']['sessions'][0]['session_id'] ?? '' ), 'List did not return the workspace-owned session', $failures, $passes );
frontend_agent_chat_workspace_assert( 'session-1' === ( $listed['data']['sessions'][0]['id'] ?? '' ), 'List did not project the canonical session ID for Agenttic', $failures, $passes );

$loaded = frontend_agent_chat_rest_get_session( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );
frontend_agent_chat_workspace_assert( 'session-1' === ( $loaded['data']['session_id'] ?? '' ), 'Get did not load the workspace-owned session', $failures, $passes );
$blocked = frontend_agent_chat_rest_get_session( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'other-workspace' ) ) );
frontend_agent_chat_workspace_assert( $blocked instanceof WP_Error && 'workspace_mismatch' === $blocked->get_error_code(), 'Get crossed the configured workspace boundary', $failures, $passes );

$read = frontend_agent_chat_rest_mark_session_read( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );
frontend_agent_chat_workspace_assert( false === ( $read['success'] ?? true ) && false === ( $read['data']['persisted'] ?? true ), 'Unavailable read-state capability reported fake persistence', $failures, $passes );
frontend_agent_chat_workspace_assert( str_contains( (string) ( $read['data']['dependency'] ?? '' ), 'agents-api/issues/448' ), 'Read-state response omitted its owning substrate dependency', $failures, $passes );
$GLOBALS['frontend_agent_chat_available_abilities'][] = 'agents/mark-conversation-session-read';
$read = frontend_agent_chat_rest_mark_session_read( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );
frontend_agent_chat_workspace_assert( true === ( $read['success'] ?? false ) && true === ( $read['data']['persisted'] ?? false ), 'Available canonical read-state capability was not executed', $failures, $passes );
frontend_agent_chat_workspace_assert( '2026-07-21T12:00:00Z' === ( $GLOBALS['frontend_agent_chat_sessions']['session-1']['last_read_at'] ?? '' ), 'Canonical read-state capability did not mutate the workspace-owned session', $failures, $passes );

$renamed = frontend_agent_chat_rest_update_session_title( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'title' => 'Renamed' ) ) );
frontend_agent_chat_workspace_assert( 'Renamed' === ( $renamed['data']['title'] ?? '' ), 'Title update did not mutate the workspace-owned session', $failures, $passes );

$run = frontend_agent_chat_rest_get_run( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_workspace_assert( 'running' === ( $run['data']['status'] ?? '' ), 'Run status did not resolve the workspace-owned session', $failures, $passes );
$events = frontend_agent_chat_rest_list_run_events( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_workspace_assert( 'running' === ( $events['data']['status'] ?? '' ), 'Run events did not resolve the workspace-owned session', $failures, $passes );
$cancelled = frontend_agent_chat_rest_cancel_run( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1', 'run_id' => 'run-1' ) ) );
frontend_agent_chat_workspace_assert( true === ( $cancelled['data']['cancelled'] ?? false ), 'Run cancellation did not resolve the workspace-owned session', $failures, $passes );

$deleted = frontend_agent_chat_rest_delete_session( new WP_REST_Request( array( 'agent' => 'demo-agent', 'session_id' => 'session-1' ) ) );
frontend_agent_chat_workspace_assert( true === ( $deleted['data']['deleted'] ?? false ) && ! isset( $GLOBALS['frontend_agent_chat_sessions']['session-1'] ), 'Delete did not remove the workspace-owned session', $failures, $passes );
frontend_agent_chat_workspace_assert( isset( $GLOBALS['frontend_agent_chat_sessions']['other-workspace'] ), 'Delete crossed into another workspace', $failures, $passes );

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

foreach ( $GLOBALS['frontend_agent_chat_workspace_calls'] as [ $ability, $input ] ) {
	frontend_agent_chat_workspace_assert(
		$GLOBALS['frontend_agent_chat_trusted_workspace'] === ( $input['workspace'] ?? null ),
		$ability . ' did not receive the configured workspace',
		$failures,
		$passes
	);
}

$source = file_get_contents( dirname( __DIR__ ) . '/src/AgentChat.tsx' );
frontend_agent_chat_workspace_assert( false !== strpos( $source, "[ 'workspace', 'context', 'metadata' ]" ), 'Client does not preserve canonical pending-action origin fields', $failures, $passes );
frontend_agent_chat_workspace_assert( false !== strpos( $source, 'data: { action_id: actionId, decision, origin }' ), 'Client does not return pending-action origin for server validation', $failures, $passes );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Frontend workspace/origin lifecycle smoke passed ({$passes} assertions).\n";
