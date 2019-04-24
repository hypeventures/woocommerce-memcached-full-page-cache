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
    const global_config_var      = '$wcMfpcConfig';
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
    public $plugin_file = '';

    /**
     * @var array
     */
    public $defaults = [];

    /**
     * @var string
     */
    public $plugin_url = '';

    /**
     * @var string
     */
    public $plugin_dir = '';

    /**
     * @var string
     */
    public $admin_css_url = '';

    /**
     * @var string
     */
    public $precache_logfile = '';

    /**
     * @var string
     */
    public $precache_phpfile = '';

    /**
     * @var mixed|string
     */
    public $shell_function = false;

    /**
     * @var string
     */
    public $acache_worker = '';

    /**
     * @var string
     */
    public $acache = '';

    /**
     * @var string
     */
    public $global_config_key = '';

    /**
     * @var bool
     */
    public $network = false;

    /**
     * @var string
     */
    public $settings_slug = '';

    /**
     * @var string
     */
    public $settings_link = '';

    /**
     * Data constructor.
     */
    public function __construct()
    {
        $defaultConfig              = new Config();

        $this->plugin_file          = basename(dirname(__FILE__, 2)) . '/' . self::plugin_constant . '.php';
        $this->defaults             = $defaultConfig->getConfig();
        $this->plugin_url           = plugin_dir_url(dirname(__FILE__));
        $this->plugin_dir           = plugin_dir_path(dirname(__FILE__));
        $this->admin_css_url        = $this->plugin_url . 'assets/admin.css';
        $this->precache_logfile     = sys_get_temp_dir() . '/' . self::precache_log;
        $this->precache_phpfile     = sys_get_temp_dir() . '/' . self::precache_php;
        $this->acache_worker        = $this->plugin_dir . 'wc-mfpc-advanced-cache.php';
        $this->acache               = WP_CONTENT_DIR . '/advanced-cache.php';

        $this->setShellFunction();
        $this->setSettingsLinkAndSlug();
    }

    /**
     * Sets the possible shell function if it finds one.
     *
     * @return void
     */
    private function setShellFunction()
    {
        $disabled = array_flip(array_map('trim', explode(',', ini_get('disable_functions'))));

        foreach (self::shell_possibilities as $possibility) {

            if (function_exists($possibility) && ! (ini_get('safe_mode') || isset($disabled[ $possibility ]))) {

                $this->shell_function = $possibility;
                return;

            }

        }
    }

    /**
     * Checks if plugin is activated via network and sets Slug & Link for the Settings-Page accordingly.
     *
     * @return void
     */
    private function setSettingsLinkAndSlug()
    {
        $this->settings_slug = 'options-general.php';

        if (! function_exists('is_plugin_active_for_network') && @is_plugin_active_for_network($this->plugin_file)) {

            require_once(ABSPATH . 'wp-admin/includes/plugin.php');

            $this->network       = true;
            $this->settings_slug = 'settings.php';

        }

        $this->settings_link = $this->settings_slug . '?page=' . Data::plugin_settings_page;
    }

}