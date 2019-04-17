<?php

namespace InvincibleBrands\WcMfpc;


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
    const defaults               = [
        'hosts'                   => '127.0.0.1:11211',
        'memcached_binary'        => false,
        'authpass'                => '',
        'authuser'                => '',
        'browsercache'            => 0,
        'browsercache_home'       => 0,
        'browsercache_taxonomy'   => 0,
        'expire'                  => 300,
        'expire_home'             => 300,
        'expire_taxonomy'         => 300,
        'invalidation_method'     => 0,
        'prefix_meta'             => 'meta-',
        'prefix_data'             => 'data-',
        'charset'                 => 'utf-8',
        'log'                     => true,
        'cache_type'              => 'memcached',
        'cache_loggedin'          => false,
        'nocache_home'            => false,
        'nocache_feed'            => false,
        'nocache_archive'         => false,
        'nocache_single'          => false,
        'nocache_page'            => false,
        'nocache_cookies'         => false,
        'nocache_dyn'             => true,
        'nocache_woocommerce'     => true,
        'nocache_woocommerce_url' => '',
        'nocache_url'             => '^/wp-',
        'nocache_comment'         => '',
        'response_header'         => false,
        'generate_time'           => false,
        'precache_schedule'       => 'null',
        'key'                     => '$scheme://$host$request_uri',
        'comments_invalidate'     => true,
        'pingback_header'         => false,
        'hashkey'                 => false,
    ];

    private $plugin_file;
    private $defaults;
    private $plugin_url;
    private $plugin_dir;
    private $admin_css_url;

    /**
     * Data constructor.
     */
    public function __construct()
    {
        $this->plugin_file          = getcwd() . '/' . Data::plugin_constant . '.php';
        error_log($this->plugin_file);
        $this->defaults             = WC_MFPC_DEFAULTS;
        $this->plugin_url           = plugin_dir_url(dirname(__FILE__));
        $this->plugin_dir           = plugin_dir_path(dirname(__FILE__));
        $this->admin_css_url        = $this->plugin_url . 'assets/admin.css';
    }

}