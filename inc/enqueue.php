<?php
/**
 * Script and style enqueue + mount container.
 *
 * @package FrontendAgentChat
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the frontend chat script and styles.
 *
 * Fires on wp_enqueue_scripts so the assets load on every frontend page.
 * Bails early if the chat is disabled, the user can't access the agent,
 * or the agent doesn't exist.
 *
 * @return void
 */
function frontend_agent_chat_enqueue() {
	$config = frontend_agent_chat_get_config();

	if ( empty( $config['enabled'] ) || empty( $config['agent_slug'] ) ) {
		return;
	}

	$agent = frontend_agent_chat_resolve_agent( $config['agent_slug'] );
	if ( ! $agent ) {
		return;
	}

	if ( ! frontend_agent_chat_user_can_see( $agent ) ) {
		return;
	}

	$build_dir = FRONTEND_AGENT_CHAT_PLUGIN_DIR . 'build/';
	$build_url = FRONTEND_AGENT_CHAT_PLUGIN_URL . 'build/';
	$asset_php = $build_dir . 'index.asset.php';

	if ( ! file_exists( $asset_php ) ) {
		return;
	}

	$asset = require $asset_php;

	wp_enqueue_script(
		'frontend-agent-chat',
		$build_url . 'index.js',
		$asset['dependencies'] ?? array(),
		$asset['version'] ?? FRONTEND_AGENT_CHAT_VERSION,
		array( 'in_footer' => true )
	);

	if ( file_exists( $build_dir . 'index.css' ) ) {
		wp_enqueue_style(
			'frontend-agent-chat',
			$build_url . 'index.css',
			array(),
			$asset['version'] ?? FRONTEND_AGENT_CHAT_VERSION
		);
	}

	$js_config = array(
		'agentSlug'        => (string) ( $agent['agent_slug'] ?? $config['agent_slug'] ),
		'basePath'         => '/frontend-agent-chat/v1/chat',
		'agentName'        => (string) ( $agent['agent_name'] ?? $agent['label'] ?? $config['agent_slug'] ),
		'agentDescription' => (string) ( $agent['agent_description'] ?? $agent['description'] ?? $config['description'] ),
	);

	if ( ! empty( $config['loading_messages'] ) ) {
		$js_config['loadingMessages'] = $config['loading_messages'];
	}

	wp_localize_script(
		'frontend-agent-chat',
		'frontendAgentChatConfig',
		$js_config
	);
}
add_action( 'wp_enqueue_scripts', 'frontend_agent_chat_enqueue' );

/**
 * Render the chat mount container in wp_footer.
 *
 * Only renders if the script was successfully enqueued.
 *
 * @return void
 */
function frontend_agent_chat_render_container() {
	if ( ! wp_script_is( 'frontend-agent-chat', 'enqueued' ) ) {
		return;
	}

	echo '<div data-frontend-agent-chat></div>';
}
add_action( 'wp_footer', 'frontend_agent_chat_render_container', 50 );

/**
 * Backward-compatible enqueue wrapper.
 *
 * @return void
 */
function data_machine_frontend_chat_enqueue() {
	frontend_agent_chat_enqueue();
}

/**
 * Backward-compatible container wrapper.
 *
 * @return void
 */
function data_machine_frontend_chat_render_container() {
	frontend_agent_chat_render_container();
}
