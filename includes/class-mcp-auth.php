<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles MCP request authentication.
 *
 * Supports two methods:
 *   1. Bearer token  — issued via the OAuth 2.0 flow (Claude.ai connector)
 *   2. Basic auth    — WordPress Application Password (manual / Claude Desktop)
 */
class TLMS_MCP_Auth {

    /**
     * Validate the incoming request.
     * Returns WP_User on success, WP_Error on failure.
     *
     * @param WP_REST_Request $request
     * @return WP_User|WP_Error
     */
    public static function authenticate( WP_REST_Request $request ) {

        if ( ! get_option( 'tlms_mcp_enabled', true ) ) {
            return new WP_Error( 'mcp_disabled', 'MCP Server is disabled.', [ 'status' => 503 ] );
        }

        // ── 1. Bearer token (OAuth flow from Claude.ai) ───────────────────────
        // On LiteSpeed/Hostinger, WordPress's WP_REST_Request::get_header() does
        // not always see Authorization even when $_SERVER['HTTP_AUTHORIZATION'] is
        // set. Read it directly from all possible sources, preferring $_SERVER.
        $auth_header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']
            ?? $request->get_header( 'authorization' )
            ?? '';

        // getallheaders() fallback (cgi/fastcgi environments)
        if ( empty( $auth_header ) && function_exists( 'getallheaders' ) ) {
            $all_headers = getallheaders();
            $auth_header = $all_headers['Authorization']
                ?? $all_headers['authorization']
                ?? '';
        }
        if ( $auth_header && str_starts_with( strtolower( $auth_header ), 'bearer ' ) ) {
            $token   = trim( substr( $auth_header, 7 ) );
            $user_id = TLMS_MCP_OAuth::validate_bearer_token( $token );

            if ( ! $user_id ) {
                return new WP_Error(
                    'mcp_unauthorized',
                    'Invalid or expired Bearer token.',
                    [ 'status' => 401 ]
                );
            }

            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                return new WP_Error(
                    'mcp_unauthorized',
                    'Token references a deleted user.',
                    [ 'status' => 401 ]
                );
            }

            if ( ! TLMS_MCP_OAuth::user_has_access( $user ) ) {
                return new WP_Error(
                    'mcp_forbidden',
                    'Your account does not have permission to use the MCP server.',
                    [ 'status' => 403 ]
                );
            }

            return $user;
        }

        // ── 2. Basic auth (WordPress Application Password) ────────────────────
        // WordPress core already processes Authorization: Basic and sets the
        // current user via wp_authenticate_application_password().
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return new WP_Error(
                'mcp_unauthorized',
                'Authentication required. Use a Bearer token (OAuth) or a WordPress Application Password.',
                [ 'status' => 401 ]
            );
        }

        // Use the same access check as the OAuth class
        if ( TLMS_MCP_OAuth::user_has_access( $user ) ) {
            return $user;
        }

        return new WP_Error(
            'mcp_forbidden',
            'Your account does not have permission to use the MCP server.',
            [ 'status' => 403 ]
        );
    }
}
