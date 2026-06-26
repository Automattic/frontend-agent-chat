<?php
/**
 * Pure-PHP smoke test for principal-based frontend visibility.
 *
 * Run with: php tests/principal-access-smoke.php
 *
 * @package FrontendAgentChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE' ) ) {
	define( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE', 'frontend_agent_chat_browser' );
}

class WP_Agent_Access_Grant {
	public const ROLE_VIEWER = 'viewer';
}

class FrontendAgentChatFakeAbility {
	public function __construct( private string $name ) {}

	public function execute( array $input ) {
		$GLOBALS['frontend_agent_chat_smoke_calls'][] = array( $this->name, $input );

		if ( 'agents/list-accessible-agents' === $this->name ) {
			return array( 'agents' => $GLOBALS['frontend_agent_chat_smoke_agents'] );
		}

		if ( 'agents/can-access-agent' === $this->name ) {
			return array( 'allowed' => $GLOBALS['frontend_agent_chat_smoke_allowed'] );
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

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

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

function wp_get_ability( string $name ) {
	return new FrontendAgentChatFakeAbility( $name );
}

function is_wp_error( $value ) {
	return false;
}

function is_user_logged_in() {
	return false;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_salt( $scheme = 'auth' ) {
	unset( $scheme );
	return 'frontend-agent-chat-smoke-salt';
}

require_once dirname( __DIR__ ) . '/inc/config.php';

$failures = array();
$passes   = 0;

function frontend_agent_chat_smoke_assert_equals( $expected, $actual, string $message, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		return;
	}

	$failures[] = $message . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true );
}

echo "frontend-agent-chat-principal-access-smoke\n";

$config = frontend_agent_chat_get_config();
frontend_agent_chat_smoke_assert_equals( 'Agent Chat', $config['fab_label'] ?? '', 'frontend chat has generic default FAB label', $failures, $passes );
frontend_agent_chat_smoke_assert_equals( 'AI', $config['fab_icon'] ?? '', 'frontend chat has generic default FAB icon', $failures, $passes );

$GLOBALS['frontend_agent_chat_smoke_calls']   = array();
$GLOBALS['frontend_agent_chat_smoke_allowed'] = true;
$GLOBALS['frontend_agent_chat_smoke_agents']  = array(
	array(
		'slug'        => 'demo-agent',
		'label'       => 'Demo Agent',
		'description' => 'Answers user questions.',
	),
);

$agents = frontend_agent_chat_list_accessible_agents();
frontend_agent_chat_smoke_assert_equals( 'demo-agent', $agents[0]['agent_slug'] ?? '', 'accessible agents normalize from Agents API ability', $failures, $passes );
frontend_agent_chat_smoke_assert_equals( true, frontend_agent_chat_user_can_see( $agents[0] ), 'visibility delegates to Agents API access without requiring a WP login', $failures, $passes );
frontend_agent_chat_smoke_assert_equals( false, frontend_agent_chat_browser_cookie_secure_default(), 'browser principal cookie is not secure by default on non-SSL requests', $failures, $passes );

$GLOBALS['frontend_agent_chat_smoke_allowed'] = false;
frontend_agent_chat_smoke_assert_equals( false, frontend_agent_chat_user_can_see( $agents[0] ), 'visibility fails closed when Agents API denies the current principal', $failures, $passes );

$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] = str_repeat( 'a', 64 );
$GLOBALS['frontend_agent_chat_smoke_allowed']  = true;
$list_input                                    = frontend_agent_chat_add_browser_principal_input( array( 'agent' => 'demo-agent' ) );
frontend_agent_chat_smoke_assert_equals( true, frontend_agent_chat_allow_browser_conversation_sessions( false, $list_input ), 'browser principal may list its own conversation sessions', $failures, $passes );

$tampered_input                                    = $list_input;
$tampered_input['transcript_owner']['key']         = 'browser:tampered';
frontend_agent_chat_smoke_assert_equals( false, frontend_agent_chat_allow_browser_conversation_sessions( false, $tampered_input ), 'browser principal cannot list another browser owner', $failures, $passes );

$GLOBALS['frontend_agent_chat_smoke_allowed'] = false;
frontend_agent_chat_smoke_assert_equals( false, frontend_agent_chat_allow_browser_conversation_sessions( false, $list_input ), 'browser session ability permission still requires agent access', $failures, $passes );

$GLOBALS['frontend_agent_chat_smoke_agents'] = array();
frontend_agent_chat_smoke_assert_equals( array(), frontend_agent_chat_list_accessible_agents(), 'no accessible principal grants means no agents', $failures, $passes );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Frontend principal access smoke passed ({$passes} assertions).\n";
