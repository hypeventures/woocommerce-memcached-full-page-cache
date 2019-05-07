<?php
/*
Plugin Name: WooCommerce Memcached Full Page Cache
Plugin URI: https://github.com/agaleski/woocommerce-memcached-full-page-cache
Description: WooCommerce full page cache plugin based on Memcached.
Version: 0.1
Author: Achim Galeski <achim@invinciblebrands.com>
Author URI: https://achim-galeski.de/
License: GPLv3
Text Domain: wc-mfpc
Domain Path: /languages/
*/

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

if (! defined('ABSPATH')) { exit; }

include_once 'vendor/autoload.php';

use InvincibleBrands\WcMfpc\Config;
use InvincibleBrands\WcMfpc\Data;
use InvincibleBrands\WcMfpc\WcMfpc;
use InvincibleBrands\WcMfpc\Admin;
use InvincibleBrands\WcMfpc\AdminView;

/**
 * @var WcMfpc $wcMfpc
 */
$wcMfpc = null;

/**
 * @var Config $wcMfpcConfig
 */
$wcMfpcConfig = null;

add_action('init', 'wc_mfpc_init_plugin');
add_action('admin_init', 'wc_mfpc_admin_init_plugin');
add_action('admin_menu', 'wc_mfpc_admin_init_menu', 101);
add_action('admin_bar_init', 'wc_mfpc_admin_bar_init');

/**
 * Initializes the plugin.
 *
 * @return void
 */
function wc_mfpc_init_plugin()
{
    global $wcMfpcConfig, $wcMfpc;

    error_log('init_plugin');

    define('WC_MFPC_PLUGIN_DIR', __DIR__ . '/');
    define('WC_MFPC_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('WC_MFPC_PLUGIN_FILE', basename(__FILE__) . '/' . Data::pluginConstant . '.php');

    $wcMfpcConfig = new Config();
    $wcMfpcConfig->load();

    $wcMfpc = new WcMfpc();
    $wcMfpc->init();
}

/**
 * Initializes the plugins admin.
 *
 * @return void
 */
function wc_mfpc_admin_init_plugin()
{
    error_log('admin_init_plugin');

    $admin = new Admin();
    $admin->setHooks();
}

/**
 * Sets the no-cache cookie to avoid caching of views including the admin bar.
 *
 * @return void
 */
function wc_mfpc_admin_bar_init()
{
    error_log('admin_bar_init');

    if (empty($_COOKIE[ 'wc-mfpc-nocache' ])) {

        setcookie('wc-mfpc-nocache', 1, time() + 604800);

    }
}

/**
 * Adds the link to settings page to the WooCommerce admin menu.
 *
 * @return void
 */
function wc_mfpc_admin_init_menu()
{
    error_log('admin_init_menu');

    add_submenu_page(
        'woocommerce',
        Data::pluginName . ' options',
        'Full Page Cache',
        Data::capability,
        Data::pluginSettingsPage,
        'wc_mfpc_admin_init_view'
    );
}

/**
 * Adds the link to settings page to the WooCommerce admin menu.
 *
 * @return void
 */
function wc_mfpc_admin_init_view()
{
    error_log('admin_init_render');

    $adminView = new AdminView();
    $adminView->render();
}
