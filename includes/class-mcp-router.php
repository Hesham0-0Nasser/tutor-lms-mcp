<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the REST endpoint and implements the MCP protocol.
 *
 * Endpoint: POST /wp-json/tutor-mcp/v1/mcp
 *
 * Supports MCP methods:
 *   - initialize
 *   - tools/list
 *   - tools/call
 */
class TLMS_MCP_Router {

    const NAMESPACE = 'tutor-mcp/v1';
    const ROUTE     = '/mcp';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, self::ROUTE, [
            [
                'methods'             => [ 'POST' ],
                'callback'            => [ __CLASS__, 'handle' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => [ 'GET', 'OPTIONS' ],
                'callback'            => [ __CLASS__, 'handle_preflight' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    /**
     * Respond to OPTIONS / GET so Claude.ai's health-check and CORS preflight succeed.
     *
     * OPTIONS → 204 No Content (CORS preflight)
     * GET     → 200 with text/event-stream so Claude.ai accepts the endpoint
     *           as a valid Streamable HTTP MCP server.
     */
    public static function handle_preflight( WP_REST_Request $request ): WP_REST_Response {
        // OPTIONS → CORS preflight (204)
        // GET     → 405 Method Not Allowed signals to Claude.ai that this is a
        //           stateless Streamable HTTP server (POST-only). Per MCP spec
        //           2025-03-26 §6.3.3, a 405 on GET is valid and Claude.ai will
        //           proceed with POST-only communication.
        if ( 'OPTIONS' === $request->get_method() ) {
            $r = new WP_REST_Response( null, 204 );
            $r->header( 'Access-Control-Allow-Origin',  '*' );
            $r->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
            $r->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id' );
            return $r;
        }
        // GET — stateless server does not support SSE streams
        $r = new WP_REST_Response( [ 'error' => 'Use POST for MCP requests.' ], 405 );
        $r->header( 'Access-Control-Allow-Origin',  '*' );
        $r->header( 'Allow', 'POST, OPTIONS' );
        return $r;
    }

    public static function handle( WP_REST_Request $request ): WP_REST_Response {

        // ── Debug logging ─────────────────────────────────────────────────────
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log = [
                'time'    => current_time( 'mysql' ),
                'method'  => $request->get_method(),
                'body'    => $request->get_body(),
                'auth'    => substr( $request->get_header( 'authorization' ) ?? '', 0, 20 ) . '...',
                'session' => $request->get_header( 'mcp-session-id' ) ?? 'none',
            ];
            error_log( '[TutorMCP] ' . wp_json_encode( $log ) );
        }

        // ── Ignore Mcp-Session-Id — this is a stateless server ──────────────
        // Claude.ai sends back whatever session ID we gave it. We never issued
        // one, so if a session ID arrives, we simply accept and ignore it.
        // Do NOT return 404 — that would cause Claude.ai to terminate the session.

        // ── Parse JSON-RPC body ───────────────────────────────────────────────
        // Support both single requests and batched arrays.
        $raw    = $request->get_json_params();
        $body   = is_array( $raw ) && isset( $raw['method'] ) ? $raw : ( $raw ?? [] );
        $method = $body['method'] ?? '';
        $id     = $body['id']     ?? null;
        $params = $body['params'] ?? [];

        // ── Always-allowed methods (no auth needed) ──────────────────────────
        switch ( $method ) {
            case 'notifications/initialized':
            case 'notifications/cancelled':
                // Spec §4: notifications have no id. Server MUST return 202 Accepted
                // with empty body. We bypass WP REST response pipeline entirely to
                // ensure no body is serialized (WP_REST_Response(null) sends "null").
                http_response_code( 202 );
                header( 'Access-Control-Allow-Origin: *' );
                header( 'Content-Length: 0' );
                exit;

            case 'ping':
                return self::json( new stdClass(), $id );

            case 'initialize':
                // initialize is handled specially:
                // • If no/invalid auth → return 401 + WWW-Authenticate so Claude.ai
                //   triggers the OAuth popup. The id is echoed so Claude.ai can match it.
                // • If auth valid     → return the full initialize response.
                $auth_header = $_SERVER['HTTP_AUTHORIZATION']
                    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                    ?? $request->get_header( 'authorization' )
                    ?? '';
                if ( empty( $auth_header ) ) {
                    $r = self::error_response( -32001, 'Authentication required.', $id, 401 );
                    $r->header( 'WWW-Authenticate', TLMS_MCP_OAuth::www_authenticate_header() );
                    return $r;
                }
                // Auth header present — validate it
                $user_init = TLMS_MCP_Auth::authenticate( $request );
                if ( is_wp_error( $user_init ) ) {
                    $http_status = $user_init->get_error_data()['status'] ?? 401;
                    $r = self::error_response( -32001, $user_init->get_error_message(), $id, $http_status );
                    if ( 401 === $http_status ) {
                        $r->header( 'WWW-Authenticate', TLMS_MCP_OAuth::www_authenticate_header() );
                    }
                    return $r;
                }
                // Stateless server — do not issue Mcp-Session-Id.
                // Issuing one requires validating it on every subsequent request.
                // A stateless HTTP MCP server should omit session tracking.
                return self::json( self::result_initialize( $params ), $id );
        }

        // ── All other methods require authentication ───────────────────────────
        $user = TLMS_MCP_Auth::authenticate( $request );
        if ( is_wp_error( $user ) ) {
            $http_status = $user->get_error_data()['status'] ?? 401;
            $r = self::error_response( -32001, $user->get_error_message(), $id, $http_status );
            if ( 401 === $http_status ) {
                $r->header( 'WWW-Authenticate', TLMS_MCP_OAuth::www_authenticate_header() );
            }
            return $r;
        }

        // ── Authenticated dispatch ────────────────────────────────────────────
        switch ( $method ) {

            case 'tools/list':
                return self::json( [ 'tools' => TLMS_MCP_Tools::list_tools(), 'nextCursor' => null ], $id );

            case 'tools/call':
                // Ensure authenticated user is active for Tutor LMS permission checks
                if ( isset( $user ) && $user instanceof WP_User ) {
                    wp_set_current_user( $user->ID );
                }
                $tool_name = $params['name']      ?? '';
                $arguments = $params['arguments'] ?? [];
                try {
                    $result = TLMS_MCP_Tools::call_tool( $tool_name, $arguments );
                    return self::json( [
                        'content' => [ [ 'type' => 'text', 'text' => $result ] ],
                        'isError' => false,
                    ], $id );
                } catch ( Exception $e ) {
                    return self::json( [
                        'content' => [ [ 'type' => 'text', 'text' => 'Error: ' . $e->getMessage() ] ],
                        'isError' => true,
                    ], $id );
                }

            default:
                return self::error_response( -32601, "Method not found: {$method}", $id );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function result_initialize( array $params ): array {
        $client_version = $params['protocolVersion'] ?? '2025-03-26';
        $known          = [ '2024-11-05', '2025-03-26', '2025-11-25' ];
        $version        = in_array( $client_version, $known, true ) ? $client_version : '2025-03-26';

        // If the client declared extensions, acknowledge the field (empty object).
        // ClaudeAI sends extensions: {"io.modelcontextprotocol/ui": {...}} and
        // terminates the session if the server omits the field entirely.
        $client_caps = $params['capabilities'] ?? [];
        $capabilities = [ 'tools' => [ 'listChanged' => false ] ];
        if ( isset( $client_caps['extensions'] ) ) {
            $capabilities['extensions'] = new stdClass();
        }

        return [
            'protocolVersion' => $version,
            'serverInfo'      => [
                'name'    => 'tutor-lms-mcp',
                'version' => TLMS_MCP_VERSION,
            ],
            'capabilities'    => $capabilities,
        ];
    }

    private static function json( array $result, $id ): WP_REST_Response {
        $r = new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ], 200 );
        $r->header( 'Content-Type', 'application/json; charset=utf-8' );
        $r->header( 'Access-Control-Allow-Origin', '*' );
        $r->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id' );
        return $r;
    }

    private static function error_response( int $code, string $message, $id, int $http_status = 400 ): WP_REST_Response {
        $r = new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [ 'code' => $code, 'message' => $message ],
        ], $http_status );
        $r->header( 'Content-Type', 'application/json; charset=utf-8' );
        $r->header( 'Access-Control-Allow-Origin', '*' );
        $r->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id' );
        return $r;
    }
}
