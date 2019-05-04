<?php
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

unlink(Data::acache);

delete_site_option(Data::global_option);
