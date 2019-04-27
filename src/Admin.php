<?php

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
     * @var array
     */
    private $global_config = [];

    /**
     * @var int
     */
    private $status = 0;

    /**
     * @var bool
     */
    private $global_saved = false;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * Initializes the Hooks necessary for the admin settings pages.
     *
     * @return void
     */
    public function setHooks()
    {
        global $wcMfpcData;

        /*
         * register settings pages & register admin init, catches $_POST and adds submenu to admin menu
         */
        if ($wcMfpcData->network) {

            add_filter("network_admin_plugin_action_links_" . $wcMfpcData->plugin_file, [ &$this, 'plugin_settings_link' ]);
            add_action('network_admin_menu', [ &$this, 'addMenu' ]);

        } else {

            add_filter("plugin_action_links_" . $wcMfpcData->plugin_file, [ &$this, 'plugin_settings_link' ]);
            add_action('admin_menu', [ &$this, 'addMenu' ]);

        }

        add_action('admin_init', [ &$this, 'plugin_admin_init' ]);
        add_action('admin_enqueue_scripts', [ &$this, 'enqueue_admin_css_js' ]);

        /*
         * Check WP_CACHE and add a warning if it's disabled.
         */
        if (! defined('WP_CACHE') || empty(WP_CACHE)) {

            Alert::alert(
                '(!) WP_CACHE is disabled. Woocommerce-Memcached-Full-Page-Cache does not work without.<br>' .
                'Please add <i>define(\'WP_CACHE\', true);</i> to the beginning of wp-config.php file to enable caching.',
                LOG_WARNING, true
            );

        }
    }

    /**
     * Adding the submenu page.
     *
     * @return void
     */
    public function addMenu()
    {
        global $wcMfpcData;

        add_submenu_page(
            'woocommerce',
            Data::plugin_name . ' options',
            Data::plugin_name,
            Data::capability,
            Data::plugin_settings_page,
            [ &$this, 'plugin_admin_panel' ]
        );
    }

    /**
     * @return void
     */
    public function enqueue_admin_css_js()
    {
        global $wcMfpcData;

        wp_register_style(Data::admin_css_handle, $wcMfpcData->admin_css_url, [ 'dashicons' ], false, 'all');
        wp_enqueue_style(Data::admin_css_handle);
    }

    /**
     * init hook function runs before admin panel hook, themeing and options read
     */
    public function plugin_pre_init()
    {
        global $wcMfpcData;

        if (! isset($_SERVER[ 'HTTP_HOST' ])) {

            $_SERVER[ 'HTTP_HOST' ] = '127.0.0.1';

        }

        /* set global config key; here, because it's needed for migration */
        if ($wcMfpcData->network) {

            $wcMfpcData->global_config_key = 'network';

        } else {

            $sitedomain = parse_url(get_option('siteurl'), PHP_URL_HOST);

            if ($_SERVER[ 'HTTP_HOST' ] != $sitedomain) {

                $this->errors[ 'domain_mismatch' ] = sprintf(__("Domain mismatch: the site domain configuration (%s) does not match the HTTP_HOST (%s) variable in PHP. Please fix the incorrect one, otherwise the plugin may not work as expected.", 'wc-mfpc'), $sitedomain, $_SERVER[ 'HTTP_HOST' ]);

            }

            $wcMfpcData->global_config_key = $_SERVER[ 'HTTP_HOST' ];

        }
    }

    /**
     * admin init called by WordPress add_action, needs to be public
     */
    public function plugin_admin_init()
    {
        global $wcMfpc, $wcMfpcData, $wcMfpcConfig;

        $this->plugin_pre_init();
        $this->plugin_extend_options_read($wcMfpcConfig->getConfig());

        /* save parameter updates, if there are any */
        if (isset($_POST[ Data::button_save ]) && check_admin_referer('wc-mfpc')) {

            $this->plugin_options_save();
            $this->status = 1;
            header("Location: " . $wcMfpcData->settings_link . Data::slug_save);

        }

        /* delete parameters if requested */
        if (isset($_POST[ Data::button_delete ]) && check_admin_referer('wc-mfpc')) {

            self::plugin_options_delete();
            $this->status = 2;
            header("Location: " . $wcMfpcData->settings_link . $wcMfpcData->slug_delete);

        }

        /* save parameter updates, if there are any */
        if (isset($_POST[ Data::button_flush ]) && check_admin_referer('wc-mfpc')) {

            /* flush backend */
            $wcMfpc->backend->clear(false, true);
            $this->status = 3;
            header("Location: " . $wcMfpcData->settings_link . $wcMfpcData->slug_flush);

        }
    }

    /**
     * deletes saved options from database
     */
    public static function plugin_options_delete()
    {
        global $wcMfpcData;

        self::_delete_option(Data::plugin_constant, $wcMfpcData->network);
        delete_site_option(Data::global_option);
    }

    /**
     * clear option; will handle network wide or standalone site options
     *
     * @param      $optionID
     * @param bool $network
     */
    public static function _delete_option($optionID, $network = false)
    {
        if ($network) {
          
            delete_site_option($optionID);
            
        } else {
          
            delete_option($optionID);
            
        }
    }

    /**
     * used on update and to save current options to database
     *
     * @param boolean $activating [optional] true on activation hook
     */
    protected function plugin_options_save($activating = false)
    {
        global $wcMfpcData, $wcMfpcConfig;

        /* only try to update defaults if it's not activation hook, $_POST is not empty and the post is ours */
        if (! $activating && ! empty ($_POST) && isset($_POST[ Data::button_save ])) {

            /* we'll only update those that exist in the defaults array */
            $options = $wcMfpcData->defaults;

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
                } elseif (empty($_POST[ $key ]) && (is_bool($default) || is_int($default))) {

                    $options[ $key ] = 0;

                } elseif (empty($_POST[ $key ]) && is_array($default)) {

                    $options[ $key ] = [];

                }

            }

            /* update the options entity */
            $wcMfpcConfig->setConfig($options);

        }

        /* flush the cache when new options are saved, not needed on activation */
        if (! $activating) {

            global $wcMfpc;

            $wcMfpc->backend->clear(null, true);

        }

        /* create the to-be-included configuration for advanced-cache.php */
        $this->update_global_config();

        /* create advanced cache file, needed only once or on activation, because there could be lefover advanced-cache.php from different plugins */
        if (! $activating) {

            $this->deploy_advanced_cache();

        }

        /* save options to database */
        self::_update_option(Data::plugin_constant, $wcMfpcConfig->getConfig(), $wcMfpcData->network);
    }

    /**
     * option update; will handle network wide or standalone site options
     *
     * @param      $optionID
     * @param      $data
     * @param bool $network
     */
    public static function _update_option($optionID, $data, $network = false)
    {
        if ($network) {

            update_site_option($optionID, $data);

        } else {

            update_option($optionID, $data);

        }
    }

    /**
     * read option; will handle network wide or standalone site options
     *
     * @param      $optionID
     * @param bool $network
     *
     * @return mixed
     */
    public static function _get_option($optionID, $network = false)
    {
        if ($network) {

            $options = get_site_option($optionID);

        } else {

            $options = get_option($optionID);

        }

        return $options;
    }

    /**
     * read hook; needs to be implemented
     *
     * @param $options
     */
    public function plugin_extend_options_read($options)
    {
        global $wcMfpcData;

        $this->global_config = get_site_option(Data::global_option);

        if (! empty ($this->global_config[ $wcMfpcData->global_config_key ])) {

            $this->global_saved = true;

        }

        $this->global_config[ $wcMfpcData->global_config_key ] = $options;
    }

    /**
     * function to update global configuration
     *
     * @param boolean $remove_site Bool to remove or add current config to global
     */
    public function update_global_config($remove_site = false)
    {
        global $wcMfpcConfig, $wcMfpcData;

        /* remove or add current config to global config */
        if ($remove_site) {

            unset ($this->global_config[ $wcMfpcData->global_config_key ]);

        } else {

            $this->global_config[ $wcMfpcData->global_config_key ] = $wcMfpcConfig->getConfig();

        }

        /* deploy advanced-cache.php */
        $this->deploy_advanced_cache();
        /* save options to database */
        update_site_option(Data::global_option, $this->global_config);
    }

    /**
     * advanced-cache.php creator function
     */
    private function deploy_advanced_cache()
    {
        global $wcMfpcData;

        if (! touch($wcMfpcData->acache)) {

            error_log('Generating advanced-cache.php failed: ' . $wcMfpcData->acache . ' is not writable');

            return false;
        }

        /* if no active site left no need for advanced cache :( */
        if (empty ($this->global_config)) {

            error_log('Generating advanced-cache.php failed: Global config is empty');

            return false;
        }

        /* add the required includes and generate the needed code */
        $string[] = "<?php";
        $string[] = Data::global_config_var . " = " . var_export($this->global_config, true) . ';';
        $string[] = "include_once ('" . $wcMfpcData->acache_worker . "');";

        /* write the file and start caching from this point */

        return file_put_contents($wcMfpcData->acache, join("\n", $string));
    }

    /**
     * Select options field processor
     *
     * @param array $elements  Array to build <option> values of
     * @param mixed $current   The current active element
     * @param bool  $valid
     * @param bool  $print     Is true, the options will be printed, otherwise the string will be returned
     *
     * @return mixed $opt      Prints or returns the options string
     */
    protected function print_select_options($elements, $current, $valid = false, $print = true)
    {
        $opt = '';

        foreach ($elements as $value => $name) {

            $opt .= '<option value="' . $value . '" ';
            $opt .= selected($value, $current);

            // ugly tree level valid check to prevent array warning messages
            if (is_array($valid) && isset ($valid [ $value ]) && $valid [ $value ] == false) {

                $opt .= ' disabled="disabled"';

            }

            $opt .= '>';
            $opt .= $name;
            $opt .= "</option>\n";
        }

        if ($print) {

            echo $opt;

        } else {

            return $opt;
        }
    }

    /**
     * callback function to add settings link to plugins page
     *
     * @param array $links Current links to add ours to
     *
     * @return array
     */
    public function plugin_settings_link($links)
    {
        global $wcMfpcData;

        $settings_link = '<a href="' . $wcMfpcData->settings_link . '">' . __('Settings', 'wc-mfpc') . '</a>';
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

    /**
     * Returns the indicator if the global save was successful.
     *
     * @return bool $status
     */
    public function isGlobalSaved()
    {
        return $this->global_saved;
    }

}
