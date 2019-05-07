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
 * Class WcMfpc
 *
 * @package InvincibleBrands\WcMfpc
 */
class WcMfpc
{

    /**
     * @var null|Memcached   Contains the active Memcached-Server connection if initialized.
     */
    private static $memcached = null;

    /**
     * Activation hook function. Redirects to settings page
     *
     * @return void
     */
    public static function pluginActivate()
    {
        wp_redirect(admin_url() . Data::settingsLink, 302);
        wp_die();
    }

    /**
     * Removes current site config from global config on deactivation.
     *
     * @return void
     */
    public static function pluginDeactivate()
    {
        global $wcMfpcConfig;

        $wcMfpcConfig->delete();
    }

    /**
     * admin panel, load plugin textdomain
     *
     * @return void
     */
    public static function loadTextdomain()
    {
        load_plugin_textdomain('wc-mfpc', false, WC_MFPC_PLUGIN_DIR . 'languages/');
    }

    /**
     * Returns the Memcached connection. Initiates it if it does not exist.
     *
     * @return Memcached
     */
    public static function getMemcached()
    {
        if (empty(self::$memcached)) {

            global $wcMfpcConfig;

            self::$memcached = new Memcached($wcMfpcConfig->getConfig());

        }

        return self::$memcached;
    }

    /**
     * Handles clearing of posts and taxonomies.
     *
     * @param int|string $postId  ID of the post to invalidate
     *
     * @return bool
     */
    public static function clearPostCache($postId = null)
    {
        if (
            empty($postId)
            || ! self::getMemcached()->isAlive()
            || wp_is_post_autosave($postId)
            || wp_is_post_revision($postId)
        ) {

            return false;
        }

        $permalink  = get_permalink($postId);

        if (empty($permalink)) {

            #error_log('Unable to determine path for Post Permalink, post ID: ' . $postId, LOG_WARNING);

            return false;
        }

        $permalinks = [];

        /*
         * It's possible that post/page is paginated with <!--nextpage--> as Wordpress doesn't seem to expose the
         * number of pages via API. So let's just count it.
         */
        $numberOfPages = 1 + (int) preg_match_all('/<!--nextpage-->/', get_post($postId)->post_content, $matches);
        $currentPageId = 0;

        do {

            $currentPageId            = 1 + (int) $currentPageId;
            $permalinks[ $permalink ] = true;

        } while ($numberOfPages > 1 && $currentPageId <= $numberOfPages);

        return self::getMemcached()
                    ->clearLinks($permalinks);
    }

}
