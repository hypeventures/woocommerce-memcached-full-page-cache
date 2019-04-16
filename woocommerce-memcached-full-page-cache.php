<?php
/*
    Plugin Name: WooCommerce Memcached Full Page Cache
    Plugin URI: https://github.com/agaleski/woocommerce-memcached-full-page-cache
    Description: WooCommerce full page cache plugin based on Memcached.
    Version: 0.1
    Author: Achim Galeski <hello@petermolnar.eu>
    Author URI: https://achim-galeski.de/
    License: GPLv3
    Text Domain: woocommerce-memcached-full-page-cache
    Domain Path: /languages/
*/

/*
    Copyright 2019 Achim Galeski ( achim@invinciblebrands.com )

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

include_once('vendor/autoload.php');

if (! defined('WC_MFPC_DEFAULTS')) {

    define ('WC_MFPC_DEFAULTS', [
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
    ]);

}

use InvincibleBrands\WcMfpc\WcMfpc;

$wc_mfpc = new WcMfpc(WC_MFPC_DEFAULTS);
