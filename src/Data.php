<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Data
 *
 * @package InvincibleBrands\WcMfpc
 */
final class Data
{

    const host_separator         = ',';
    const port_separator         = ':';
    const global_config_var      = '$wc_mfpc_config_array';
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
    const admin_css_url          = WC_MFPC_PLUGIN_URL . 'assets/admin.css';
    const cache_control_action   = 'wc-mfpc-clear-keys';
    const acache                 = WP_CONTENT_DIR . '/advanced-cache.php';
    const acache_worker          = WC_MFPC_PLUGIN_DIR . 'wc-mfpc-advanced-cache.php';
    const settings_link          = 'admin.php?page=' . self::plugin_settings_page;

}