<?php
/**
 * Smoke tests for ability errors crossing the FAC REST boundary.
 *
 * @package FrontendAgentChat
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private mixed $data;

	public function __construct( private string $code = '', private string $message = '', $data = null ) {
		$this->data = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}

	public function add_data( $data ): void {
		$this->data = $data;
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function add_filter(): void {}

function wp_get_ability(): object {
	return new class() {
		public function execute( array $input ) {
			unset( $input );
			return $GLOBALS['frontend_agent_chat_test_ability_result'];
		}
	};
}

require_once dirname( __DIR__ ) . '/inc/config.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$GLOBALS['frontend_agent_chat_test_ability_result'] = new WP_Error( 'ability_invalid_permissions', 'Denied.' );
$permission_error = frontend_agent_chat_execute_ability( 'agents/queue-chat-message', array() );
$assert( 403 === ( $permission_error->get_error_data()['status'] ?? null ), 'Permission denial did not receive HTTP 403.' );

$GLOBALS['frontend_agent_chat_test_ability_result'] = new WP_Error( 'ability_invalid_permissions', 'Denied.', array( 'status' => 404 ) );
$explicit_status = frontend_agent_chat_execute_ability( 'agents/get-conversation-session', array() );
$assert( 404 === ( $explicit_status->get_error_data()['status'] ?? null ), 'Explicit ability status was overwritten.' );

$GLOBALS['frontend_agent_chat_test_ability_result'] = new WP_Error( 'provider_failure', 'Provider failed.' );
$other_error = frontend_agent_chat_execute_ability( 'agents/chat', array() );
$assert( null === $other_error->get_error_data(), 'Non-permission ability error was modified.' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Ability REST error status smoke tests passed.\n";
