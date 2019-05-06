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

    const globalConfigVar           = '$wc_mfpc_config_array';
    const keySave                   = 'saved';
    const keyRefresh                = 'deleted';
    const keyFlush                  = 'flushed';
    const slugFlush                 = '&flushed=true';
    const slugSave                  = '&saved=true';
    const slugReset                 = '&deleted=true';
    const pluginConstant            = 'woocommerce-memcached-full-page-cache';
    const pluginName                = 'WC-MFPC';
    const capability                = 'manage_options';
    const globalOption              = 'wc-mfpc-global';
    const buttonFlush               = 'wc-mfpc-flush';
    const buttonSave                = 'wc-mfpc-save';
    const buttonReset               = 'wc-mfpc-delete';
    const pluginSettingsPage        = 'wc-mfpc-settings';
    const adminCssHandle            = 'wc-mfpc-admin-css';
    const adminCssUrl               = WC_MFPC_PLUGIN_URL . 'assets/admin.css';
    const cacheControlClearAction   = 'wc-mfpc-cache-control-keys';
    const cacheControlRefreshAction = 'wc-mfpc-cache-control-refresh';
    const advancedCache             = WP_CONTENT_DIR . '/advanced-cache.php';
    const advancedCacheWorker       = WC_MFPC_PLUGIN_DIR . 'wc-mfpc-advanced-cache.php';
    const settingsLink              = 'admin.php?page=' . self::pluginSettingsPage;

}