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
    public $backend = null;

    /**
     * Initializes the plugin, sets necessary hooks and establishes the connection to Memcached.
     *
     * @return void
     */
    public function init()
    {
        global $wcMfpcConfig, $wcMfpcData;

        register_activation_hook($wcMfpcData->plugin_file, [ &$this, 'pluginActivate' ]);
        register_deactivation_hook($wcMfpcData->plugin_file, [ &$this, 'pluginDeactivate' ]);

        $wcMfpcConfig->read();

        if (is_admin()) {

            global $wcMfpcAdmin;

            $wcMfpcAdmin = new Admin();
            $wcMfpcAdmin->setHooks();

            add_action('plugins_loaded', [ &$this, 'loadTextdomain' ]);

        }

        /*
         * initiate backend
         */
        $this->backend = new Memcached($wcMfpcConfig->getConfig());

        /*
         * cache invalidation hooks
         */
        add_action('transition_post_status', [ &$this->backend, 'clear_ng' ], 10, 3);

        /*
         * comments invalidation hooks
         */
        if (! empty($wcMfpcConfig->isCommentsInvalidate())) {

            add_action('comment_post', [ &$this->backend, 'clear' ], 0);
            add_action('edit_comment', [ &$this->backend, 'clear' ], 0);
            add_action('trashed_comment', [ &$this->backend, 'clear' ], 0);
            add_action('pingback_post', [ &$this->backend, 'clear' ], 0);
            add_action('trackback_post', [ &$this->backend, 'clear' ], 0);
            add_action('wp_insert_comment', [ &$this->backend, 'clear' ], 0);

        }

        /*
         * invalidation on some other occasions as well
         */
        add_action('switch_theme', [ &$this->backend, 'clear' ], 0);
        add_action('deleted_post', [ &$this->backend, 'clear' ], 0);
        add_action('edit_post', [ &$this->backend, 'clear' ], 0);

        /*
         * add filter for catching canonical redirects
         */
        if (WP_CACHE) {

            add_filter('redirect_canonical', 'wc_mfpc_redirect_callback', 10, 2);

        }
    }

    /**
     * Activation hook function.
     * Left empty to avoid issues with detecting WordPress Network (Multisite).
     *
     * @return void
     */
    public function pluginActivate() {}

    /**
     * Removes current site config from global config on deactivation.
     *
     * @return void
     */
    public function pluginDeactivate()
    {
        $admin = new Admin();
        $admin->update_global_config(true);
    }

    /**
     * admin panel, load plugin textdomain
     *
     * @return void
     */
    public function loadTextdomain()
    {
        load_plugin_textdomain('wc-mfpc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

}
