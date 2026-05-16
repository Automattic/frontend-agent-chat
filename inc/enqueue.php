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

	if ( empty( $config['enabled'] ) ) {
		return;
	}

	$agents = frontend_agent_chat_list_accessible_agents();
	if ( empty( $agents ) ) {
		return;
	}

	$agent = null;
	if ( ! empty( $config['agent_slug'] ) ) {
		$agent = frontend_agent_chat_resolve_agent( (string) $config['agent_slug'] );
	}
	$agent = $agent ?: $agents[0];

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
		'bootstrapPath'    => '/frontend-agent-chat/v1/bootstrap',
		'agentsPath'       => '/frontend-agent-chat/v1/agents',
		'agentName'        => (string) ( $agent['agent_name'] ?? $agent['label'] ?? $config['agent_slug'] ),
		'agentDescription' => (string) ( $agent['agent_description'] ?? $agent['description'] ?? $config['description'] ),
		'isLoggedIn'       => is_user_logged_in(),
	);

	/**
	 * Filter anonymous chat persistence CTA data.
	 *
	 * Domain plugins can provide a product-specific sign-in or account-linking
	 * action without coupling this generic widget to a concrete identity provider.
	 * Return an array with `message`, `action_label`, and `action_url`, or an empty
	 * array to show the generic browser-cookie persistence message.
	 *
	 * @param array $cta    CTA data.
	 * @param array $config Frontend chat configuration.
	 * @param array $agent  Selected agent descriptor.
	 */
	$persistence_cta = apply_filters( 'frontend_agent_chat_persistence_cta', array(), $config, $agent );
	if ( is_array( $persistence_cta ) && ! empty( $persistence_cta['action_url'] ) ) {
		$js_config['persistenceCta'] = array(
			'message'     => sanitize_text_field( (string) ( $persistence_cta['message'] ?? '' ) ),
			'actionLabel' => sanitize_text_field( (string) ( $persistence_cta['action_label'] ?? '' ) ),
			'actionUrl'   => esc_url_raw( (string) $persistence_cta['action_url'] ),
		);
	}

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
