<?php
/**
 * Uninstall file for WC-MFPC;
 * (!) Hook does not remove the database options.
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
