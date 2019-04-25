<?php
/*
    Plugin Name: WooCommerce Memcached Full Page Cache
    Plugin URI: https://github.com/agaleski/woocommerce-memcached-full-page-cache
    Description: WooCommerce full page cache plugin based on Memcached.
    Version: 0.1
    Author: Achim Galeski <achim@invinciblebrands.com>
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

use InvincibleBrands\WcMfpc\Config;
use InvincibleBrands\WcMfpc\Data;
use InvincibleBrands\WcMfpc\WcMfpc;

global $wcMfpcConfig, $wcMfpcData, $wcMfpc;

$wcMfpcConfig = new Config();
$wcMfpcData   = new Data();
$wcMfpc       = new WcMfpc();

add_action('init', [ &$wcMfpc, 'init' ]);
