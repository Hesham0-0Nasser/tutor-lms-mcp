<?php
defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.0 Authorization Server for the Tutor LMS MCP plugin.
 *
 * Implements the MCP Authorization spec so Claude.ai can connect via
 * the "Add connector" flow without needing a manually generated API key.
 *
 * Endpoints:
 *   GET  /.well-known/oauth-authorization-server  — discovery metadata
 *   GET  /tutor-mcp/oauth/authorize               — approval page (browser)
 *   POST /wp-json/tutor-mcp/v1/oauth/token        — token exchange (JSON)
 */
class TLMS_MCP_OAuth {

    const NS = 'tutor-mcp/v1';

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'maybe_handle_well_known' ], 1 );
        // Intercept /tutor-mcp-authorize at priority 1 — before WordPress tries to route
        // the URL — so the metadata can advertise a clean path with no pre-existing query string.
        add_action( 'init', [ __CLASS__, 'maybe_handle_authorize_page' ], 1 );
        add_action( 'init', [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_authorize' ], 1 );
        add_action( 'rest_api_init', [ __CLASS__, 'register_token_route' ] );
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'add_cors_headers' ], 10, 3 );
    }

    // ── .well-known discovery ─────────────────────────────────────────────────

    public static function maybe_handle_well_known(): void {
        $path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );

        if ( $path === '/.well-known/oauth-authorization-server' ) {
            self::send_json( self::metadata() );
        }

        if ( $path === '/.well-known/oauth-protected-resource' ) {
            self::send_json( self::protected_resource_metadata() );
        }
    }

    private static function send_json( array $data ): void {
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Cache-Control: no-store' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        exit;
    }

    public static function metadata(): array {
        return [
            'issuer'                                => home_url(),
            // Clean path — no pre-existing query string so Claude.ai appends ? correctly.
            // Intercepted in maybe_handle_authorize_page() at init priority 1.
            'authorization_endpoint'                => home_url( '/tutor-mcp-authorize' ),
            'token_endpoint'                        => add_query_arg( 'rest_route', '/' . self::NS . '/oauth/token', home_url( '/' ) ),
            'response_types_supported'              => [ 'code' ],
            'grant_types_supported'                 => [ 'authorization_code' ],
            'code_challenge_methods_supported'      => [ 'S256' ],
            'token_endpoint_auth_methods_supported' => [ 'none' ],
            'scopes_supported'                      => [ 'mcp' ],
            'registration_endpoint'                 => add_query_arg( 'rest_route', '/' . self::NS . '/oauth/register', home_url( '/' ) ),
        ];
    }

    public static function protected_resource_metadata(): array {
        $endpoint = add_query_arg( 'rest_route', '/' . self::NS . '/mcp', home_url( '/' ) );
        return [
            'resource'                => $endpoint,
            'authorization_servers'   => [ home_url() ],
            'bearer_methods_supported'=> [ 'header' ],
            'scopes_supported'        => [ 'mcp' ],
        ];
    }

    public static function www_authenticate_header(): string {
        $as_meta = home_url( '/.well-known/oauth-authorization-server' );
        return sprintf( 'Bearer realm="Tutor LMS MCP", authorization_server="%s"', $as_meta );
    }

    // ── Clean-path authorize interceptor ─────────────────────────────────────

    public static function maybe_handle_authorize_page(): void {
        $authorize_path = rtrim( parse_url( home_url( '/tutor-mcp-authorize' ), PHP_URL_PATH ), '/' );
        $req_path       = rtrim( strtok( $_SERVER['REQUEST_URI'] ?? '', '?' ), '/' );

        if ( $req_path !== $authorize_path ) {
            return;
        }

        self::do_authorize_flow();
    }

    // ── Rewrite rule for /tutor-mcp/oauth/authorize ───────────────────────────

    public static function register_rewrite(): void {
        // Match /tutor-mcp-authorize (the URL advertised in OAuth metadata)
        add_rewrite_rule(
            '^tutor-mcp-authorize/?$',
            'index.php?tlms_mcp_action=oauth_authorize',
            'top'
        );
        // Also keep legacy path for backwards compat
        add_rewrite_rule(
            '^tutor-mcp/oauth/authorize/?$',
            'index.php?tlms_mcp_action=oauth_authorize',
            'top'
        );
    }

    public static function register_query_var( array $vars ): array {
        $vars[] = 'tlms_mcp_action';
        return $vars;
    }

    // ── Authorization endpoint (browser flow) ─────────────────────────────────

    public static function handle_authorize(): void {
        if ( get_query_var( 'tlms_mcp_action' ) !== 'oauth_authorize' ) {
            return;
        }
        self::do_authorize_flow();
    }

    private static function do_authorize_flow(): void {
        status_header( 200 );
        nocache_headers();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $client_id     = sanitize_text_field( $_GET['client_id'] ?? '' );
        $redirect_uri  = esc_url_raw( $_GET['redirect_uri'] ?? '' );
        $state         = sanitize_text_field( $_GET['state'] ?? '' );
        $challenge     = sanitize_text_field( $_GET['code_challenge'] ?? '' );
        $ch_method     = sanitize_text_field( $_GET['code_challenge_method'] ?? 'S256' );
        $response_type = sanitize_text_field( $_GET['response_type'] ?? '' );
        // phpcs:enable

        if ( 'code' !== $response_type || empty( $redirect_uri ) ) {
            wp_die( 'Invalid OAuth request: missing required parameters.', 'OAuth Error', [ 'response' => 400 ] );
        }

        if ( ! is_user_logged_in() ) {
            // add_query_arg encodes values — do NOT rawurlencode() here
            $back = add_query_arg( [
                'client_id'             => $client_id,
                'redirect_uri'          => $redirect_uri,
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => $ch_method,
                'response_type'         => 'code',
            ], home_url( '/tutor-mcp-authorize' ) );
            wp_redirect( wp_login_url( $back ) );
            exit;
        }

        $user = wp_get_current_user();

        if ( ! self::user_has_access( $user ) ) {
            wp_die( 'Your account does not have permission to use the MCP server.', 'Access Denied', [ 'response' => 403 ] );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            check_admin_referer( 'tlms_mcp_oauth_approve' );

            if ( isset( $_POST['tlms_mcp_deny'] ) ) {
                wp_redirect( add_query_arg( [ 'error' => 'access_denied', 'state' => $state ], $redirect_uri ) );
                exit;
            }

            if ( isset( $_POST['tlms_mcp_approve'] ) ) {
                $code = bin2hex( random_bytes( 32 ) );
                set_transient( 'tlms_mcp_code_' . $code, [
                    'user_id'      => $user->ID,
                    'redirect_uri' => $redirect_uri,
                    'challenge'    => $challenge,
                    'ch_method'    => $ch_method,
                    'client_id'    => $client_id,
                ], 300 );

                wp_redirect( add_query_arg( [ 'code' => $code, 'state' => $state ], $redirect_uri ) );
                exit;
            }
        }

        self::render_approve_page( $user, $client_id, $redirect_uri, $state, $challenge, $ch_method );
        exit;
    }

    private static function render_approve_page(
        WP_User $user,
        string $client_id,
        string $redirect_uri,
        string $state,
        string $challenge,
        string $ch_method
    ): void {
        // add_query_arg encodes values automatically — do NOT rawurlencode() here
        $action = add_query_arg( [
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => $ch_method,
            'response_type'         => 'code',
        ], home_url( '/tutor-mcp-authorize' ) );

        $site_name  = get_bloginfo( 'name' );
        $user_name  = $user->display_name;
        $user_email = $user->user_email;
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Authorize Claude &mdash; <?php echo esc_html( $site_name ); ?></title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f0f2f5;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 24px;
                }
                .card {
                    background: #fff;
                    border-radius: 14px;
                    box-shadow: 0 4px 28px rgba(0,0,0,.11);
                    max-width: 440px;
                    width: 100%;
                    padding: 40px 36px;
                }
                .logo-row {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 16px;
                    margin-bottom: 32px;
                }
                .icon {
                    width: 52px;
                    height: 52px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 26px;
                    flex-shrink: 0;
                }
                .icon-claude { background: #d97706; }
                .icon-site   { background: #4f46e5; }
                .arrow { font-size: 22px; color: #9ca3af; }
                h1 {
                    font-size: 21px;
                    font-weight: 700;
                    color: #111827;
                    margin-bottom: 8px;
                    text-align: center;
                }
                .subtitle {
                    font-size: 14px;
                    color: #6b7280;
                    margin-bottom: 24px;
                    line-height: 1.6;
                    text-align: center;
                }
                .user-badge {
                    background: #f9fafb;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 12px 16px;
                    margin-bottom: 24px;
                    font-size: 14px;
                    color: #374151;
                }
                .user-badge strong { color: #111827; }
                .permissions { margin-bottom: 28px; }
                .permissions h3 {
                    font-size: 12px;
                    font-weight: 600;
                    color: #6b7280;
                    text-transform: uppercase;
                    letter-spacing: .07em;
                    margin-bottom: 10px;
                }
                .perm-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 8px;
                    font-size: 14px;
                    color: #374151;
                    padding: 5px 0;
                    line-height: 1.4;
                }
                .check { color: #059669; font-weight: 700; flex-shrink: 0; }
                .btn-row { display: flex; gap: 10px; }
                .btn {
                    flex: 1;
                    padding: 11px 18px;
                    border-radius: 8px;
                    font-size: 15px;
                    font-weight: 600;
                    cursor: pointer;
                    border: none;
                    transition: opacity .15s;
                    line-height: 1;
                }
                .btn:hover { opacity: .85; }
                .btn-approve { background: #4f46e5; color: #fff; }
                .btn-deny    { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
                .footer {
                    margin-top: 22px;
                    font-size: 12px;
                    color: #9ca3af;
                    text-align: center;
                    line-height: 1.5;
                }
            </style>
        </head>
        <body>
        <div class="card">
            <div class="logo-row">
                <div class="icon icon-claude">🤖</div>
                <div class="arrow">&#8594;</div>
                <div class="icon icon-site">🎓</div>
            </div>

            <h1>Authorize Claude AI</h1>
            <p class="subtitle">
                Claude is requesting access to your<br>
                <strong><?php echo esc_html( $site_name ); ?></strong> Tutor LMS data.
            </p>

            <div class="user-badge">
                Authorizing as <strong><?php echo esc_html( $user_name ); ?></strong>
                (<?php echo esc_html( $user_email ); ?>)
            </div>

            <div class="permissions">
                <h3>Claude will be able to</h3>
                <div class="perm-item"><span class="check">✓</span> Read and manage your courses &amp; lessons</div>
                <div class="perm-item"><span class="check">✓</span> Access quizzes and topics</div>
                <div class="perm-item"><span class="check">✓</span> View and manage student enrollments</div>
                <div class="perm-item"><span class="check">✓</span> Read student progress and quiz results</div>
            </div>

            <form method="post" action="<?php echo esc_url( $action ); ?>">
                <?php wp_nonce_field( 'tlms_mcp_oauth_approve' ); ?>
                <div class="btn-row">
                    <button type="submit" name="tlms_mcp_deny"    value="1" class="btn btn-deny">Deny</button>
                    <button type="submit" name="tlms_mcp_approve" value="1" class="btn btn-approve">Authorize</button>
                </div>
            </form>

            <p class="footer">
                You can revoke access at any time from<br>
                <strong>Tutor MCP &rarr; Settings &rarr; Active Tokens</strong> in your WP admin.
            </p>
        </div>
        </body>
        </html>
        <?php
    }

    // ── Token endpoint (JSON, called by Claude) ───────────────────────────────

    public static function register_token_route(): void {
        // Dynamic Client Registration (RFC7591) — Claude.ai MUST be able to register
        // itself before starting OAuth. Without this, Claude.ai gets 404 and aborts.
        register_rest_route( self::NS, '/oauth/register', [
            [
                'methods'             => [ 'POST', 'OPTIONS' ],
                'callback'            => [ __CLASS__, 'handle_register' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( self::NS, '/oauth/token', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_token' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'OPTIONS',
                'callback'            => [ __CLASS__, 'handle_token_options' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    public static function handle_register( WP_REST_Request $request ): WP_REST_Response {
        // RFC7591 Dynamic Client Registration.
        // Claude.ai sends this before starting OAuth. We accept any client and echo
        // back a client_id. No secret needed (public client with PKCE).
        if ( 'OPTIONS' === $request->get_method() ) {
            $r = new WP_REST_Response( null, 204 );
            $r->header( 'Access-Control-Allow-Origin', '*' );
            $r->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
            $r->header( 'Access-Control-Allow-Headers', 'Content-Type' );
            return $r;
        }

        $body        = $request->get_json_params() ?? [];
        $client_name = sanitize_text_field( $body['client_name'] ?? 'mcp-client' );
        $client_id   = 'tlms_' . bin2hex( random_bytes( 8 ) );

        $r = new WP_REST_Response( [
            'client_id'              => $client_id,
            'client_name'            => $client_name,
            'redirect_uris'          => $body['redirect_uris'] ?? [],
            'grant_types'            => [ 'authorization_code' ],
            'response_types'         => [ 'code' ],
            'token_endpoint_auth_method' => 'none',
        ], 201 );
        $r->header( 'Access-Control-Allow-Origin', '*' );
        return $r;
    }

    public static function handle_token_options(): WP_REST_Response {
        $r = new WP_REST_Response( null, 204 );
        $r->header( 'Access-Control-Allow-Origin', '*' );
        $r->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
        $r->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization' );
        return $r;
    }

    public static function handle_token( WP_REST_Request $request ): WP_REST_Response {
        $grant_type   = $request->get_param( 'grant_type' );
        $code         = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
        $redirect_uri = esc_url_raw( $request->get_param( 'redirect_uri' ) ?? '' );
        $verifier     = sanitize_text_field( $request->get_param( 'code_verifier' ) ?? '' );

        if ( 'authorization_code' !== $grant_type ) {
            return self::oauth_error( 'unsupported_grant_type', 'Only authorization_code is supported.', 400 );
        }

        if ( empty( $code ) ) {
            return self::oauth_error( 'invalid_request', 'code is required.', 400 );
        }

        $data = get_transient( 'tlms_mcp_code_' . $code );
        if ( ! $data ) {
            return self::oauth_error( 'invalid_grant', 'Code is expired or invalid.', 400 );
        }
        delete_transient( 'tlms_mcp_code_' . $code );

        if ( $data['redirect_uri'] !== $redirect_uri ) {
            return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.', 400 );
        }

        // PKCE verification (S256)
        if ( ! empty( $data['challenge'] ) ) {
            $expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
            if ( ! hash_equals( $expected, $data['challenge'] ) ) {
                return self::oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
            }
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_in = 30 * DAY_IN_SECONDS;
        set_transient( 'tlms_mcp_token_' . $token, $data['user_id'], $expires_in );

        $r = new WP_REST_Response( [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $expires_in,
        ], 200 );
        $r->header( 'Cache-Control', 'no-store' );
        return $r;
    }

    // ── CORS for all tutor-mcp REST routes ────────────────────────────────────

    public static function add_cors_headers( WP_HTTP_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_HTTP_Response {
        if ( str_starts_with( $request->get_route(), '/' . self::NS ) ) {
            $response->header( 'Access-Control-Allow-Origin', '*' );
            $response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
            $response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id' );
        }
        return $response;
    }

    // ── Token validation (called by TLMS_MCP_Auth) ────────────────────────────

    public static function validate_bearer_token( string $token ): int|false {
        return get_transient( 'tlms_mcp_token_' . $token );
    }

    // ── Revoke tokens ─────────────────────────────────────────────────────────

    /**
     * Revoke MCP tokens.
     * Pass a user_id > 0 to revoke only that user's tokens; pass 0 to revoke all.
     */
    public static function revoke_all_tokens( int $user_id = 0 ): void {
        global $wpdb;
        if ( $user_id > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value = %s",
                    $wpdb->esc_like( '_transient_tlms_mcp_token_' ) . '%',
                    (string) $user_id
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( '_transient_tlms_mcp_token_' ) . '%'
                )
            );
        }
    }

    /** Count active MCP tokens, optionally grouped by user. */
    public static function get_active_tokens(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value AS user_id FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_tlms_mcp_token_' ) . '%'
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Role check (shared with auth class) ───────────────────────────────────

    public static function user_has_access( WP_User $user ): bool {
        if ( ! $user->exists() ) {
            return false;
        }
        // Default: require administrator or Tutor instructor capability.
        // Site owners can widen or narrow access via the filter.
        $has_access = $user->has_cap( 'manage_options' ) || $user->has_cap( 'manage_tutor' );
        return apply_filters( 'tlms_mcp_user_has_access', $has_access, $user );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function oauth_error( string $error, string $desc, int $status ): WP_REST_Response {
        $r = new WP_REST_Response( [
            'error'             => $error,
            'error_description' => $desc,
        ], $status );
        $r->header( 'Access-Control-Allow-Origin', '*' );
        return $r;
    }
}
