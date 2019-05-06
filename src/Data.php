<?php
/*
    WooCommerce Memcached Full Page Cache - FPC the WooCommerece way via PHP-Memcached.
    Copyright (C)  2019 Achim Galeski ( achim@invinciblebrands.com )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA02110-1301USA
*/

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Data
 *
 * @package InvincibleBrands\WcMfpc
 */
final class Data
{

    const host_separator               = ',';
    const port_separator               = ':';
    const global_config_var            = '$wc_mfpc_config_array';
    const key_save                     = 'saved';
    const key_delete                   = 'deleted';
    const key_flush                    = 'flushed';
    const slug_flush                   = '&flushed=true';
    const slug_save                    = '&saved=true';
    const slug_reset                  = '&deleted=true';
    const plugin_constant              = 'woocommerce-memcached-full-page-cache';
    const plugin_name                  = 'WC-MFPC';
    const capability                   = 'manage_options';
    const global_option                = 'wc-mfpc-global';
    const button_flush                 = 'wc-mfpc-flush';
    const button_save                  = 'wc-mfpc-save';
    const button_reset                = 'wc-mfpc-delete';
    const plugin_settings_page         = 'wc-mfpc-settings';
    const admin_css_handle             = 'wc-mfpc-admin-css';
    const admin_css_url                = WC_MFPC_PLUGIN_URL . 'assets/admin.css';
    const cache_control_clear_action   = 'wc-mfpc-cache-control-keys';
    const cache_control_refresh_action = 'wc-mfpc-cache-control-refresh';
    const acache                       = WP_CONTENT_DIR . '/advanced-cache.php';
    const acache_worker                = WC_MFPC_PLUGIN_DIR . 'wc-mfpc-advanced-cache.php';
    const settings_link                = 'admin.php?page=' . self::plugin_settings_page;

}