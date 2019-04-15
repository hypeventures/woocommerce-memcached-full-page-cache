<?php
/**
 * uninstall file for WP-FFPC; uninstall hook does not remove the databse options
 */

// exit if uninstall not called from WordPress
if (! defined( 'WP_UNINSTALL_PLUGIN') || ! defined('ABSPATH')) { exit; }

/* get the worker file */
include_once ( 'woocommerce-memcached-full-page-cache.php' );

/* run uninstall function */
$wp_ffpc->plugin_uninstall();
