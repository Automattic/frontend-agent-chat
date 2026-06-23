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
 * Sanitize SVG path data for icon configuration.
 *
 * Consumers may override icon shapes through frontend_agent_chat_config, but
 * the widget still owns the SVG element. This keeps the extension point narrow
 * and avoids passing raw markup to the browser.
 *
 * @param mixed $path SVG path data.
 * @return string Sanitized path data, or empty string when invalid.
 */
function frontend_agent_chat_sanitize_svg_path( $path ): string {
	$path = trim( (string) $path );
	if ( '' === $path || ! preg_match( '/^[MmZzLlHhVvCcSsQqTtAa0-9.,\-\s]+$/', $path ) ) {
		return '';
	}

	return $path;
}

/**
 * Sanitize SVG viewBox data for icon configuration.
 *
 * @param mixed $view_box SVG viewBox data.
 * @return string Sanitized viewBox data.
 */
function frontend_agent_chat_sanitize_svg_view_box( $view_box ): string {
	$view_box = trim( (string) $view_box );
	if ( ! preg_match( '/^-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?$/', $view_box ) ) {
		return '0 0 24 24';
	}

	return $view_box;
}

/**
 * Sanitize message suggestions for the frontend chat UI.
 *
 * @param mixed $suggestions Raw suggestion config.
 * @return array<int,array{id:string,label:string,prompt?:string,autoSubmit?:bool}> Sanitized suggestions.
 */
function frontend_agent_chat_sanitize_message_suggestions( $suggestions ): array {
	if ( ! is_array( $suggestions ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $suggestions as $suggestion ) {
		if ( ! is_array( $suggestion ) ) {
			continue;
		}

		$label = sanitize_text_field( (string) ( $suggestion['label'] ?? '' ) );
		if ( '' === $label ) {
			continue;
		}

		$item = array(
			'id'    => sanitize_title( $label ),
			'label' => $label,
		);

		$message = sanitize_textarea_field( (string) ( $suggestion['message'] ?? '' ) );
		if ( '' !== $message ) {
			$item['prompt'] = $message;
		}

		if ( isset( $suggestion['auto_submit'] ) || isset( $suggestion['autoSubmit'] ) ) {
			$item['autoSubmit'] = (bool) ( $suggestion['auto_submit'] ?? $suggestion['autoSubmit'] );
		}

		$sanitized[] = $item;
	}

	return $sanitized;
}

/**
 * Sanitize generic chat context forwarded with widget messages.
 *
 * This is intentionally an opaque key/value payload. Integrations may use it
 * to provide a selected brain, corpus, or source-scope hint while Agents API
 * and domain plugins own the concrete semantics and authorization checks.
 *
 * @param mixed $context Raw context configuration.
 * @return array<string,mixed> Sanitized context.
 */
function frontend_agent_chat_sanitize_chat_context( $context ): array {
	if ( ! is_array( $context ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $context as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			$sanitized[ $key ] = $value;
			continue;
		}

		if ( is_string( $value ) ) {
			$sanitized[ $key ] = sanitize_text_field( $value );
			continue;
		}

		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$items[] = sanitize_text_field( (string) $item );
				}
			}
			if ( ! empty( $items ) ) {
				$sanitized[ $key ] = $items;
			}
		}
	}

	return $sanitized;
}

/**
 * Sanitize header control visibility configuration.
 *
 * @param mixed $controls Raw header controls configuration.
 * @return array{agentSelector:bool,sessionControls:bool,expandButton:bool,closeButton:bool} Sanitized controls.
 */
function frontend_agent_chat_sanitize_header_controls( $controls ): array {
	$defaults = array(
		'agent_selector'    => true,
		'session_controls' => true,
		'expand_button'    => true,
		'close_button'     => true,
	);

	if ( ! is_array( $controls ) ) {
		$controls = array();
	}

	$controls = wp_parse_args( $controls, $defaults );

	return array(
		'agentSelector'    => (bool) $controls['agent_selector'],
		'sessionControls' => (bool) $controls['session_controls'],
		'expandButton'    => (bool) $controls['expand_button'],
		'closeButton'     => (bool) $controls['close_button'],
	);
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

	$default_agent_slug = frontend_agent_chat_get_default_agent_slug( $config );
	$agent              = null;
	if ( '' !== $default_agent_slug ) {
		$agent = frontend_agent_chat_resolve_agent( $default_agent_slug );
	}
	$agent = $agent ? $agent : $agents[0];

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

	$capabilities = frontend_agent_chat_get_run_control_capabilities( (string) ( $agent['agent_slug'] ?? $default_agent_slug ) );

	$js_config = array(
		'agentSlug'                  => (string) ( $agent['agent_slug'] ?? $default_agent_slug ),
		'basePath'                   => '/frontend-agent-chat/v1/chat',
		'bootstrapPath'              => '/frontend-agent-chat/v1/bootstrap',
		'agentsPath'                 => '/frontend-agent-chat/v1/agents',
		'agentName'                  => (string) ( $agent['agent_name'] ?? $agent['label'] ?? $default_agent_slug ),
		'agentDescription'           => (string) ( $agent['agent_description'] ?? $agent['description'] ?? $config['description'] ),
		'fabLabel'                   => sanitize_text_field( (string) ( $config['fab_label'] ?? __( 'Agent Chat', 'frontend-agent-chat' ) ) ),
		'fabIcon'                    => sanitize_text_field( (string) ( $config['fab_icon'] ?? 'AI' ) ),
		'fabIconPath'                => frontend_agent_chat_sanitize_svg_path( $config['fab_icon_path'] ?? '' ),
		'fabIconViewBox'             => frontend_agent_chat_sanitize_svg_view_box( $config['fab_icon_view_box'] ?? '0 0 24 24' ),
		'expandIconPath'             => frontend_agent_chat_sanitize_svg_path( $config['expand_icon_path'] ?? '' ),
		'collapseIconPath'           => frontend_agent_chat_sanitize_svg_path( $config['collapse_icon_path'] ?? '' ),
		'expandIconViewBox'          => frontend_agent_chat_sanitize_svg_view_box( $config['expand_icon_view_box'] ?? '0 0 24 24' ),
		'layout'                     => 'inline' === ( $config['layout'] ?? '' ) ? 'inline' : 'floating',
		'headerControls'             => frontend_agent_chat_sanitize_header_controls( $config['header_controls'] ?? array() ),
		'isLoggedIn'                 => is_user_logged_in(),
		'canUploadFiles'             => is_user_logged_in() && current_user_can( 'upload_files' ),
		'capabilities'               => $capabilities,
		'operatorDiagnosticsEnabled' => ! empty( $config['operator_diagnostics'] ) || ! empty( $capabilities['operator_diagnostics'] ),
	);

	/**
	 * Filter anonymous chat persistence CTA data.
	 *
	 * Domain plugins can provide a product-specific sign-in or account-linking
	 * action without coupling this generic widget to a concrete identity provider.
	 * Return an array with `message`, `action_label`, and `action_url`, or an empty
	 * array to hide this optional CTA slot.
	 *
	 * @param array $cta    CTA data.
	 * @param array $config Frontend chat configuration.
	 * @param array $agent  Selected agent descriptor.
	 */
	/** @var mixed $persistence_cta */
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

	$message_suggestions = frontend_agent_chat_sanitize_message_suggestions( $config['message_suggestions'] ?? array() );
	if ( ! empty( $message_suggestions ) ) {
		$js_config['messageSuggestions'] = $message_suggestions;
	}

	$chat_context = frontend_agent_chat_sanitize_chat_context( $config['chat_context'] ?? $config['context'] ?? array() );
	if ( ! empty( $chat_context ) ) {
		$js_config['chatContext'] = $chat_context;
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
