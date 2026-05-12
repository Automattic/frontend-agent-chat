<?php
/**
 * Plugin Name: Frontend Agent Chat
 * Plugin URI: https://github.com/Extra-Chill/frontend-agent-chat
 * Description: Floating agent chat widget for WordPress agents. Uses the canonical Agents API chat ability with a compatibility adapter for existing Data Machine installs.
 * Version: 0.7.3
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: frontend-agent-chat
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 *
 * @package FrontendAgentChat
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRONTEND_AGENT_CHAT_VERSION', '0.7.3' );
define( 'FRONTEND_AGENT_CHAT_PLUGIN_FILE', __FILE__ );
define( 'FRONTEND_AGENT_CHAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRONTEND_AGENT_CHAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Backward-compatible constant aliases for existing integrations.
define( 'DATA_MACHINE_FRONTEND_CHAT_VERSION', FRONTEND_AGENT_CHAT_VERSION );
define( 'DATA_MACHINE_FRONTEND_CHAT_PLUGIN_FILE', FRONTEND_AGENT_CHAT_PLUGIN_FILE );
define( 'DATA_MACHINE_FRONTEND_CHAT_PLUGIN_DIR', FRONTEND_AGENT_CHAT_PLUGIN_DIR );
define( 'DATA_MACHINE_FRONTEND_CHAT_PLUGIN_URL', FRONTEND_AGENT_CHAT_PLUGIN_URL );

require_once FRONTEND_AGENT_CHAT_PLUGIN_DIR . 'inc/config.php';
require_once FRONTEND_AGENT_CHAT_PLUGIN_DIR . 'inc/rest.php';
require_once FRONTEND_AGENT_CHAT_PLUGIN_DIR . 'inc/enqueue.php';
