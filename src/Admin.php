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
 * Class Admin
 *
 * @package InvincibleBrands\WcMfpc\Admin
 */
class Admin
{

    /**
     * Initializes the Hooks necessary for the admin settings pages.
     *
     * @return void
     */
    public function setHooks()
    {
        if (is_multisite()) {

            add_filter("network_admin_plugin_action_links_" . WC_MFPC_PLUGIN_FILE, [ &$this, 'addSettingsLink' ]);

        }

        add_filter("plugin_action_links_" . WC_MFPC_PLUGIN_FILE, [ &$this, 'addSettingsLink' ]);
        add_action('admin_post_' . Data::buttonSave, [ &$this, 'processSave' ]);
        add_action('admin_post_' . Data::buttonFlush, [ &$this, 'processFlush' ]);
        add_action('admin_post_' . Data::buttonReset, [ &$this, 'processReset' ]);
        add_action('admin_enqueue_scripts', [ &$this, 'enqueAdminCss' ]);

        /*
         * In case of major issues => abort here and set no more action hooks.
         */
        if (! $this->validateEnvironment()) {

            return;
        }

        /*
         * Add hooks necessary for the "Cache control" box.
         */
        add_action('add_meta_boxes', [ &$this, 'addCacheControlMetaBox' ], 2);
        add_action('product_cat_edit_form_fields', [ &$this, 'showCategoryBox' ]);
        add_action('wp_ajax_' . Data::cacheControlClearAction, [ &$this, 'processCacheControlAjax' ]);
        add_action('wp_ajax_' . Data::cacheControlRefreshAction, [ &$this, 'processCacheControlAjax' ]);

        /*
         * Add hooks necessary for Bulk deletion of cache entries.
         */
        add_filter('bulk_actions-edit-product', [ &$this, 'addBulkAction' ]);
        add_filter('bulk_actions-edit-post', [ &$this, 'addBulkAction' ]);
        add_filter('bulk_actions-edit-page', [ &$this, 'addBulkAction' ]);
        add_filter('bulk_actions-edit-product_cat', [ &$this, 'addBulkAction' ]);
        add_filter('handle_bulk_actions-edit-product', [ &$this, 'handleBulkAction' ], 10, 3);
        add_filter('handle_bulk_actions-edit-post', [ &$this, 'handleBulkAction' ], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [ &$this, 'handleBulkAction' ], 10, 3);
        add_filter('handle_bulk_actions-edit-product_cat', [ &$this, 'handleBulkAction' ], 10, 3);
    }

    /**
     * Verifies the validity of a request if action string provided.
     *
     * @param string $action
     *
     * @return bool
     */
    protected function validateRequest($action = '')
    {
        return ! empty($_POST[ '_wpnonce' ])
               && wp_verify_nonce($_POST[ '_wpnonce' ], $action)
               && ! empty($_POST[ '_wp_http_referer' ])
               && check_admin_referer($action);
    }

    /**
     * Processes Admin settings page SAVE action.
     *
     * @return void
     */
    public function processSave()
    {
        $slug = '';

        if ($this->validateRequest(Data::buttonSave)) {

            $this->saveConfig();
            $this->deployAdvancedCache();
            $slug = Data::slugSave;

        }

        wp_redirect(Data::settingsLink . $slug);
        exit;
    }

    /**
     * Processes Admin settings page SAVE action.
     *
     * @return void
     */
    public function processFlush()
    {
        $slug = '';

        if ($this->validateRequest(Data::buttonFlush)) {

            global $wcMfpc;

            $wcMfpc->getMemcached()
                   ->flush();
            $slug = Data::slugFlush;

        }

        wp_redirect(Data::settingsLink . $slug);
        exit;
    }

    /**
     * Processes Admin settings page SAVE action.
     *
     * @return void
     */
    public function processReset()
    {
        $slug = '';

        if ($this->validateRequest(Data::buttonReset)) {

            global $wcMfpcConfig;

            $wcMfpcConfig->delete();
            $this->deployAdvancedCache();
            $slug = Data::slugReset;

        }

        wp_redirect(Data::settingsLink . $slug);
        exit;
    }

    /**
     * Validates "siteurl" value and status of "WP_CACHE". Triggers admin notices in case of an issue.
     *
     * @return bool
     */
    private function validateEnvironment()
    {
        $valid  = true;
        $domain = parse_url(get_option('siteurl'), PHP_URL_HOST);

        /*
         * Check if global_config_key equals the actual domain.
         */
        if (Config::getGlobalConfigKey() !== $domain) {

            Alert::alert(sprintf(
                'Domain mismatch: the site domain configuration (%s) does not match the HTTP_HOST (%s) '
                . 'variable in PHP. Please fix the incorrect one, otherwise the plugin may not work as expected.',
                $domain, Config::getGlobalConfigKey()
            ), LOG_WARNING, true);

            $valid = false;

        }

        /*
         * Check WP_CACHE and add a warning if it's disabled.
         */
        if (! defined('WP_CACHE') || empty(WP_CACHE)) {

            Alert::alert(
                '(!) WP_CACHE is disabled. Woocommerce-Memcached-Full-Page-Cache does not work without.<br>' .
                'Please add <i>define(\'WP_CACHE\', true);</i> to the beginning of wp-config.php file to enable caching.',
                LOG_WARNING, true
            );

            $valid = false;
        }

        if (! class_exists('Memcached')) {

            Alert::alert(
                'WARNING: PHP Memcached expansion is missing! WooCommerce Memcached Full Page Cache does not work.',
                LOG_WARNING, true
            );

        }

        return $valid;
    }

    /**
     * Adds "Clear cache" action to the bulk edit dropdowns.
     *
     * @param array $actions
     *
     * @return array
     */
    public function addBulkAction($actions = [])
    {
        if (get_current_screen()->id !== 'edit-product_cat') {

            $actions[ 'clearCache' ] = 'Clear cache';

        } else {

            $actions[ 'clearCategoryCache' ] = 'Clear cache';

        }

        if (isset($_GET[ 'cache_cleared' ]) && ! empty($_GET[ 'processed' ])) {

            Alert::alert('Cache cleared for: <b>' . urldecode($_GET[ 'processed' ]) . '</b>');

        }

        return $actions;
    }

    /**
     * Processes the Bulk Clear Cache actions.
     *
     * @param array  $redirectTo
     * @param string $action
     * @param array  $ids
     *
     * @return array|string
     */
    public function handleBulkAction($redirectTo = [], $action = '', $ids = [])
    {
        if ($action !== 'clearCache' && $action !== 'clearCategoryCache') {

            return $redirectTo;
        }

        global $wcMfpc;

        $processed = [];

        foreach ($ids as $id) {

            if ($action === 'clearCache') {

                $result = $wcMfpc->clearPostCache($id);
                $item   = $id;

            } else {

                $term      = get_term($id);
                $item      = $term->name;
                $permalink = get_category_link($term->term_taxonomy_id);
                $result    = $wcMfpc->getMemcached()
                                    ->clearLinks([ $permalink => true, ]);

            }

            if ($result) {

                $processed[] = $item;

            }

        }

        $redirectTo = add_query_arg([
            'cache_cleared' => 1,
            'processed'     => implode(',', $processed),
        ], $redirectTo );

        return $redirectTo;
    }

    /**ł
     * Adds the "Cache control" meta box to "Post", "Page" & "Product" edit screens.
     *
     * @return void
     */
    public function addCacheControlMetaBox()
    {
        $screens = [ 'post', 'page', 'product' ];

        foreach ($screens as $screen) {

            add_meta_box(
                'wc-mfpc-metabox-' . $screen,
                'Cache control',
                [ Admin::class, 'showMetaBox' ],
                $screen,
                'side',
                'high'
            );

        }
    }

    /**
     * Shows the "Cache control" box on "Category" edit screens.
     *
     * @param null|\WP_Term $term
     */
    public function showCategoryBox($term = null)
    {
        $permalink  = get_category_link($term->term_taxonomy_id);
        $type       = 'Category';
        $identifier = $term->name;

        self::showCacheControl($permalink, $type, $identifier);
    }

    /**
     * Shows the "Cache control" meta box on "Post", "Page" & "Product" edit screens.
     *
     * @param null|\WP_Post $post
     *
     * @return void
     */
    public static function showMetaBox($post = null)
    {
        $permalink  = get_permalink($post);
        $type       = ucfirst($post->post_type);
        $identifier = $post->ID;

        self::showCacheControl($permalink, $type, $identifier);
    }

    /**
     * Actually triggers the "Cache Control" box view to be rendered.
     *
     * @param string $permalink
     * @param string $type
     * @param string $identifier
     *
     * @return void
     */
    public static function showCacheControl($permalink = '', $type = '', $identifier = '')
    {
        global $wcMfpc, $wcMfpcConfig;

        $statusMessage = '<b class="wc-mfpc-error-msg">Not cached</b>';
        $display       = 'none';
        $key           = $wcMfpcConfig->prefix_data . $permalink;

        if (! empty($wcMfpc->getMemcached()->get($key))) {

            $statusMessage = '<b class="wc-mfpc-ok-msg">Cached</b>';
            $display       = 'block';

        }

        AdminView::renderCacheControl($statusMessage, $display, $type, $identifier, $permalink);
    }

    /**
     * Processes the "Cache control" AJAX request. Clears cache of given postId if possible.
     *
     * @return void
     */
    public function processCacheControlAjax()
    {
        global $wcMfpc;

        header('Content-Type: application/json');

        if (empty($_POST[ 'action' ]) || empty($_POST[ 'nonce' ]) || ! isset($_POST[ 'permalink' ])){

            wp_die(json_encode('ERROR: Bad request!'), '', [ 'response' => 400 ]);

        }

        $valid     = false;
        $permalink = esc_url($_POST[ 'permalink' ]);

        if (
            $_POST[ 'action' ] === Data::cacheControlClearAction
            && wp_verify_nonce($_POST[ 'nonce' ], Data::cacheControlClearAction)
        ) {

            $valid  = true;
            $result = $wcMfpc->getMemcached()
                                ->clearLinks([ $permalink => true, ]);

        } elseif (
                  $_POST[ 'action' ] === Data::cacheControlRefreshAction
                  && wp_verify_nonce($_POST[ 'nonce' ], Data::cacheControlRefreshAction)
        ) {

            $valid  = true;
            $key    = $wcMfpc->getMemcached()
                             ->buildKey($permalink);
            $result = $wcMfpc->getMemcached()
                             ->get($key);

            wp_die(json_encode((bool) $result));

        }

        if (! $valid) {

            wp_die(json_encode('ERROR: Bad request!'), '', [ 'response' => 400 ]);

        }

        if (empty($result)) {

            wp_die(json_encode('ERROR: Cached object not found!'), '', [ 'response' => 404 ]);

        }

        wp_die(json_encode('SUCCESS! Cache for this item was cleared.'));
    }

    /**
     * Enqueues admin.css to be added to the footer.
     *
     * @return void
     */
    public function enqueAdminCss()
    {
        wp_register_style(Data::adminCssHandle, Data::adminCssUrl, [], false, 'all');
        wp_enqueue_style(Data::adminCssHandle);
    }

    /**
     * Processes POST vars to save the new Config.
     *
     * @return void
     */
    protected function saveConfig()
    {
        global $wcMfpc, $wcMfpcConfig;

        $options = Config::getDefaultConfig();

        foreach ($options as $key => $default) {

            if (! empty($_POST[ $key ])) {

                $update = trim($_POST[ $key ]);

                /* get rid of slashes in strings, just in case */
                if (is_string($update)) {

                    $update = stripslashes($update);

                }

                $options[ $key ] = $update;

            /*
             * empty $_POST element: when HTML form posted, empty checkboxes a 0 input
             * values will not be part of the $_POST array, thus we need to check
             * if this is the situation by checking the types of the elements,
             * since a missing value means update from an integer to 0
             */
            } elseif (is_bool($default) || is_int($default)) {

                $options[ $key ] = 0;

            } elseif (is_array($default)) {

                $options[ $key ] = [];

            }

        }

        $wcMfpcConfig->setConfig($options)
                     ->setNocacheWoocommerceUrl();
        $wcMfpcConfig->save();
        $wcMfpc->getMemcached()
               ->flush();
    }

    /**
     * Generates and writes the "advanced-cache.php"
     *
     * @return bool
     */
    private function deployAdvancedCache()
    {
        global $wcMfpcConfig;

        if (! touch(Data::advancedCache)) {

            error_log('Generating advanced-cache.php failed: ' . Data::advancedCache . ' is not writable');

            return false;
        }

        if (empty($wcMfpcConfig->getGlobal())) {

            error_log('Generating advanced-cache.php failed: Global config is empty');
            unlink(WP_CONTENT_DIR . '/advanced-cache.php');

            return false;
        }

        $globalConfig = $wcMfpcConfig->getGlobal();

        /**
         * Hook to customize the Global-Config array before it is written via var_export to the advanced-cache.php file.
         *
         * @param array $globalConfig  Array with the config to write into the advanced-cache.php file
         *
         * @return array $globalConfig
         */
        $config = (array) apply_filters('wc_mfpc_custom_advanced_cache_config', $globalConfig);

        $stringArray[] = '<?php';
        $stringArray[] = '/*';
        $stringArray[] = 'Plugin Name: WooCommerce Memcached Full Page Cache (Drop-In: advanced-cache.php)';
        $stringArray[] = 'Plugin URI: https://github.com/agaleski/woocommerce-memcached-full-page-cache/';
        $stringArray[] = 'Description: WooCommerce full page cache plugin based on Memcached.';
        $stringArray[] = 'Version: 0.1';
        $stringArray[] = 'Author: Achim Galeski <achim@invinciblebrands.com>';
        $stringArray[] = 'License: GPLv3';
        $stringArray[] = '*/';
        $stringArray[] = '';
        $stringArray[] = 'global ' . Data::globalConfigVar . ';';
        $stringArray[] = '';
        $stringArray[] = Data::globalConfigVar . ' = ' . var_export($config, true) . ';';
        $stringArray[] = '';
        $stringArray[] = "include_once ('" . Data::advancedCacheWorker . "');";
        $stringArray[] = '';

        return file_put_contents(Data::advancedCache, join("\n", $stringArray));
    }

    /**
     * Callback function to add settings link to plugins page
     *
     * @param array $links Current links to add ours to
     *
     * @return array
     */
    public function addSettingsLink($links)
    {
        $settingsLink = '<a href="' . Data::settingsLink . '">Settings</a>';
        array_unshift($links, $settingsLink);

        return $links;
    }

}
