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
    const global_config_var      = '$wcMfpcConfig';
    const key_save               = 'saved';
    const key_delete             = 'deleted';
    const key_flush              = 'flushed';
    const slug_flush             = '&flushed=true';
    const slug_save              = '&saved=true';
    const slug_delete            = '&deleted=true';
    const plugin_constant        = 'woocommerce-memcached-full-page-cache';
    const plugin_name            = 'WC-MFPC';
    const capability             = 'manage_options';
    const global_option          = 'wc-mfpc-global';
    const button_flush           = 'wc-mfpc-flush';
    const button_save            = 'wc-mfpc-save';
    const button_delete          = 'wc-mfpc-delete';
    const plugin_settings_page   = 'wc-mfpc-settings';
    const admin_css_handle       = 'wc-mfpc-admin-css';
    const shell_possibilities    = [ 'shell_exec', 'exec', 'system', 'passthru' ];
    const cache_control_action   = 'wc-mfpc-clear-keys';

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
        $this->acache_worker        = $this->plugin_dir . 'wc-mfpc-advanced-cache.php';
        $this->acache               = WP_CONTENT_DIR . '/advanced-cache.php';
        $this->settings_slug        = 'admin.php';
        $this->settings_link        = $this->settings_slug . '?page=' . Data::plugin_settings_page;

        $this->setShellFunction();
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

}