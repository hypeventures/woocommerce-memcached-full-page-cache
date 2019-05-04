<?php

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

            add_action('comment_post', [ &$this, 'clearMemcached' ], 0);
            add_action('edit_comment', [ &$this, 'clearMemcached' ], 0);
            add_action('trashed_comment', [ &$this, 'clearMemcached' ], 0);
            add_action('pingback_post', [ &$this, 'clearMemcached' ], 0);
            add_action('trackback_post', [ &$this, 'clearMemcached' ], 0);
            add_action('wp_insert_comment', [ &$this, 'clearMemcached' ], 0);

        }

        /*
         * invalidation on some other occasions as well
         */
        add_action('switch_theme', [ &$this, 'clearMemcached' ], 0);
        add_action('deleted_post', [ &$this, 'clearMemcached' ], 0);

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
     * @todo This needs some serious diet.
     *
     * @see Config::$invalidation_method
     *
     * @param int|string $postId  ID of the post to invalidate
     *
     * @return bool
     */
    public function clearMemcached($postId = null)
    {
        $memcached = &$this->getMemcached();

        if (! $memcached->isAlive()) {

            return false;
        }

        if (empty($postId)) {

            #error_log('not clearing unidentified post', LOG_WARNING);

            return false;
        }

        global $wcMfpcConfig;

        /*
         * If invalidation method is set to full, flush cache.
         */
        if ($wcMfpcConfig->getInvalidationMethod() === 0) {

            #error_log('flushing cache');

            return $memcached->flush();
        }

        /* storage for entries to clear */
        $toClear = [];

        /* clear taxonomies if settings requires it */
        if ($wcMfpcConfig->getInvalidationMethod() == 2) {

            /* this will only clear the current blog's entries */
            $toClear = $this->getTaxonomyLinks($toClear);

        }

        /* need permalink functions */
        if (! function_exists('get_permalink')) {

            include_once(ABSPATH . 'wp-includes/link-template.php');

        }

        $permalink = get_permalink($postId);

        /* no path, don't do anything */
        if (empty($permalink) && $permalink != false) {

            error_log(sprintf('unable to determine path from Post Permalink, post ID: %s', $postId), LOG_WARNING);

            return false;
        }

        /*
         * It is possible that post/page is paginated with <!--nextpage-->
         * Wordpress doesn't seem to expose the number of pages via API.
         * So let's just count it.
         */
        $numberOfPages = 1 + (int) preg_match_all('/<!--nextpage-->/', get_post($postId)->post_content, $matches);
        $currentPageId = 0;

        do {

            $uriMap                    = $memcached::parseUriMap($permalink, $memcached->uriMap);
            $uriMap[ '$request_uri' ]  = $uriMap[ '$request_uri' ] . ($currentPageId ? $currentPageId . '/' : '');
            $clearCacheKey             = $memcached::mapUriMap($uriMap, $wcMfpcConfig->getKey());
            $currentPageId             = 1 + (int) $currentPageId;
            $toClear[ $clearCacheKey ] = true;

        } while ($numberOfPages > 1 && $currentPageId <= $numberOfPages);

        /**
         * Filter to enable customization of array $toClear.
         * Allows 3rd party Developers to change the array of keys which should be cleared afterwards.
         *
         * @param array      $toClear  Array of keys which should be cleared afterwards.
         * @param string|int $postId   Id of the post in question IF it is a post. Needs to be checked!
         * @param Memcached  $this     Instance of this Memcached class with active server connection.
         *
         * @return array $toClear
         */
        $toClear = (array) apply_filters('wc_mfpc_custom_to_clear', $toClear, $postId, $memcached);

        return $memcached->clear_keys($toClear);
    }

    /**
     * Collects all permalinks of all taxonomy terms used in invalidation
     *
     * @param array $links  Passed by reference array that has to be filled up with the links
     *
     * @return array $links
     */
    public function getTaxonomyLinks($links)
    {
        $taxonomies = get_taxonomies([
            'public' => true,
        ], 'objects');

        if (empty($taxonomies)) {

            return $links;
        }

        foreach ($taxonomies as $taxonomy) {

            $terms = get_terms([
                'taxonomy'     => $taxonomy->name,
                'hide_empty'   => true,
                'fields'       => 'all',
                'hierarchical' => false,
            ]);

            if (empty($terms)) {

                continue;

            }

            foreach ($terms as $term) {

                if (empty($term->count)) {

                    continue;

                }

                $link           = get_term_link($term->slug, $taxonomy->name);
                $links[ $link ] = true;

                /*
                 * Remove the taxonomy name from the link, lots of plugins remove this for SEO, it's better to
                 * include them than leave them out. In worst case, some 404 pages are cached as well
                 */
                $link           = str_replace('/' . $taxonomy->rewrite[ 'slug' ], '', $link);
                $links[ $link ] = true;

            }

        }

        return $links;
    }

}
