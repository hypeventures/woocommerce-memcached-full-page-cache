<?php
/**
 * Uninstall file for WC-MFPC;
 * (!) Hook does not remove the databse options
 */

/*
 * Exit if uninstall not called from WordPress
 */
if (! defined( 'WP_UNINSTALL_PLUGIN') || ! defined('ABSPATH')) {

    exit;

}

/*
 * get the worker file
 */
include_once 'woocommerce-memcached-full-page-cache.php';

/*
 * run uninstall function
 */
$wc_mfpc->plugin_uninstall();
