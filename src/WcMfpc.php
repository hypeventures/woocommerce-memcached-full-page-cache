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
    public $memcached = null;

    /**
     * Initializes the plugin, sets necessary hooks and establishes the connection to Memcached.
     *
     * @return void
     */
    public function init()
    {
        global $wcMfpcConfig;

        register_activation_hook(WC_MFPC_PLUGIN_FILE, [ &$this, 'pluginActivate' ]);
        register_deactivation_hook(WC_MFPC_PLUGIN_FILE, [ &$this, 'pluginDeactivate' ]);

        $wcMfpcConfig->load();

        if (is_admin()) {

            global $wcMfpcAdmin;

            $wcMfpcAdmin = new Admin();
            $wcMfpcAdmin->setHooks();

            add_action('plugins_loaded', [ &$this, 'loadTextdomain' ]);

        }

        /*
         * if WP_CACHE is not set or false - abort here and safe your time.
         */
        if (! defined('WP_CACHE') || empty(WP_CACHE)) {

            return;
        }

        /*
         * comments invalidation hooks
         */
        if (! empty($wcMfpcConfig->isCommentsInvalidate())) {

            add_action('comment_post', [ &$this, 'clearPostCache' ], 0);
            add_action('edit_comment', [ &$this, 'clearPostCache' ], 0);
            add_action('trashed_comment', [ &$this, 'clearPostCache' ], 0);
            add_action('pingback_post', [ &$this, 'clearPostCache' ], 0);
            add_action('trackback_post', [ &$this, 'clearPostCache' ], 0);
            add_action('wp_insert_comment', [ &$this, 'clearPostCache' ], 0);

        }

        /*
         * invalidation on some other occasions as well
         */
        add_action('switch_theme', [ &$this, 'clearPostCache' ], 0);
        add_action('deleted_post', [ &$this, 'clearPostCache' ], 0);

        /*
         * add filter for catching canonical redirects
         */
        add_filter('redirect_canonical', 'wc_mfpc_redirect_callback', 10, 2);

    }

    /**
     * Activation hook function. Redirects to settings page
     *
     * @return void
     */
    public function pluginActivate()
    {
        wp_redirect(admin_url() . Data::settings_link, 302);
        wp_die();
    }

    /**
     * Removes current site config from global config on deactivation.
     *
     * @return void
     */
    public function pluginDeactivate()
    {
        global $wcMfpcConfig;

        $wcMfpcConfig->delete();
    }

    /**
     * admin panel, load plugin textdomain
     *
     * @return void
     */
    public function loadTextdomain()
    {
        load_plugin_textdomain('wc-mfpc', false, WC_MFPC_PLUGIN_DIR . 'languages/');
    }

    /**
     * Returns the Memcached connection. Initiates it if it does not exist.
     *
     * @return Memcached
     */
    public function getMemcached()
    {
        if (empty($this->memcached)) {

            global $wcMfpcConfig;

            $this->memcached = new Memcached($wcMfpcConfig->getConfig());

        }

        return $this->memcached;
    }

    /**
     * Handles clearing of posts and taxonomies.
     *
     * @todo Check if WcMfpc::clearPostCache() can be further simplified.
     *
     * @param int|string $postId  ID of the post to invalidate
     *
     * @return bool
     */
    public function clearPostCache($postId = null)
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {

            return false;
        }

        $memcached = $this->getMemcached();

        if (! $memcached->isAlive()) {

            return false;
        }

        $toClear   = [];

        if (empty($postId)) {

            #error_log('not clearing unidentified post', LOG_WARNING);

            return false;
        }

        $permalink = get_permalink($postId);

        if (empty($permalink)) {

            #error_log('Unable to determine path from Post Permalink, post ID: ' . $postId, LOG_WARNING);

            return false;
        }

        /*
         * It's possible that post/page is paginated with <!--nextpage--> as Wordpress doesn't seem to expose the
         * number of pages via API. So let's just count it.
         */
        $numberOfPages = 1 + (int) preg_match_all('/<!--nextpage-->/', get_post($postId)->post_content, $matches);
        $currentPageId = 0;

        do {

            $currentPageId         = 1 + (int) $currentPageId;
            $toClear[ $permalink ] = true;

        } while ($numberOfPages > 1 && $currentPageId <= $numberOfPages);

        return $memcached->clearLinks($toClear);
    }

}
