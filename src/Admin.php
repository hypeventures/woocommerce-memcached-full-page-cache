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
     * @var int
     */
    private $status = 0;

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
        add_action('admin_menu', [ &$this, 'addMenu' ], 101);
        add_action('admin_init', [ &$this, 'init' ]);
        add_action('admin_enqueue_scripts', [ &$this, 'enqueAdminCss' ]);
        add_action('admin_bar_init', [ &$this, 'setAdminNoCacheCookie' ]);

        /*
         * In case of major issues => abort here and set no more action hooks.
         */
        if (! $this->validateMandatorySettings()) {

            return;
        }

        /*
         * Add hooks necessary for the "Cache control" box.
         */
        add_action('add_meta_boxes', [ &$this, 'addCacheControlMetaBox' ], 2);
        add_action('product_cat_edit_form_fields', [ &$this, 'showCategoryBox' ]);
        add_action('wp_ajax_' . Data::cache_control_action, [ &$this, 'processCacheControlAjax' ]);

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
     * Sets the no-cache cookie for admins, if not set earlier.
     *
     * @return void
     */
    public function setAdminNoCacheCookie()
    {
        if (empty($_COOKIE[ 'wc-mfpc-nocache' ])) {

            setcookie('wc-mfpc-nocache', 1, time() + 86400);

        }
    }

    /**
     * Validates "siteurl" value and status of "WP_CACHE". Triggers admin notices in case of an issue.
     *
     * @return bool
     */
    private function validateMandatorySettings()
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

                $result = $wcMfpc->clearMemcached($id);
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

        if (
            ! empty($_POST[ 'action' ])
            && $_POST[ 'action' ] === Data::cache_control_action
            && ! empty($_POST[ 'nonce' ])
            && wp_verify_nonce($_POST[ 'nonce' ], Data::cache_control_action)
            && isset($_POST[ 'permalink' ])
        ) {

            $permalink = esc_url($_POST[ 'permalink' ]);
            $result    = $wcMfpc->getMemcached()
                                ->clearLinks([ $permalink => true, ]);

        } else {

            wp_die(json_encode('ERROR: Bad request!'), '', [ 'response' => 400 ]);

        }

        if (empty($result)) {

            wp_die(json_encode('ERROR: Cached object not found!'), '', [ 'response' => 404 ]);

        }

        wp_die(json_encode('SUCCESS! Cache for this item was cleared.'));
    }

    /**
     * Adding the submenu page.
     *
     * @return void
     */
    public function addMenu()
    {
        $view = new AdminView();

        add_submenu_page(
            'woocommerce',
            Data::plugin_name . ' options',
            'Full Page Cache',
            Data::capability,
            Data::plugin_settings_page,
            [ &$view, 'render' ]
        );
    }

    /**
     * @return void
     */
    public function enqueAdminCss()
    {
        wp_register_style(Data::admin_css_handle, Data::admin_css_url, [], false, 'all');
        wp_enqueue_style(Data::admin_css_handle);
    }

    /**
     * admin init called by WordPress add_action, needs to be public
     */
    public function init()
    {
        global $wcMfpc, $wcMfpcConfig;

        /*
         * save parameter updates, if there are any
         */
        if (isset($_POST[ Data::button_save ]) && check_admin_referer('wc-mfpc')) {

            $this->saveConfig();
            $this->deployAdvancedCache();
            $this->status = 1;
            header("Location: " . Data::settings_link . Data::slug_save);

        }

        /*
         * delete parameters if requested
         */
        if (isset($_POST[ Data::button_delete ]) && check_admin_referer('wc-mfpc')) {

            $wcMfpcConfig->delete();
            $this->deployAdvancedCache();
            $this->status = 2;
            header("Location: " . Data::settings_link . Data::slug_delete);

        }

        /*
         * save parameter updates, if there are any
         */
        if (isset($_POST[ Data::button_flush ]) && check_admin_referer('wc-mfpc')) {

            /* flush memcached */
            $wcMfpc->getMemcached()
                   ->flush();
            $this->status = 3;
            header("Location: " . Data::settings_link . Data::slug_flush);

        }
    }

    /**
     * used on update and to save current options to database
     *
     * @param boolean $activating [optional] true on activation hook
     */
    protected function saveConfig($activating = false)
    {
        global $wcMfpcConfig;

        /* only try to update defaults if it's not activation hook, $_POST is not empty and the post is ours */
        if (! $activating && ! empty ($_POST) && isset($_POST[ Data::button_save ])) {

            /* we'll only update those that exist in the defaults array */
            $options = Config::getDefaultConfig();

            foreach ($options as $key => $default) {

                /* $_POST element is available */
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

            /* update the options entity */
            $wcMfpcConfig->setConfig($options);
            $wcMfpcConfig->setNocacheWoocommerceUrl();
        }

        /* flush the cache when new options are saved, not needed on activation */
        if (! $activating) {

            global $wcMfpc;

            $wcMfpc->getMemcached()
                   ->flush();

        }

        $wcMfpcConfig->save();
    }

    /**
     * Generates and writes the "advanced-cache.php"
     *
     * @return bool
     */
    private function deployAdvancedCache()
    {
        global $wcMfpcConfig;

        if (! touch(Data::acache)) {

            error_log('Generating advanced-cache.php failed: ' . Data::acache . ' is not writable');

            return false;
        }

        /* if no active site left no need for advanced cache :( */
        if (empty($wcMfpcConfig->getGlobal())) {

            error_log('Generating advanced-cache.php failed: Global config is empty');
            unlink(WP_CONTENT_DIR . '/advanced-cache.php');

            return false;
        }

        /* add the required includes and generate the needed code */
        $string[] = '<?php';
        $string[] = 'global ' . Data::global_config_var . ';';
        $string[] = Data::global_config_var . ' = ' . var_export($wcMfpcConfig->getGlobal(), true) . ';';
        $string[] = "include_once ('" . Data::acache_worker . "');";

        return file_put_contents(Data::acache, join("\n", $string));
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
        $settings_link = '<a href="' . Data::settings_link . '">Settings</a>';
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Returns the status level indicator of the Memcached connection.
     *
     * @return int $status
     */
    public function getStatus()
    {
        return $this->status;
    }

}
