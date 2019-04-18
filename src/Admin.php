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
     * @var string
     */
    private $plugin_file = '';

    /**
     * @var bool
     */
    private $network = false;

    /**
     * @var string
     */
    private $settings_slug = '';

    /**
     * @var string
     */
    private $settings_link = '';

    /**
     * @var string
     */
    private $plugin_settings_page = '';

    /**
     * @var string
     */
    private $precache_message = '';

    /**
     * @var bool
     */
    private $scheduled = false;

    /**
     * Admin constructor.
     */
    public function __construct()
    {
        /* we need network wide plugin check functions */
        if (! function_exists('is_plugin_active_for_network')) {

            require_once(ABSPATH . 'wp-admin/includes/plugin.php');

        }

        /* check if plugin is network-activated */
        if (@is_plugin_active_for_network($this->plugin_file)) {

            $this->network       = true;
            $this->settings_slug = 'settings.php';

        } else {

            $this->settings_slug = 'options-general.php';

        }

        /* set the settings page link string */
        $this->settings_link = $this->settings_slug . '?page=' . Data::plugin_settings_page;
    }

    /**
     * Initializes the Hooks necessary for the admin settings pages.
     *
     * @return void
     */
    public function setHooks()
    {
        /*
         * register settings pages & register admin init, catches $_POST and adds submenu to admin menu
         */
        if ($this->network) {

            add_filter("network_admin_plugin_action_links_" . $this->plugin_file, [ &$this, 'plugin_settings_link' ]);
            add_action('network_admin_menu', [ &$this, 'plugin_admin_init' ]);

        } else {

            add_filter("plugin_action_links_" . $this->plugin_file, [ &$this, 'plugin_settings_link' ]);
            add_action('admin_menu', [ &$this, 'plugin_admin_init' ]);

        }

        add_action('admin_enqueue_scripts', [ &$this, 'enqueue_admin_css_js' ]);
    }

    /**
     * @return void
     */
    public function enqueue_admin_css_js()
    {
        /* jquery ui tabs is provided by WordPress */
        wp_enqueue_script("jquery-ui-tabs");
        wp_enqueue_script("jquery-ui-slider");
        /* additional admin styling */
        wp_register_style($this->admin_css_handle, $this->admin_css_url, [ 'dashicons' ], false, 'all');
        wp_enqueue_style($this->admin_css_handle);
    }

    /**
     * admin init called by WordPress add_action, needs to be public
     */
    public function plugin_admin_init()
    {
        /* save parameter updates, if there are any */
        if (isset($_POST[ Data::button_save ]) && check_admin_referer('wc-mfpc')) {

            $this->plugin_options_save();
            $this->status = 1;
            header("Location: " . $this->settings_link . Data::slug_save);

        }

        /* delete parameters if requested */
        if (isset($_POST[ Data::button_delete ]) && check_admin_referer('wc-mfpc')) {

            self::plugin_options_delete();
            $this->status = 2;
            header("Location: " . $this->settings_link . Data::slug_delete);

        }

        /* load additional moves */
        $this->plugin_extend_admin_init();

        error_log('adding submenu');

        error_log(print_r([
            'data' => [
                '$this->settings_slug' => $this->settings_slug,
                'Data::plugin_name . \' options\'' => Data::plugin_name . ' options',
                'Data::plugin_name' => Data::plugin_name,
                'Data::capability' => Data::capability,
                'Data::plugin_settings_page' => Data::plugin_settings_page,
            ],
        ], true));

        /* add submenu to settings pages */
        error_log(add_submenu_page(
            $this->settings_slug,
            Data::plugin_name . ' options',
            Data::plugin_name,
            Data::capability,
            Data::plugin_settings_page,
            [ &$this, 'plugin_admin_panel' ]
        ));
    }

    /**
     * extending admin init
     */
    public function plugin_extend_admin_init()
    {
        /* save parameter updates, if there are any */
        if (isset($_POST[ Data::button_flush ]) && check_admin_referer('wc-mfpc')) {

            /* remove precache log entry */
            self::_delete_option(Data::precache_log);
            /* remove precache timestamp entry */
            self::_delete_option(Data::precache_timestamp);
            /* remove precache logfile */

            if (@file_exists($this->precache_logfile)) {

                unlink($this->precache_logfile);

            }

            /* remove precache PHP worker */
            if (@file_exists($this->precache_phpfile)) {

                unlink($this->precache_phpfile);

            }

            /* flush backend */
            $this->backend->clear(false, true);
            $this->status = 3;
            header("Location: " . $this->settings_link . self::slug_flush);

        }

        /* save parameter updates, if there are any */
        if (isset($_POST[ Data::button_precache ]) && check_admin_referer('wc-mfpc')) {

            /* is no shell function is possible, fail */
            if ($this->shell_function == false) {

                $this->status = 5;
                header("Location: " . $this->settings_link . Data::slug_precache_disabled);

            } else {

                $this->precache_message = $this->precache_coldrun();
                $this->status           = 4;
                header("Location: " . $this->settings_link . Data::slug_precache);

            }

        }
    }

    /**
     * deletes saved options from database
     */
    public static function plugin_options_delete()
    {
        self::_delete_option(Data::plugin_constant, self::$network);
        /* additional moves */
        self::plugin_extend_options_delete();
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
     * options delete hook; needs to be implemented
     */
    public static function plugin_extend_options_delete()
    {
        delete_site_option(Data::global_option);
    }

    /**
     * used on update and to save current options to database
     *
     * @param boolean $activating [optional] true on activation hook
     */
    protected function plugin_options_save($activating = false)
    {
        /* only try to update defaults if it's not activation hook, $_POST is not empty and the post
           is ours */
        if (! $activating && ! empty ($_POST) && isset($_POST[ Data::button_save ])) {
            /* we'll only update those that exist in the defaults array */
            $options = $this->defaults;
            foreach ($options as $key => $default) {
                /* $_POST element is available */
                if (! empty($_POST[ $key ])) {
                    $update = $_POST[ $key ];
                    /* get rid of slashes in strings, just in case */
                    if (is_string($update)) {
                        $update = stripslashes($update);
                    }
                    $options[ $key ] = $update;
                } /* empty $_POST element: when HTML form posted, empty checkboxes a 0 input
                     values will not be part of the $_POST array, thus we need to check
                     if this is the situation by checking the types of the elements,
                     since a missing value means update from an integer to 0
                   */
                elseif (empty($_POST[ $key ]) && (is_bool($default) || is_int($default))) {
                    $options[ $key ] = 0;
                } elseif (empty($_POST[ $key ]) && is_array($default)) {
                    $options[ $key ] = [];
                }
            }
            /* update the options array */
            $this->options = $options;
        }
        /* call hook function for additional moves before saving the values */
        $this->plugin_extend_options_save($activating);
        /* save options to database */
        static::_update_option(Data::plugin_constant, $this->options, $this->network);
    }

    /**
     * extending options_save
     */
    public function plugin_extend_options_save($activating)
    {
        /* schedule cron if posted */
        $schedule = wp_get_schedule(Data::precache_id);

        if ($this->options[ 'precache_schedule' ] != 'null') {

            /* clear all other schedules before adding a new in order to replace */
            wp_clear_scheduled_hook(Data::precache_id);
            $this->scheduled = wp_schedule_event(time(), $this->options[ 'precache_schedule' ], self::precache_id);

        } elseif ((! isset($this->options[ 'precache_schedule' ]) || $this->options[ 'precache_schedule' ] == 'null') && ! empty($schedule)) {

            wp_clear_scheduled_hook(Data::precache_id);

        }

        /* flush the cache when new options are saved, not needed on activation */
        if (! $activating) {

            $this->backend->clear(null, true);

        }

        /* create the to-be-included configuration for advanced-cache.php */
        $this->update_global_config();

        /* create advanced cache file, needed only once or on activation, because there could be lefover advanced-cache.php from different plugins */
        if (! $activating) {

            $this->deploy_advanced_cache();

        }
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
     * reads options stored in database and reads merges them with default values
     */
    public function plugin_options_read()
    {
        global $wcMfpcData;

        $options = self::_get_option(Data::plugin_constant, $this->network);

        /* map missing values from default */
        foreach ($wcMfpcData->defaults as $key => $default) {

            if (! @array_key_exists($key, $options)) {

                $options[ $key ] = $default;

            }

        }

        /* removed unused keys, rare, but possible */
        foreach (@array_keys($options) as $key) {

            if (! @array_key_exists($key, $wcMfpcData->defaults)) {

                unset ($options[ $key ]);

            }

        }

        /* any additional read hook */
        $this->plugin_extend_options_read($options);
        $this->options = $options;
    }

    /**
     * read option; will handle network wide or standalone site options
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
    public function plugin_extend_options_read(&$options)
    {
        /* read the global options, network compatibility */
        $this->global_config = get_site_option(Data::global_option);

        /* check if current site present in global config */
        if (! empty ($this->global_config[ $this->global_config_key ])) {

            $this->global_saved = true;

        }

        $this->global_config[ $this->global_config_key ] = $options;
    }

    /**
     * function to update global configuration
     *
     * @param boolean $remove_site Bool to remove or add current config to global
     */
    private function update_global_config($remove_site = false)
    {
        /* remove or add current config to global config */
        if ($remove_site) {

            unset ($this->global_config[ $this->global_config_key ]);

        } else {

            $this->global_config[ $this->global_config_key ] = $this->options;

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
        if (! touch($this->acache)) {

            error_log('Generating advanced-cache.php failed: ' . $this->acache . ' is not writable');

            return false;
        }

        /* if no active site left no need for advanced cache :( */
        if (empty ($this->global_config)) {

            error_log('Generating advanced-cache.php failed: Global config is empty');

            return false;
        }

        /* add the required includes and generate the needed code */
        $string[] = "<?php";
        $string[] = Data::global_config_var . ' = ' . var_export($this->global_config, true) . ';';
        $string[] = "include_once ('" . $this->acache_worker . "');";

        /* write the file and start caching from this point */

        return file_put_contents($this->acache, join("\n", $string));
    }

    /**
     * @return mixed
     */
    private function plugin_admin_panel_get_tabs()
    {
        $default_tabs = [
            'type'       => __('Cache type', 'wc-mfpc'),
            'debug'      => __('Debug & in-depth', 'wc-mfpc'),
            'exceptions' => __('Cache exceptions', 'wc-mfpc'),
            'servers'    => __('Backend settings', 'wc-mfpc'),
            'precache'   => __('Precache & precache log', 'wc-mfpc'),
        ];

        return apply_filters('wc_mfpc_admin_panel_tabs', $default_tabs);
    }

    /**
     * admin panel, the admin page displayed for plugin settings
     */
    public function plugin_admin_panel()
    {
        error_log('reached admin panel method');
        /*
         * security, if somehow we're running without WordPress security functions
         */
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {

            die();

        }

        /* woo_commenrce page url */
        if (class_exists('WooCommerce')) {

            $page_wc_checkout                           = str_replace(home_url(), '', wc_get_page_permalink('checkout'));
            $page_wc_myaccount                          = str_replace(home_url(), '', wc_get_page_permalink('myaccount'));
            $page_wc_cart                               = str_replace(home_url(), '', wc_get_page_permalink('cart'));
            $wcapi                                      = '^/wc-api|^/\?wc-api=';
            $this->options[ 'nocache_woocommerce_url' ] = '^' . $page_wc_checkout . '|^' . $page_wc_myaccount . '|^' . $page_wc_cart . '|' . $wcapi;

        } else {

            $this->options[ 'nocache_woocommerce_url' ] = '';

        }
        ?>

        <div class="wrap">

            <script>
              jQuery(document).ready(function ($) {
                jQuery("#<?php echo Data::plugin_constant ?>-settings").tabs();
                jQuery("#<?php echo Data::plugin_constant ?>-commands").tabs();
              });
            </script>

            <?php
            /*
             * if options were saved, display saved message
             */
            if (isset($_GET[ Data::key_save ]) && $_GET[ Data::key_save ] == 'true' || $this->status == 1) { ?>

                <div class='updated settings-error'><p><strong><?php _e('Settings saved.', 'wc-mfpc') ?></strong></p></div>

            <?php }

            /*
             * if options were delete, display delete message
             */
            if (isset($_GET[ Data::key_delete ]) && $_GET[ Data::key_delete ] == 'true' || $this->status == 2) { ?>

                <div class='error'><p><strong><?php _e('Plugin options deleted.', 'wc-mfpc') ?></strong></p></div>

            <?php }

            /*
             * if options were saved
             */
            if (isset($_GET[ Data::key_flush ]) && $_GET[ Data::key_flush ] == 'true' || $this->status == 3) { ?>

                <div class='updated settings-error'><p><strong><?php _e("Cache flushed.", 'wc-mfpc'); ?></strong></p></div>

            <?php }

            /*
             * if options were saved, display saved message
             */
            if ((isset($_GET[ Data::key_precache ]) && $_GET[ Data::key_precache ] == 'true') || $this->status == 4) { ?>

                <div class='updated settings-error'><p><strong><?php _e('Precache process was started, it is now running in the background, please be patient, it may take a very long time to finish.', 'wc-mfpc') ?></strong></p></div>

            <?php } ?>

            <h2><?php echo Data::plugin_name . ' settings'; ?></h2>

            <div class="updated">
                <p>
                  <strong>
                    <?php
                      _e('Driver: ', 'wc-mfpc');
                      echo $this->options[ 'cache_type' ];
                    ?>
                  </strong>
                </p>
                <p>
                    <?php
                    _e('<strong>Backend status:</strong><br />', 'wc-mfpc');

                    /* we need to go through all servers */
                    $servers = $this->backend->status();

                    if (is_array($servers) && ! empty ($servers)) {

                        foreach ($servers as $server_string => $status) {

                            echo $server_string . " => ";
                            if ($status == 0) {

                                _e('<span class="error-msg">down</span><br />', 'wc-mfpc');

                            } elseif (($this->options[ 'cache_type' ] == 'memcache' && $status > 0) || $status == 1) {

                                _e('<span class="ok-msg">up & running</span><br />', 'wc-mfpc');

                            } else {

                                _e('<span class="error-msg">unknown, please try re-saving settings!</span><br />', 'wc-mfpc');

                            }

                        }

                    }
                    ?>
                </p>
            </div>
            <form autocomplete="off" method="post" action="#" id="<?php echo Data::plugin_constant ?>-settings" class="plugin-admin">
                <?php wp_nonce_field('wc-mfpc'); ?>
                <?php $switcher_tabs = $this->plugin_admin_panel_get_tabs(); ?>
                <ul class="tabs">
                    <?php foreach ($switcher_tabs AS $tab_section => $tab_label) { ?>

                        <li><a href="#<?= Data::plugin_constant ?>-<?= $tab_section ?>" class="wp-switch-editor"><?= $tab_label ?></a></li>

                    <?php } ?>
                </ul>

                <fieldset id="<?php echo Data::plugin_constant ?>-type">
                    <legend><?php _e('Set cache type', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <label for="cache_type"><?php _e('Select backend', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <select name="cache_type" id="cache_type">
                                <?php $this->print_select_options($this->select_cache_type, $this->options[ 'cache_type' ], $this->valid_cache_type) ?>
                            </select>
                            <span class="description"><?php _e('Select backend storage driver', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="expire"><?php _e('Expiration time for posts', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="expire" id="expire" value="<?php echo $this->options[ 'expire' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of post entry in seconds, including custom post types and pages.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="browsercache"><?php _e('Browser cache expiration time of posts', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="browsercache" id="browsercache" value="<?php echo $this->options[ 'browsercache' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of posts/pages/singles for the browser cache.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="expire_taxonomy"><?php _e('Expiration time for taxonomy', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="expire_taxonomy" id="expire_taxonomy" value="<?php echo $this->options[ 'expire_taxonomy' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of taxonomy entry in seconds, including custom taxonomy.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="browsercache_taxonomy"><?php _e('Browser cache expiration time of taxonomy', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="browsercache_taxonomy" id="browsercache_taxonomy" value="<?php echo $this->options[ 'browsercache_taxonomy' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of taxonomy for the browser cache.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="expire_home"><?php _e('Expiration time for home', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="expire_home" id="expire_home" value="<?php echo $this->options[ 'expire_home' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of home on server side.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="browsercache_home"><?php _e('Browser cache expiration time of home', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="number" name="browsercache_home" id="browsercache_home" value="<?php echo $this->options[ 'browsercache_home' ]; ?>"/>
                            <span class="description"><?php _e('Sets validity time of home for the browser cache.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="charset"><?php _e('Charset', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="charset" id="charset" value="<?php echo $this->options[ 'charset' ]; ?>"/>
                            <span class="description"><?php _e('Charset of HTML and XML (pages and feeds) data.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="invalidation_method"><?php _e('Cache invalidation method', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <select name="invalidation_method" id="invalidation_method">
                                <?php $this->print_select_options($this->select_invalidation_method, $this->options[ 'invalidation_method' ]) ?>
                            </select>
                            <div class="description"><?php _e('Select cache invalidation method.', 'wc-mfpc'); ?>
                                <ol>
                                    <?php
                                    $invalidation_method_description = [
                                        'clears everything in storage, <strong>including values set by other applications</strong>',
                                        'clear only the modified posts entry, everything else remains in cache',
                                        'unvalidates post and the taxonomy related to the post',
                                    ];

                                    foreach ($this->select_invalidation_method AS $current_key => $current_invalidation_method) {

                                        printf('<li><em>%1$s</em> - %2$s</li>', $current_invalidation_method, $invalidation_method_description[ $current_key ]);

                                    }

                                    ?>
                                </ol>
                            </div>
                        </dd>

                        <dt>
                            <label for="comments_invalidate"><?php _e('Invalidate on comment actions', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="comments_invalidate" id="comments_invalidate" value="1" <?php checked($this->options[ 'comments_invalidate' ], true); ?> />
                            <span class="description"><?php _e('Trigger cache invalidation when a comments is posted, edited, trashed. ', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="prefix_data"><?php _e('Data prefix', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="prefix_data" id="prefix_data" value="<?php echo $this->options[ 'prefix_data' ]; ?>"/>
                            <span
                                class="description"><?php _e('Prefix for HTML content keys, can be used in nginx.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.',
                                    'wc-mfpc'
                                ); ?></span>
                        </dd>

                        <dt>
                            <label for="prefix_meta"><?php _e('Meta prefix', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="prefix_meta" id="prefix_meta" value="<?php echo $this->options[ 'prefix_meta' ]; ?>"/>
                            <span class="description"><?php _e('Prefix for meta content keys, used only with PHP processing.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="key"><?php _e('Key scheme', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="key" id="key" value="<?php echo $this->options[ 'key' ]; ?>"/>
                            <span class="description"><?php _e('Key layout; <strong>use the guide below to change it</strong>.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.', 'wc-mfpc'); ?></span>
                            <dl class="description"><?php
                                foreach ($this->list_uri_vars as $uri => $desc) {
                                    echo '<dt>' . $uri . '</dt><dd>' . $desc . '</dd>';
                                }
                                ?></dl>
                        </dd>

                        <dt>
                            <label for="hashkey"><?php _e('SHA1 hash key', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="hashkey" id="hashkey" value="1" <?php checked($this->options[ 'hashkey' ], true); ?> />
                            <span
                                class="description"><?php _e('Occasionally URL can be too long to be used as key for the backend storage, especially with memcached. Turn on this feature to use SHA1 hash of the URL as key instead. Please be aware that you have to add ( or uncomment ) a line and a <strong>module</strong> in nginx if you want nginx to fetch the data directly; for details, please see the nginx example tab.',
                                    'wc-mfpc'
                                ); ?>
                        </dd>


                    </dl>
                </fieldset>

                <fieldset id="<?php echo Data::plugin_constant; ?>-debug">
                    <legend><?php _e('Debug & in-depth settings', 'wc-mfpc'); ?></legend>
                    <h3><?php _e('Notes', 'wc-mfpc'); ?></h3>
                    <p><?php _e('The former method of debug logging flag has been removed. In case you need debug log from WC-MFPC please set both the <a href="http://codex.wordpress.org/WP_DEBUG">WP_DEBUG</a> and the WC_MFPC__DEBUG_MODE constants `true` in wp-config.php.<br /> This will enable NOTICE level messages apart from the WARNING level ones which are always displayed.', 'wc-mfpc'); ?></p>

                    <dl>
                        <dt>
                            <label for="pingback_header"><?php _e('Enable X-Pingback header preservation', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="pingback_header" id="pingback_header" value="1" <?php checked($this->options[ 'pingback_header' ], true); ?> />
                            <span class="description"><?php _e('Preserve X-Pingback URL in response header.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="response_header"><?php _e("Add X-Cache-Engine header", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="response_header" id="response_header" value="1" <?php checked($this->options[ 'response_header' ], true); ?> />
                            <span class="description"><?php _e('Add X-Cache-Engine HTTP header to HTTP responses.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="generate_time"><?php _e("Add HTML debug comment", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="generate_time" id="generate_time" value="1" <?php checked($this->options[ 'generate_time' ], true); ?> />
                            <span class="description"><?php _e('Adds comment string including plugin name, cache engine and page generation time to every generated entry before closing <body> tag.', 'wc-mfpc'); ?></span>
                        </dd>

                    </dl>

                </fieldset>

                <fieldset id="<?php echo Data::plugin_constant ?>-exceptions">
                    <legend><?php _e('Set cache additions/excepions', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <label for="cache_loggedin"><?php _e('Enable cache for logged in users', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="cache_loggedin" id="cache_loggedin" value="1" <?php checked($this->options[ 'cache_loggedin' ], true); ?> />
                            <span class="description"><?php _e('Cache pages even if user is logged in.', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label><?php _e("Excludes", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <table style="width:100%">
                                <thead>
                                <tr>
                                    <th style="width:13%; text-align:left"><label for="nocache_home"><?php _e("Exclude home", 'wc-mfpc'); ?></label></th>
                                    <th style="width:13%; text-align:left"><label for="nocache_feed"><?php _e("Exclude feeds", 'wc-mfpc'); ?></label></th>
                                    <th style="width:13%; text-align:left"><label for="nocache_archive"><?php _e("Exclude archives", 'wc-mfpc'); ?></label></th>
                                    <th style="width:13%; text-align:left"><label for="nocache_page"><?php _e("Exclude pages", 'wc-mfpc'); ?></label></th>
                                    <th style="width:13%; text-align:left"><label for="nocache_single"><?php _e("Exclude singulars", 'wc-mfpc'); ?></label></th>
                                    <th style="width:17%; text-align:left"><label for="nocache_dyn"><?php _e("Dynamic requests", 'wc-mfpc'); ?></label></th>
                                    <th style="width:18%; text-align:left"><label for="nocache_woocommerce"><?php _e("WooCommerce", 'wc-mfpc'); ?></label></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="nocache_home" id="nocache_home" value="1" <?php checked($this->options[ 'nocache_home' ], true); ?> />
                                        <span class="description"><?php _e('Never cache home.', 'wc-mfpc'); ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="nocache_feed" id="nocache_feed" value="1" <?php checked($this->options[ 'nocache_feed' ], true); ?> />
                                        <span class="description"><?php _e('Never cache feeds.', 'wc-mfpc'); ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="nocache_archive" id="nocache_archive" value="1" <?php checked($this->options[ 'nocache_archive' ], true); ?> />
                                        <span class="description"><?php _e('Never cache archives.', 'wc-mfpc'); ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="nocache_page" id="nocache_page" value="1" <?php checked($this->options[ 'nocache_page' ], true); ?> />
                                        <span class="description"><?php _e('Never cache pages.', 'wc-mfpc'); ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="nocache_single" id="nocache_single" value="1" <?php checked($this->options[ 'nocache_single' ], true); ?> />
                                        <span class="description"><?php _e('Never cache singulars.', 'wc-mfpc'); ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="nocache_dyn" id="nocache_dyn" value="1" <?php checked($this->options[ 'nocache_dyn' ], true); ?> />
                                        <span class="description"><?php _e('Exclude every URL with "?" in it.', 'wc-mfpc'); ?></span>
                                    </td>
                                    <td>
                                        <input type="hidden" name="nocache_woocommerce_url" id="nocache_woocommerce_url" value="<?php if (isset($this->options[ 'nocache_woocommerce_url' ])) {
                                            echo $this->options[ 'nocache_woocommerce_url' ];
                                        } ?>"/>
                                        <input type="checkbox" name="nocache_woocommerce" id="nocache_woocommerce" value="1" <?php checked($this->options[ 'nocache_woocommerce' ], true); ?> />
                                        <span class="description"><?php _e('Exclude dynamic WooCommerce page.', 'wc-mfpc'); ?>
                                            <?php if (isset($this->options[ 'nocache_woocommerce_url' ])) {
                                                echo "<br />Url:" . $this->options[ 'nocache_woocommerce_url' ];
                                            } ?></span>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </dd>
                        <dt>
                            <label for="nocache_cookies"><?php _e("Exclude based on cookies", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="nocache_cookies" id="nocache_cookies" value="<?php if (isset($this->options[ 'nocache_cookies' ])) {
                                echo $this->options[ 'nocache_cookies' ];
                            } ?>"/>
                            <span class="description"><?php _e('Exclude content based on cookies names starting with this from caching. Separate multiple cookies names with commas.<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.',
                                    'wc-mfpc'
                                ); ?></span>
                        </dd>

                        <dt>
                            <label for="nocache_url"><?php _e("Don't cache following URL paths - use with caution!", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
					<textarea name="nocache_url" id="nocache_url" rows="3" cols="100" class="large-text code"><?php
                        if (isset($this->options[ 'nocache_url' ])) {
                            echo $this->options[ 'nocache_url' ];
                        }
                        ?></textarea>
                            <span class="description"><?php _e('Regular expressions use you must! e.g. <em>pattern1|pattern2|etc</em>', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="nocache_comment"><?php _e("Exclude from cache based on content", 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input name="nocache_comment" id="nocache_comment" type="text" value="<?php if (isset($this->options[ 'nocache_comment' ])) {
                                echo $this->options[ 'nocache_comment' ];
                            } ?>"/>
                            <span class="description"><?php _e('Enter a regex pattern that will trigger excluding content from caching. Eg. <!--nocache-->. Regular expressions use you must! e.g. <em>pattern1|pattern2|etc</em><br />
					<strong>WARNING:</strong> be careful where you display this, because it will apply to any content, including archives, collection pages, singles, anything. If empty, this setting will be ignored.', 'wc-mfpc'
                                ); ?></span>
                        </dd>

                    </dl>
                </fieldset>

                <fieldset id="<?php echo Data::plugin_constant ?>-servers">
                    <legend><?php _e('Backend server settings', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <label for="hosts"><?php _e('Hosts', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" name="hosts" id="hosts" value="<?php echo $this->options[ 'hosts' ]; ?>"/>
                            <span class="description">
					        <?php _e('List of backends, with the following syntax: <br />- in case of TCP based connections, list the servers as host1:port1,host2:port2,... . Do not add trailing , and always separate host and port with : .<br />- for a unix socket enter: unix://[socket_path]', 'wc-mfpc'); ?>
                </span>
                        </dd>

                        <h3><?php _e('Authentication ( only for SASL enabled Memcached)') ?></h3>
                        <?php if (! ini_get('memcached.use_sasl') && (! empty($this->options[ 'authuser' ]) || ! empty($this->options[ 'authpass' ]))) { ?>
                            <div class="error"><p><strong><?php _e('WARNING: you\'ve entered username and/or password for memcached authentication ( or your browser\'s autocomplete did ) which will not work unless you enable memcached sasl in the PHP settings: add `memcached.use_sasl=1` to php.ini', 'wc-mfpc'); ?></strong></p></div>
                        <?php } ?>
                        <dt>
                            <label for="authuser"><?php _e('Authentication: username', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="text" autocomplete="off" name="authuser" id="authuser" value="<?php echo $this->options[ 'authuser' ]; ?>"/>
                            <span class="description">
					      <?php _e('Username for authentication with backends', 'wc-mfpc'); ?></span>
                        </dd>

                        <dt>
                            <label for="authpass"><?php _e('Authentication: password', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="password" autocomplete="off" name="authpass" id="authpass" value="<?php echo $this->options[ 'authpass' ]; ?>"/>
                            <span class="description">
					      <?php _e('Password for authentication with for backends - WARNING, the password will be stored in an unsecure format!', 'wc-mfpc'); ?></span>
                        </dd>

                        <h3><?php _e('Memcached specific settings') ?></h3>
                        <dt>
                            <label for="memcached_binary"><?php _e('Enable memcached binary mode', 'wc-mfpc'); ?></label>
                        </dt>
                        <dd>
                            <input type="checkbox" name="memcached_binary" id="memcached_binary" value="1" <?php checked($this->options[ 'memcached_binary' ], true); ?> />
                            <span class="description"><?php _e('Some memcached proxies and implementations only support the ASCII protocol.', 'wc-mfpc'); ?></span>
                        </dd>


                    </dl>
                </fieldset>

                <fieldset id="<?php echo Data::plugin_constant ?>-precache">
                    <legend><?php _e('Precache settings & log from previous pre-cache generation', 'wc-mfpc'); ?></legend>

                    <dt>
                        <label for="precache_schedule"><?php _e('Precache schedule', 'wc-mfpc'); ?></label>
                    </dt>
                    <dd>
                        <select name="precache_schedule" id="precache_schedule">
                            <?php $this->print_select_options($this->select_schedules, $this->options[ 'precache_schedule' ]) ?>
                        </select>
                        <span class="description"><?php _e('Schedule autorun for precache with WP-Cron', 'wc-mfpc'); ?></span>
                    </dd>

                    <?php
                    $gentime = static::_get_option(Data::precache_timestamp, $this->network);
                    $log     = static::_get_option(Data::precache_log, $this->network);
                    if (@file_exists($this->precache_logfile)) {
                        $logtime = filemtime($this->precache_logfile);
                        /* update precache log in DB if needed */
                        if ($logtime > $gentime) {
                            $log = file($this->precache_logfile);
                            static::_update_option(Data::precache_log, $log, $this->network);
                            static::_update_option(Data::precache_timestamp, $logtime, $this->network);
                        }
                    }
                    if (empty ($log)) {
                        _e('No precache log was found!', 'wc-mfpc');
                    } else { ?>
                        <p><strong><?php _e('Time of run: ') ?><?php echo date('r', $gentime); ?></strong></p>
                        <div style="overflow: auto; max-height: 20em;">
                            <table style="width:100%; border: 1px solid #ccc;">
                                <thead>
                                <tr>
                                    <?php $head = explode("	", array_shift($log));
                                    foreach ($head as $column) { ?>
                                        <th><?php echo $column; ?></th>
                                    <?php } ?>
                                </tr>
                                </thead>
                                <?php
                                foreach ($log as $line) { ?>
                                    <tr>
                                        <?php $line = explode("	", $line);
                                        foreach ($line as $column) { ?>
                                            <td><?php echo $column; ?></td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    <?php } ?>
                </fieldset>

                <?php do_action('wc_mfpc_admin_panel_tabs_extra_content', 'wc-mfpc'); ?>

                <p class="clear">
                    <input class="button-primary" type="submit" name="<?php echo Data::button_save ?>" id="<?php echo Data::button_save ?>" value="<?php _e('Save Changes', 'wc-mfpc') ?>"/>
                </p>

            </form>

            <form method="post" action="#" id="<?php echo Data::plugin_constant ?>-commands" class="plugin-admin" style="padding-top:2em;">

                <?php wp_nonce_field('wc-mfpc'); ?>

                <ul class="tabs">
                    <li><a href="#<?php echo Data::button_precache; ?>" class="wp-switch-editor"><?php _e('Precache', 'wc-mfpc'); ?></a></li>
                    <li><a href="#<?php echo Data::button_flush; ?>" class="wp-switch-editor"><?php _e('Empty cache', 'wc-mfpc'); ?></a></li>
                    <li><a href="#<?php echo Data::button_delete; ?>" class="wp-switch-editor"><?php _e('Reset settings', 'wc-mfpc'); ?></a></li>
                </ul>

                <fieldset id="<?php echo Data::button_precache; ?>-fields">
                    <legend><?php _e('Precache', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <?php if ((isset($_GET[ Data::key_precache_disabled ]) && $_GET[ Data::key_precache_disabled ] == 'true') || $this->status == 5 || $this->shell_function == false) { ?>
                                <strong><?php _e("Precache functionality is disabled due to unavailable system call function. <br />Since precaching may take a very long time, it's done through a background CLI process in order not to run out of max execution time of PHP. Please enable one of the following functions if you whish to use precaching: ",
                                        'wc-mfpc'
                                    ) ?><?php echo join(',', $this->shell_possibilities); ?></strong>
                            <?php } else { ?>
                                <input class="button-secondary" type="submit" name="<?php echo Data::button_precache ?>" id="<?php echo Data::button_precache ?>" value="<?php _e('Pre-cache', 'wc-mfpc') ?>"/>
                            <?php } ?>
                        </dt>
                        <dd>
                <span
                    class="description"><?php _e('Start a background process that visits all permalinks of all blogs it can found thus forces WordPress to generate cached version of all the pages.<br />The plugin tries to visit links of taxonomy terms without the taxonomy name as well. This may generate 404 hits, please be prepared for these in your logfiles if you plan to pre-cache.',
                        'wc-mfpc'
                    ); ?></span>
                        </dd>
                    </dl>
                </fieldset>
                <fieldset id="<?php echo Data::button_flush; ?>-fields">
                    <legend><?php _e('Precache', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <input class="button-warning" type="submit" name="<?php echo Data::button_flush; ?>" id="<?php echo Data::button_flush; ?>" value="<?php _e('Clear cache', 'wc-mfpc') ?>"/>
                        </dt>
                        <dd>
                            <span class="description"><?php _e("Clear all entries in the storage, including the ones that were set by other processes.", 'wc-mfpc'); ?> </span>
                        </dd>
                    </dl>
                </fieldset>
                <fieldset id="<?php echo Data::button_delete; ?>-fields">
                    <legend><?php _e('Precache', 'wc-mfpc'); ?></legend>
                    <dl>
                        <dt>
                            <input class="button-warning" type="submit" name="<?php echo Data::button_delete; ?>" id="<?php echo Data::button_delete; ?>" value="<?php _e('Reset options', 'wc-mfpc') ?>"/>
                        </dt>
                        <dd>
                            <span class="description"><?php _e("Reset settings to defaults.", 'wc-mfpc'); ?> </span>
                        </dd>
                    </dl>
                </fieldset>
            </form>
        </div>
        <?php
    }

}