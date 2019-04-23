<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Data
 *
 * @package InvincibleBrands\WcMfpc
 */
class Data
{

    const host_separator         = ',';
    const port_separator         = ':';
    const donation_id_key        = 'hosted_button_id=';
    const global_config_var      = 'wcMfpcConfig';
    const key_save               = 'saved';
    const key_delete             = 'deleted';
    const key_flush              = 'flushed';
    const slug_flush             = '&flushed=true';
    const key_precache           = 'precached';
    const slug_precache          = '&precached=true';
    const key_precache_disabled  = 'precache_disabled';
    const slug_precache_disabled = '&precache_disabled=true';
    const precache_log           = 'wc-mfpc-precache-log';
    const precache_timestamp     = 'wc-mfpc-precache-timestamp';
    const precache_php           = 'wc-mfpc-precache.php';
    const precache_id            = 'wc-mfpc-precache-task';
    const slug_save              = '&saved=true';
    const slug_delete            = '&deleted=true';
    const common_slug            = 'wp-common/';
    const plugin_constant        = 'woocommerce-memcached-full-page-cache';
    const plugin_name            = 'WC-MFPC';
    const capability             = 'manage_options';
    const global_option          = 'wc-mfpc-global';
    const button_precache        = 'wc-mfpc-precache';
    const button_flush           = 'wc-mfpc-flush';
    const button_save            = 'wc-mfpc-save';
    const button_delete          = 'wc-mfpc-delete';
    const plugin_settings_page   = 'wc-mfpc-settings';
    const admin_css_handle       = 'wc-mfpc-admin-css';
    const shell_possibilities    = [ 'shell_exec', 'exec', 'system', 'passthru' ];

    /**
     * @var string
     */
    public static $plugin_file   = '';

    /**
     * @var array
     */
    public static $defaults      = [];

    /**
     * @var string
     */
    public static $plugin_url    = '';

    /**
     * @var string
     */
    public static $plugin_dir    = '';

    /**
     * @var string
     */
    public static $admin_css_url = '';

    /**
     * @var string
     */
    public static $precache_logfile = '';

    /**
     * @var string
     */
    public static $precache_phpfile = '';

    /**
     * @var mixed|string
     */
    public $shell_function = false;

    /**
     * Data constructor.
     */
    public function __construct()
    {
        $defaultConfig              = new Config();

        self::$plugin_file          = basename(dirname(__FILE__, 2)) . '/' . self::plugin_constant . '.php';
        self::$defaults             = $defaultConfig->getConfig();
        self::$plugin_url           = plugin_dir_url(dirname(__FILE__));
        self::$plugin_dir           = plugin_dir_path(dirname(__FILE__));
        self::$admin_css_url        = self::$plugin_url . 'assets/admin.css';
        self::$precache_logfile     = sys_get_temp_dir() . '/' . self::precache_log;
        self::$precache_phpfile     = sys_get_temp_dir() . '/' . self::precache_php;

        $disabled = array_flip(array_map('trim', explode(',', ini_get('disable_functions'))));

        foreach (self::shell_possibilities as $possible) {

            if (function_exists($possible) && ! (ini_get('safe_mode') || isset($disabled[ $possible ]))) {

                $this->shell_function = $possible;
                break;

            }

        }
    }

}