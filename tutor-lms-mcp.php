<?php
/**
 * Plugin Name: Tutor LMS MCP Server
 * Plugin URI:  https://github.com/Hesham0-0Nasser/tutor-lms-mcp
 * Description: Exposes a Model Context Protocol (MCP) endpoint so Claude AI can manage your Tutor LMS courses, lessons, quizzes, enrollments, and more.
 * Version:     3.7.0
 * Author:      Hesham Nasser
 * License:     GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TLMS_MCP_VERSION',    '3.7.0' );
define( 'TLMS_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TLMS_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── Autoload includes ──────────────────────────────────────────────────────────
require_once TLMS_MCP_PLUGIN_DIR . 'includes/class-mcp-oauth.php';
require_once TLMS_MCP_PLUGIN_DIR . 'includes/class-mcp-auth.php';
require_once TLMS_MCP_PLUGIN_DIR . 'includes/class-mcp-router.php';
require_once TLMS_MCP_PLUGIN_DIR . 'includes/class-mcp-tools.php';
require_once TLMS_MCP_PLUGIN_DIR . 'includes/class-mcp-admin.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
TLMS_MCP_OAuth::init();

add_action( 'rest_api_init', [ 'TLMS_MCP_Router', 'register_routes' ] );

// ── Set WP current user from Bearer token as early as possible ───────────────
// This fires on 'init' (priority 1) — before REST dispatch, before Tutor's
// permission callbacks, before everything. Sets the global WP user so that
// current_user_can() works correctly throughout the entire request.
add_action( 'init', function() {
    // Only act on REST API requests to our namespace
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_our_request = strpos( $uri, 'tutor-mcp' ) !== false
        || strpos( $uri, 'rest_route=/tutor-mcp' ) !== false;

    if ( ! $is_our_request ) {
        return;
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ( empty( $auth ) && function_exists( 'getallheaders' ) ) {
        $hdrs = getallheaders();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }

    if ( empty( $auth ) || stripos( $auth, 'bearer ' ) !== 0 ) {
        return;
    }

    $token   = trim( substr( $auth, 7 ) );
    $user_id = TLMS_MCP_OAuth::validate_bearer_token( $token );

    if ( $user_id && ! is_user_logged_in() ) {
        wp_set_current_user( $user_id );
    }
}, 1 );

// ── Prevent WordPress core from rejecting our Bearer tokens ───────────────────
// WordPress sees Authorization: Bearer <token> and tries to validate it as an
// Application Password. It fails and returns a bare 401 before our callback runs.
// This filter fires first — if the request is to our namespace and carries a
// Bearer token, we authenticate the user ourselves and tell WP core to back off.
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! empty( $result ) ) {
        return $result; // Another plugin already handled auth
    }

    $route = $GLOBALS['wp']->query_vars['rest_route'] ?? $_SERVER['PATH_INFO'] ?? '';
    $is_our_route = strpos( $route, '/tutor-mcp/' ) !== false
        || strpos( $_SERVER['REQUEST_URI'] ?? '', 'rest_route=/tutor-mcp/' ) !== false
        || strpos( $_SERVER['REQUEST_URI'] ?? '', '/wp-json/tutor-mcp/' ) !== false;

    if ( ! $is_our_route ) {
        return $result;
    }

    // Read Bearer token from every possible location
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ( empty( $auth ) && function_exists( 'getallheaders' ) ) {
        $hdrs = getallheaders();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }

    if ( empty( $auth ) || stripos( $auth, 'bearer ' ) !== 0 ) {
        return $result; // No Bearer token — let WP handle (will get 401 from our callback)
    }

    $token   = trim( substr( $auth, 7 ) );
    $user_id = TLMS_MCP_OAuth::validate_bearer_token( $token );

    if ( ! $user_id ) {
        // Token present but invalid — return null so our callback returns proper error
        return null;
    }

    $user = get_user_by( 'id', $user_id );
    if ( $user ) {
        wp_set_current_user( $user_id );
    }

    // Return null = "no error, proceed" — our callback will do the full auth check
    return null;
}, 10 );
add_action( 'admin_menu',    [ 'TLMS_MCP_Admin',  'add_menu' ] );
add_action( 'admin_init',    [ 'TLMS_MCP_Admin',  'register_settings' ] );
add_action( 'wp_ajax_tlms_mcp_test_api', [ 'TLMS_MCP_Admin', 'handle_test_ajax' ] );

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    if ( ! function_exists( 'tutor' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Tutor LMS MCP Server requires Tutor LMS (free or Pro) to be installed and active.' );
    }

    // Register rewrite rules then flush so /tutor-mcp/oauth/authorize works immediately.
    TLMS_MCP_OAuth::register_rewrite();
    flush_rewrite_rules();
} );

// ── Deactivation ──────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
