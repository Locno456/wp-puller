<?php
/**
 * Main WP Puller class.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main WP_Puller Class.
 *
 * @class WP_Puller
 */
final class WP_Puller {

    /**
     * WP_Puller version.
     *
     * @var string
     */
    public $version = '1.0.8';

    /**
     * The single instance of the class.
     *
     * @var WP_Puller
     */
    protected static $instance = null;

    /**
     * GitHub API instance.
     *
     * @var WP_Puller_GitHub_API
     */
    public $github_api = null;

    /**
     * Webhook handler instance.
     *
     * @var WP_Puller_Webhook_Handler
     */
    public $webhook = null;

    /**
     * Package updater instance.
     *
     * @var WP_Puller_Updater
     */
    public $updater = null;

    /**
     * Backup instance.
     *
     * @var WP_Puller_Backup
     */
    public $backup = null;

    /**
     * Logger instance.
     *
     * @var WP_Puller_Logger
     */
    public $logger = null;

    /**
     * Admin instance.
     *
     * @var WP_Puller_Admin
     */
    public $admin = null;

    /**
     * Main WP_Puller Instance.
     *
     * Ensures only one instance of WP_Puller is loaded or can be loaded.
     *
     * @since 1.0.0
     * @return WP_Puller Main instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * WP_Puller Constructor.
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-logger.php';
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-client-ip.php';
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-github-api.php';
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-backup.php';
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-updater.php';
        require_once WP_PULLER_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        if ( is_admin() ) {
            require_once WP_PULLER_PLUGIN_DIR . 'includes/class-admin.php';
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ), 0 );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Init WP_Puller when WordPress initializes.
     */
    public function init() {
        $this->load_textdomain();
        $this->init_classes();

        do_action( 'wp_puller_init' );
    }

    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-puller',
            false,
            dirname( WP_PULLER_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Initialize plugin classes.
     */
    private function init_classes() {
        $this->logger     = new WP_Puller_Logger();
        $this->github_api = new WP_Puller_GitHub_API();
        $this->backup     = new WP_Puller_Backup();
        $this->updater    = new WP_Puller_Updater( $this->github_api, $this->backup, $this->logger );
        $this->webhook    = new WP_Puller_Webhook_Handler( $this->updater, $this->logger, $this->github_api );

        if ( is_admin() ) {
            $this->admin = new WP_Puller_Admin( $this->github_api, $this->updater, $this->backup, $this->logger );
        }
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        if ( $this->webhook ) {
            $this->webhook->register_routes();
        }
    }

    /**
     * Get the plugin URL.
     *
     * @return string
     */
    public function plugin_url() {
        return WP_PULLER_PLUGIN_URL;
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path() {
        return WP_PULLER_PLUGIN_DIR;
    }

    /**
     * Encrypt a value using WordPress salts.
     *
     * Produces a v2 ciphertext: base64( iv[16] + cipher + hmac[32] ).
     * The HMAC-SHA256 covers iv+cipher to prevent ciphertext tampering.
     *
     * @param string $value Value to encrypt.
     * @return string
     */
    public static function encrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $key    = self::get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $cipher ) {
            return '';
        }

        $payload = $iv . $cipher;
        $hmac    = hash_hmac( 'sha256', $payload, $key, true );

        return 'v2:' . base64_encode( $payload . $hmac );
    }

    /**
     * Decrypt a value using WordPress salts.
     *
     * Supports v2 format (with HMAC) and legacy v1 format (without HMAC)
     * for backward compatibility with previously stored values.
     *
     * @param string $value Value to decrypt.
     * @return string
     */
    public static function decrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $key = self::get_encryption_key();

        if ( strpos( $value, 'v2:' ) === 0 ) {
            // v2 format: iv[16] + cipher[n] + hmac[32].
            $data = base64_decode( substr( $value, 3 ) );

            // Minimum: 16 (iv) + 1 (cipher) + 32 (hmac) = 49 bytes.
            if ( false === $data || strlen( $data ) < 49 ) {
                return '';
            }

            $hmac          = substr( $data, -32 );
            $payload       = substr( $data, 0, -32 );
            $expected_hmac = hash_hmac( 'sha256', $payload, $key, true );

            if ( ! hash_equals( $expected_hmac, $hmac ) ) {
                return '';
            }

            $iv     = substr( $payload, 0, 16 );
            $cipher = substr( $payload, 16 );
        } else {
            // Legacy v1 format: iv[16] + cipher[n], no HMAC.
            $data = base64_decode( $value );

            if ( false === $data || strlen( $data ) < 17 ) {
                return '';
            }

            $iv     = substr( $data, 0, 16 );
            $cipher = substr( $data, 16 );
        }

        $decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return false === $decrypted ? '' : $decrypted;
    }

    /**
     * Get encryption key from WordPress salts.
     *
     * @return string
     */
    private static function get_encryption_key() {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : self::get_or_create_fallback_key();
        return hash( 'sha256', $salt, true );
    }

    /**
     * Get or create a site-specific fallback encryption key.
     *
     * Used only when AUTH_KEY is not defined (non-standard WP setups).
     * The key is generated once and stored in the database.
     *
     * @return string
     */
    private static function get_or_create_fallback_key() {
        $key = get_option( 'wp_puller_encryption_key', '' );

        if ( empty( $key ) ) {
            $key = wp_generate_password( 64, true, true );
            update_option( 'wp_puller_encryption_key', $key, false );
        }

        return $key;
    }

    /**
     * Fill in defaults for a package configuration array.
     *
     * @param array $pkg Package configuration.
     * @return array
     */
    public static function normalize_package( $pkg ) {
        return wp_parse_args( (array) $pkg, array(
            'id'            => '',
            'label'         => '',
            'repo_url'      => '',
            'branch'        => 'main',
            'package_type'  => 'plugin',
            'source_path'   => '',
            'plugin_slug'   => '',
            'package_kind'  => 'dir',
            'auto_update'   => true,
            'token_mode'    => 'global',
            'pat'           => '',
            'webhook_mode'  => 'global',
            'webhook_secret' => '',
            'latest_commit' => '',
            'last_check'    => 0,
        ) );
    }

    /**
     * Get all configured packages.
     *
     * @return array
     */
    public static function get_packages() {
        $packages = get_option( 'wp_puller_packages', array() );

        if ( ! is_array( $packages ) ) {
            $packages = array();
        }

        return array_map( array( __CLASS__, 'normalize_package' ), $packages );
    }

    /**
     * Save the packages array, assigning ids to any package missing one.
     *
     * @param array $packages Package configurations.
     * @return array The saved (normalized) packages.
     */
    public static function save_packages( $packages ) {
        $clean = array();

        foreach ( (array) $packages as $pkg ) {
            $pkg = self::normalize_package( $pkg );

            if ( empty( $pkg['id'] ) ) {
                $pkg['id'] = 'pkg_' . substr( md5( uniqid( '', true ) ), 0, 12 );
            }

            $clean[] = $pkg;
        }

        update_option( 'wp_puller_packages', $clean );

        return $clean;
    }

    /**
     * Get a single package by id.
     *
     * @param string $id Package id.
     * @return array|null
     */
    public static function get_package( $id ) {
        foreach ( self::get_packages() as $pkg ) {
            if ( isset( $pkg['id'] ) && $pkg['id'] === $id ) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Resolve the effective GitHub token for a package.
     *
     * Uses the package's custom token when its token_mode is 'custom',
     * otherwise the global token.
     *
     * @param array $pkg Package configuration.
     * @return string
     */
    public static function get_package_token( $pkg ) {
        if ( ! empty( $pkg ) && ! empty( $pkg['token_mode'] ) && 'custom' === $pkg['token_mode'] && ! empty( $pkg['pat'] ) ) {
            return self::decrypt( $pkg['pat'] );
        }

        $encrypted = get_option( 'wp_puller_global_pat', '' );

        return $encrypted ? self::decrypt( $encrypted ) : '';
    }

    /**
     * Resolve the effective webhook secret for a package.
     *
     * Uses the package's custom secret when its webhook_mode is 'custom',
     * otherwise the global webhook secret.
     *
     * @param array $pkg Package configuration.
     * @return string
     */
    public static function get_effective_webhook_secret( $pkg ) {
        if ( ! empty( $pkg ) && ! empty( $pkg['webhook_mode'] ) && 'custom' === $pkg['webhook_mode'] && ! empty( $pkg['webhook_secret'] ) ) {
            return self::decrypt( $pkg['webhook_secret'] );
        }

        $encrypted = get_option( 'wp_puller_webhook_secret', '' );

        return $encrypted ? self::decrypt( $encrypted ) : '';
    }
}
