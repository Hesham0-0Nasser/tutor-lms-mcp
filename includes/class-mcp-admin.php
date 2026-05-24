<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page — Tutor LMS MCP Server.
 * User generates API key from Tutor LMS > Tools > REST API and pastes it here.
 */
class TLMS_MCP_Admin {

    public static function handle_test_ajax(): void {
        check_ajax_referer( 'tlms_mcp_test_api', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $auth = self::get_auth_header();
        if ( empty( $auth ) ) {
            wp_send_json_error( [ 'message' => 'API keys not configured.' ] );
        }

        $url      = add_query_arg( 'per_page', 1, rest_url( 'tutor/v1/courses' ) );
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => $auth ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 === $status ) {
            wp_send_json_success( [ 'status' => 200 ] );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            wp_send_json_error( [ 'message' => 'Tutor API returned status ' . $status . ': ' . ( $body['message'] ?? '' ), 'status' => $status ] );
        }
    }

    public static function add_menu(): void {
        add_menu_page(
            'Tutor LMS MCP Server',
            'Tutor MCP',
            'manage_options',
            'tlms-mcp-settings',
            [ __CLASS__, 'render_page' ],
            'dashicons-rest-api',
            58
        );
    }

    public static function register_settings(): void {
        register_setting( 'tlms_mcp_settings', 'tlms_mcp_enabled',  [ 'type' => 'boolean', 'default' => true ] );
        register_setting( 'tlms_mcp_settings', 'tlms_mcp_api_key',    [ 'type' => 'string',  'default' => '' ] );
        register_setting( 'tlms_mcp_settings', 'tlms_mcp_secret_key', [ 'type' => 'string',  'default' => '' ] );

        // Handle token revocation form submission
        if (
            isset( $_POST['tlms_mcp_revoke_all'] ) &&
            current_user_can( 'manage_options' ) &&
            check_admin_referer( 'tlms_mcp_revoke_all' )
        ) {
            TLMS_MCP_OAuth::revoke_all_tokens( 0 );
            wp_redirect( add_query_arg( [ 'page' => 'tlms-mcp-settings', 'tokens-revoked' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    /** Returns the saved Basic auth header value, or empty string if not configured. */
    public static function get_auth_header(): string {
        $key    = get_option( 'tlms_mcp_api_key', '' );
        $secret = get_option( 'tlms_mcp_secret_key', '' );
        if ( empty( $key ) || empty( $secret ) ) return '';
        return 'Basic ' . base64_encode( $key . ':' . $secret );
    }

    public static function render_page(): void {
        $endpoint   = add_query_arg( 'rest_route', '/tutor-mcp/v1/mcp', home_url( '/' ) );
        $enabled    = get_option( 'tlms_mcp_enabled', true );
        $api_key    = get_option( 'tlms_mcp_api_key', '' );
        $secret_key = get_option( 'tlms_mcp_secret_key', '' );
        $configured = ! empty( $api_key ) && ! empty( $secret_key );
        ?>
        <div class="wrap">
            <h1>Tutor LMS MCP Server</h1>
            <p>Connect Claude AI to your Tutor LMS — create courses, manage students, quizzes, enrollments, and more.</p>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success"><p>Settings saved.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['tokens-revoked'] ) ) : ?>
                <div class="notice notice-success"><p>All MCP access tokens revoked.</p></div>
            <?php endif; ?>

            <!-- Status -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0">Status</h2>
                <p>
                    MCP Server: <strong style="color:<?php echo $enabled ? '#059669' : '#dc2626'; ?>"><?php echo $enabled ? 'Active' : 'Disabled'; ?></strong>
                    &nbsp;|&nbsp;
                    Tutor API Key: <strong style="color:<?php echo $configured ? '#059669' : '#dc2626'; ?>"><?php echo $configured ? 'Configured ✓' : 'Not configured'; ?></strong>
                </p>
                <p style="margin-bottom:4px"><strong>MCP Endpoint URL</strong> (paste this into Claude.ai → Settings → Integrations):</p>
                <div style="display:flex;gap:8px;align-items:center;">
                    <code style="background:#f0f0f0;padding:10px 14px;border-radius:4px;flex:1;font-size:13px;word-break:break-all;"><?php echo esc_html( $endpoint ); ?></code>
                    <button onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000);" class="button">Copy</button>
                </div>
            </div>

            <!-- Step 1: Generate Tutor API Key -->
            <div style="background:#fff;border:2px solid #4f46e5;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0;color:#4f46e5">Step 1 — Generate Tutor LMS API Key</h2>
                <ol style="line-height:2.2;">
                    <li>Go to <strong>Tutor LMS → Tools → REST API</strong></li>
                    <li>Click <strong>Add Key</strong></li>
                    <li>Set a description (e.g. "Claude MCP") and set permissions to <strong>Read/Write</strong></li>
                    <li>Click <strong>Generate API Key</strong></li>
                    <li>Copy the <strong>Consumer Key</strong> and <strong>Consumer Secret</strong></li>
                </ol>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tutor-tools&sub_page=tutor_rest_api' ) ); ?>" class="button button-primary" target="_blank">
                    Open Tutor LMS → REST API
                </a>
            </div>

            <!-- Step 2: Paste keys here -->
            <div style="background:#fff;border:2px solid #059669;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0;color:#059669">Step 2 — Enter API Keys</h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'tlms_mcp_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>Enable MCP Server</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="tlms_mcp_enabled" value="1" <?php checked( $enabled ); ?> />
                                    Allow Claude AI to connect and manage Tutor LMS
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tlms_api_key">Consumer Key (API Key)</label></th>
                            <td>
                                <input type="text" id="tlms_api_key" name="tlms_mcp_api_key"
                                       value="<?php echo esc_attr( $api_key ); ?>"
                                       class="regular-text" placeholder="ck_xxxxxxxxxxxx" />
                                <p class="description">From Tutor LMS → Tools → REST API → Consumer Key</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tlms_secret_key">Consumer Secret</label></th>
                            <td>
                                <input type="password" id="tlms_secret_key" name="tlms_mcp_secret_key"
                                       value="<?php echo esc_attr( $secret_key ); ?>"
                                       class="regular-text" placeholder="cs_xxxxxxxxxxxx" />
                                <p class="description">From Tutor LMS → Tools → REST API → Consumer Secret</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save Keys' ); ?>
                </form>
            </div>

            <!-- Step 3: Connect Claude -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0">Step 3 — Connect in Claude.ai</h2>
                <ol style="line-height:2.2;">
                    <li>Open <strong>claude.ai → Settings → Integrations</strong></li>
                    <li>Click <strong>Add connector</strong></li>
                    <li>Paste the endpoint URL above and click Connect</li>
                    <li>Log in with your WordPress admin account when prompted and click <strong>Authorize</strong></li>
                </ol>
            </div>

            <!-- Test API Key -->
            <?php if ( $configured ) : ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0">Test API Key</h2>
                <p>Click below to verify your Tutor LMS API key is working correctly.</p>
                <button id="tlms-test-key" class="button button-secondary">Test Connection</button>
                <div id="tlms-test-result" style="margin-top:12px;font-size:13px;"></div>
                <script>
                document.getElementById('tlms-test-key').addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true; btn.textContent = 'Testing…';
                    fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=tlms_mcp_test_api&nonce=<?php echo esc_js( wp_create_nonce( 'tlms_mcp_test_api' ) ); ?>'
                    })
                    .then(r => r.json())
                    .then(d => {
                        var ok = d.success && d.data && d.data.status === 200;
                        document.getElementById('tlms-test-result').innerHTML =
                            '<span style="color:' + (ok?'#059669':'#dc2626') + ';font-weight:600;">' +
                            (ok ? '✓ Connected! Tutor API responding.' : '✗ ' + (d.data ? d.data.message : 'Request failed')) +
                            '</span>';
                    })
                    .catch(e => { document.getElementById('tlms-test-result').textContent = 'Error: ' + e.message; })
                    .finally(() => { btn.disabled = false; btn.textContent = 'Test Connection'; });
                });
                </script>
            </div>
            <?php endif; ?>

            <!-- Active Tokens -->
            <?php
            $active_tokens = TLMS_MCP_OAuth::get_active_tokens();
            // Group by user_id
            $by_user = [];
            foreach ( $active_tokens as $row ) {
                $uid = (int) $row['user_id'];
                $by_user[ $uid ] = ( $by_user[ $uid ] ?? 0 ) + 1;
            }
            ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:720px;margin-bottom:20px;">
                <h2 style="margin-top:0">Active Tokens</h2>
                <?php if ( empty( $by_user ) ) : ?>
                    <p style="color:#6b7280;">No active MCP tokens. Claude.ai is not currently connected.</p>
                <?php else : ?>
                    <p>The following accounts have active MCP access tokens:</p>
                    <table class="widefat striped" style="font-size:13px;margin-bottom:16px;">
                        <thead><tr><th>User</th><th>Active Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ( $by_user as $uid => $count ) :
                            $u = get_userdata( $uid );
                            $label = $u ? esc_html( $u->user_login . ' (' . $u->display_name . ')' ) : "User #{$uid} (deleted)";
                        ?>
                            <tr>
                                <td><?php echo $label; ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post" action="options.php" onsubmit="return confirm('Revoke all MCP tokens? Claude.ai will need to reconnect.');">
                        <?php wp_nonce_field( 'tlms_mcp_revoke_all' ); ?>
                        <input type="hidden" name="tlms_mcp_revoke_all" value="1" />
                        <button type="submit" class="button button-secondary" style="color:#dc2626;border-color:#dc2626;">
                            Revoke All Tokens
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Available Tools -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:720px;">
                <h2 style="margin-top:0">Available Tools (<?php echo count( TLMS_MCP_Tools::list_tools() ); ?>)</h2>
                <table class="widefat striped" style="font-size:13px;">
                    <thead><tr><th>Tool</th><th>Description</th></tr></thead>
                    <tbody>
                        <?php foreach ( TLMS_MCP_Tools::list_tools() as $tool ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $tool['name'] ); ?></code></td>
                                <td><?php echo esc_html( $tool['description'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
