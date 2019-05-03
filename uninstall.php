<?php
/**
 * Uninstall file for WooCommerce-Memcached-Full-Page-Cache.
 *
 * @uses defined()
 * @uses Data
 * @uses unlink()
 * @uses delete_site_option()
 */

/*
 * Exit if uninstall not called from WordPress
 */
if (! defined( 'WP_UNINSTALL_PLUGIN') || ! defined('ABSPATH')) {

    exit;

}

include_once 'vendor/autoload.php';

use InvincibleBrands\WcMfpc\Data;

unlink(WP_CONTENT_DIR . '/advanced-cache.php');

delete_site_option(Data::global_option);
