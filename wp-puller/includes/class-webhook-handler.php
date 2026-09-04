<?php
/**
 * Webhook Handler class for WP Puller.
 *
 * A single REST endpoint receives GitHub push events for every configured
 * package. On a push it matches the payload's repository and branch against
 * all packages and updates each match (respecting auto-update). The webhook
 * signature is verified against the global secret and any per-package custom
 * secret.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP_Puller_Webhook_Handler Class.
 */
class WP_Puller_Webhook_Handler {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const REST_NAMESPACE = 'wp-puller/v1';

    /**
     * REST API route.
     *
     * @var string
     */
    const REST_ROUTE = '/webhook';

    /**
     * Package updater instance.
     *
     * @var WP_Puller_Updater
     */
    private $updater;

    /**
     * Logger instance.
     *
     * @var WP_Puller_Logger
     */
    private $logger;

    /**
     * GitHub API instance.
     *
     * @var WP_Puller_GitHub_API
     */
    private $github_api;

    /**
     * Constructor.
     *
     * @param WP_Puller_Updater    $updater    Package updater instance.
     * @param WP_Puller_Logger     $logger     Logger instance.
     * @param WP_Puller_GitHub_API $github_api GitHub API instance.
     */
    public function __construct( $updater, $logger, $github_api ) {
        $this->updater    = $updater;
        $this->logger     = $logger;
        $this->github_api = $github_api;
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Get the webhook URL.
     *
     * @return string
     */
    public static function get_webhook_url() {
        return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
    }

    /**
     * Handle incoming webhook request.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_webhook( $request ) {
        if ( ! $this->check_rate_limit() ) {
            $response = new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Too many requests.',
                ),
                429
            );
            $response->header( 'Retry-After', '60' );
            return $response;
        }

        $signature = $request->get_header( 'X-Hub-Signature-256' );
        $event     = $request->get_header( 'X-GitHub-Event' );
        $delivery  = $request->get_header( 'X-GitHub-Delivery' );

        $this->logger->log(
            sprintf(
                /* translators: %1$s: event type, %2$s: delivery ID */
                __( 'Webhook received: %1$s (delivery: %2$s)', 'wp-puller' ),
                $event ?: 'unknown',
                $delivery ?: 'unknown'
            ),
            WP_Puller_Logger::STATUS_INFO,
            WP_Puller_Logger::SOURCE_WEBHOOK
        );

        if ( empty( $signature ) ) {
            $this->logger->log(
                __( 'Webhook rejected: missing signature', 'wp-puller' ),
                WP_Puller_Logger::STATUS_ERROR,
                WP_Puller_Logger::SOURCE_WEBHOOK
            );

            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Missing signature header.',
                ),
                401
            );
        }

        $body = $request->get_body();

        if ( ! $this->verify_signature( $body, $signature ) ) {
            $this->logger->log(
                __( 'Webhook rejected: invalid signature', 'wp-puller' ),
                WP_Puller_Logger::STATUS_ERROR,
                WP_Puller_Logger::SOURCE_WEBHOOK
            );

            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Invalid signature.',
                ),
                401
            );
        }

        if ( 'ping' === $event ) {
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => 'Pong! Webhook is configured correctly.',
                ),
                200
            );
        }

        if ( 'push' !== $event ) {
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => 'Event type not handled.',
                ),
                200
            );
        }

        $payload = json_decode( $body, true );

        if ( ! $payload ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Invalid JSON payload.',
                ),
                400
            );
        }

        return $this->handle_push_event( $payload );
    }

    /**
     * Handle push event: update every matching package.
     *
     * @param array $payload Push event payload.
     * @return WP_REST_Response
     */
    private function handle_push_event( $payload ) {
        $ref        = isset( $payload['ref'] ) ? $payload['ref'] : '';
        $pushed_branch = str_replace( 'refs/heads/', '', $ref );
        $repo_full  = isset( $payload['repository']['full_name'] ) ? $payload['repository']['full_name'] : '';

        if ( empty( $repo_full ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Push payload missing repository information.',
                ),
                400
            );
        }

        $updated = 0;
        $skipped = 0;

        foreach ( WP_Puller::get_packages() as $pkg ) {
            $parsed = $this->github_api->parse_repo_url( $pkg['repo_url'] );

            if ( ! $parsed ) {
                continue;
            }

            $pkg_full = $parsed['owner'] . '/' . $parsed['repo'];

            if ( $pkg_full !== $repo_full ) {
                continue;
            }

            if ( $pushed_branch !== $pkg['branch'] ) {
                $skipped++;
                $this->logger->log(
                    sprintf(
                        /* translators: %1$s: pushed branch, %2$s: configured branch */
                        __( 'Push to branch %1$s ignored for package "%2$s" (configured: %3$s)', 'wp-puller' ),
                        $pushed_branch,
                        $pkg['label'] ?: $pkg['repo_url'],
                        $pkg['branch']
                    ),
                    WP_Puller_Logger::STATUS_INFO,
                    WP_Puller_Logger::SOURCE_WEBHOOK
                );
                continue;
            }

            if ( empty( $pkg['auto_update'] ) ) {
                $skipped++;
                $this->logger->log(
                    sprintf(
                        /* translators: %s: package label */
                        __( 'Push received for "%s" but auto-update is disabled', 'wp-puller' ),
                        $pkg['label'] ?: $pkg['repo_url']
                    ),
                    WP_Puller_Logger::STATUS_INFO,
                    WP_Puller_Logger::SOURCE_WEBHOOK
                );
                continue;
            }

            $result = $this->updater->update_package( $pkg, WP_Puller_Logger::SOURCE_WEBHOOK );

            if ( is_wp_error( $result ) ) {
                $skipped++;
                $this->logger->log(
                    sprintf(
                        /* translators: %1$s: package label, %2$s: error */
                        __( 'Update failed for "%1$s": %2$s', 'wp-puller' ),
                        $pkg['label'] ?: $pkg['repo_url'],
                        $result->get_error_message()
                    ),
                    WP_Puller_Logger::STATUS_ERROR,
                    WP_Puller_Logger::SOURCE_WEBHOOK
                );
            } else {
                $updated++;
            }
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => sprintf(
                    /* translators: %1$d: updated count, %2$d: skipped count */
                    __( 'Processed push: %1$d package(s) updated, %2$d skipped.', 'wp-puller' ),
                    $updated,
                    $skipped
                ),
            ),
            200
        );
    }

    /**
     * Check whether the current request is within the rate limit.
     *
     * Allows a maximum of 10 requests per minute per IP address.
     *
     * @return bool True if within limit, false if exceeded.
     */
    private function check_rate_limit() {
        $ip = WP_Puller_Client_IP::get();

        if ( empty( $ip ) ) {
            return true;
        }

        $transient_key = 'wp_puller_rl_' . md5( $ip );
        $count         = (int) get_transient( $transient_key );

        if ( $count >= 10 ) {
            return false;
        }

        set_transient( $transient_key, $count + 1, 60 );

        return true;
    }

    /**
     * Verify a GitHub webhook signature against the global and per-package secrets.
     *
     * @param string $payload   Request body.
     * @param string $signature Signature header value.
     * @return bool
     */
    private function verify_signature( $payload, $signature ) {
        $secrets = array();

        $global = WP_Puller::decrypt( get_option( 'wp_puller_webhook_secret', '' ) );
        if ( ! empty( $global ) ) {
            $secrets[] = $global;
        }

        foreach ( WP_Puller::get_packages() as $pkg ) {
            $secret = WP_Puller::get_effective_webhook_secret( $pkg );
            if ( ! empty( $secret ) ) {
                $secrets[] = $secret;
            }
        }

        foreach ( array_unique( $secrets ) as $secret ) {
            $expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
            if ( hash_equals( $expected, $signature ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a new webhook secret (plaintext).
     *
     * @return string
     */
    public static function generate_secret() {
        return wp_generate_password( 32, false );
    }

    /**
     * Get the global plaintext webhook secret.
     *
     * The secret is stored encrypted at rest using WordPress salt-derived
     * encryption (see WP_Puller::encrypt). Legacy installs stored it in
     * plaintext; those are still read transparently for backward
     * compatibility (and upgraded on the next save / admin page view).
     *
     * @return string
     */
    public static function get_secret() {
        $stored = get_option( 'wp_puller_webhook_secret', '' );

        if ( '' === $stored ) {
            return '';
        }

        if ( 0 === strpos( $stored, 'v2:' ) ) {
            return WP_Puller::decrypt( $stored );
        }

        return $stored;
    }

    /**
     * Store the global webhook secret, encrypting it at rest.
     *
     * @param string $plain Plaintext secret.
     */
    public static function store_secret( $plain ) {
        $encrypted = WP_Puller::encrypt( $plain );
        update_option( 'wp_puller_webhook_secret', '' !== $encrypted ? $encrypted : $plain );
    }

    /**
     * Get webhook configuration instructions (global).
     *
     * @return array
     */
    public static function get_setup_instructions() {
        $webhook_url = self::get_webhook_url();

        $raw = get_option( 'wp_puller_webhook_secret', '' );
        if ( '' !== $raw && 0 !== strpos( $raw, 'v2:' ) ) {
            self::store_secret( $raw );
        }

        $webhook_secret = self::get_secret();

        return array(
            'url'          => $webhook_url,
            'secret'       => $webhook_secret,
            'content_type' => 'application/json',
            'events'       => array( 'push' ),
            'steps'        => array(
                __( 'Go to your GitHub repository Settings > Webhooks', 'wp-puller' ),
                __( 'Click "Add webhook"', 'wp-puller' ),
                sprintf(
                    /* translators: %s: webhook URL */
                    __( 'Set Payload URL to: %s', 'wp-puller' ),
                    $webhook_url
                ),
                __( 'Set Content type to: application/json', 'wp-puller' ),
                sprintf(
                    /* translators: %s: webhook secret */
                    __( 'Set Secret to: %s', 'wp-puller' ),
                    $webhook_secret
                ),
                __( 'Select "Just the push event"', 'wp-puller' ),
                __( 'Check "Active" and click "Add webhook"', 'wp-puller' ),
            ),
        );
    }
}
