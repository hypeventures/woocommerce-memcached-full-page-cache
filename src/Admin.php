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
    private $select_invalidation_method = [];

    /**
     * @var array
     */
    private $list_uri_vars = [];

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

        /* invalidation method possible values array */
        $this->select_invalidation_method = [
            0 => __('flush cache', 'wc-mfpc'),
            1 => __('only modified post', 'wc-mfpc'),
            2 => __('modified post and all related taxonomies', 'wc-mfpc'),
        ];

        /* map of possible key masks */
        $this->list_uri_vars = [
            '$scheme'           => __('The HTTP scheme (i.e. http, https).', 'wc-mfpc'),
            '$host'             => __('Host in the header of request or name of the server processing the request if the Host header is not available.', 'wc-mfpc'),
            '$request_uri'      => __('The *original* request URI as received from the client including the args', 'wc-mfpc'),
            '$remote_user'      => __('Name of user, authenticated by the Auth Basic Module', 'wc-mfpc'),
            '$cookie_PHPSESSID' => __('PHP Session Cookie ID, if set ( empty if not )', 'wc-mfpc'),
            '$accept_lang'      => __('First HTTP Accept Lang set in the HTTP request', 'wc-mfpc'),
        ];
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
     * admin panel, the admin page displayed for plugin settings
     */
    public function plugin_admin_panel()
    {
        global $wcMfpcConfig;

        /*
         * security, if somehow we're running without WordPress security functions
         */
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {

            die();

        }

        ?>
        <div class="wrap">
            <h1>WooCommerce Memcached Full Page Cache</h1>

            <?php $this->renderMessages()->renderActionButtons('flush'); ?>

            <form autocomplete="off" method="post" action="#" id="<?php echo Data::plugin_constant ?>-settings" class="plugin-admin">

                <?php wp_nonce_field('wc-mfpc'); ?>

                <fieldset id="<?php echo Data::plugin_constant ?>-servers">
                  <legend>Memcached connection settings</legend>
                  <?php
                  woocommerce_wp_text_input([
                      'id'          => 'hosts',
                      'label'       => 'Host(s)',
                      'class'       => 'short',
                      'description' => '<b>host1:port1,host2:port2,...</b> - OR - <b>unix://[socket_path]</b>',
                      'value'       => $wcMfpcConfig->getHosts(),
                  ]);
                  woocommerce_wp_text_input([
                      'id'          => 'authuser',
                      'label'       => 'Username',
                      'class'       => 'short',
                      'description' => 'Username for authentication with Memcached <span class="error-msg">(Only if SASL is enabled)</span>',
                      'value'       => $wcMfpcConfig->getAuthuser(),
                  ]);
                  woocommerce_wp_text_input([
                      'id'          => 'authpass',
                      'label'       => 'Password',
                      'class'       => 'short',
                      'description' => 'Username for authentication with Memcached <span class="error-msg">(Only if SASL is enabled)</span>',
                      'value'       => $wcMfpcConfig->getAuthpass(),
                  ]);
                  woocommerce_wp_checkbox([
                      'id'          => 'memcached_binary',
                      'label'       => 'Enable binary mode',
                      'description' => 'Some memcached proxies and implementations only support the ASCII protocol.',
                      'value'       => $wcMfpcConfig->isMemcachedBinary() ? 'yes' : 'no',
                  ]);
                  ?>
                </fieldset>

                <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

                <fieldset id="<?php echo Data::plugin_constant; ?>-type">
                    <legend>Cache settings</legend>
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => 'expire',
                        'label'       => 'Expiration of Posts',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of post entry in seconds, including custom post types and pages.',
                        'value'       => $wcMfpcConfig->getExpire(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'browsercache',
                        'label'       => 'Browser cache expiration of Posts',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of posts/pages/singles for the browser cache.',
                        'value'       => $wcMfpcConfig->getBrowsercache(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'expire_taxonomy',
                        'label'       => 'Expiration of Taxonomies',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of taxonomy entry in seconds, including custom taxonomy.',
                        'value'       => $wcMfpcConfig->getExpireTaxonomy(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'browsercache_taxonomy',
                        'label'       => 'Browser cache expiration of Taxonomies',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of taxonomy for the browser cache.',
                        'value'       => $wcMfpcConfig->getBrowsercacheTaxonomy(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'expire_home',
                        'label'       => 'Expiration of Home',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of home on server side.',
                        'value'       => $wcMfpcConfig->getExpireHome(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'browsercache_home',
                        'label'       => 'Browser cache expiration of Home',
                        'type'        => 'number',
                        'data_type'   => 'decimal',
                        'class'       => 'short',
                        'description' => 'Sets validity time of home for the browser cache.',
                        'value'       => $wcMfpcConfig->getBrowsercacheHome(),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'charset',
                        'label'       => 'Charset',
                        'class'       => 'short',
                        'description' => 'Charset of HTML and XML (pages and feeds) data.',
                        'value'       => $wcMfpcConfig->getCharset(),
                    ]);
                    woocommerce_wp_select([
                        'id'          => 'invalidation_method',
                        'label'       => 'Cache invalidation method',
                        'class'       => 'short',
                        'description' => 'Select cache invalidation method.',
                        'options'     => $this->select_invalidation_method,
                        'value'       => $wcMfpcConfig->getInvalidationMethod(),
                    ]);
                    ?>
                    <ol class="description-addon">
                      <li>
                        <b><?php echo $this->select_invalidation_method[ 0 ]; ?></b>
                        - Clears everything in storage, <span class="error-msg">including values set by other applications.</span>
                      </li>
                      <li>
                        <b><?php echo $this->select_invalidation_method[ 1 ]; ?></b>
                        - Clear only the modified posts entry, everything else remains in cache.
                      </li>
                      <li>
                        <b><?php echo $this->select_invalidation_method[ 2 ]; ?></b>
                        - Unvalidates post and the taxonomy related to the Post.
                      </li>
                    </ol>
                    <?php
                    woocommerce_wp_checkbox([
                        'id'          => 'comments_invalidate',
                        'label'       => 'Invalidate on comment actions',
                        'description' => 'Trigger cache invalidation when a comments is posted, edited, trashed.',
                        'value'       => $wcMfpcConfig->isCommentsInvalidate() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => 'prefix_data',
                        'label'       => 'Data prefix',
                        'class'       => 'short',
                        'description' => 'Prefix for HTML content keys, can be used in nginx.',
                        'value'       => $wcMfpcConfig->getPrefixData(),
                    ]);
                    ?>
                    <div class="description-addon">
                      <b>WARNING</b>: changing this will result the previous cache to becomes invalid!<br />
                      If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.
                    </div>
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => 'prefix_meta',
                        'label'       => 'Meta prefix',
                        'class'       => 'short',
                        'description' => 'Prefix for meta content keys, used only with PHP processing.',
                        'value'       => $wcMfpcConfig->getPrefixMeta(),
                    ]);
                    ?>
                    <div class="description-addon">
                      <b>WARNING</b>: changing this will result the previous cache to becomes invalid!
                    </div>
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => 'key',
                        'label'       => 'Key scheme',
                        'class'       => 'short',
                        'description' => 'Key layout: <b>please use the guide below to change it.</b>',
                        'value'       => $wcMfpcConfig->getKey(),
                    ]);
                    ?>
                    <div class="description-addon">
                      <b>WARNING</b>: changing this will result the previous cache to becomes invalid!<br />
                      If you are caching with nginx, you should update your nginx configuration and reload nginx after
                      changing this value.
                    </div>
                    <table class="description-addon" style="margin-top: 0;" cellspacing="0" cellpadding="0">
                      <tr><th colspan="2" style="text-align: left;"><h3>Possible variables:</h3></th></tr>
                      <?php
                      foreach ($this->list_uri_vars as $uri => $desc) {

                          echo '<tr><td><b>' . $uri . '</b>:</td><td><i>' . $desc . '</i></td></tr>';

                      }
                      ?>
                    </table>
                    <?php
                    woocommerce_wp_checkbox([
                        'id'          => 'hashkey',
                        'label'       => 'SHA1 hash key',
                        'description' => '
                            Occasionally URL can be too long to be used as key for the backend storage, especially with 
                            memcached. Turn on this feature to use SHA1 hash of the URL as key instead. Please be aware 
                            that you have to add ( or uncomment ) a line and a <strong>module</strong> in nginx if you 
                            want nginx to fetch the data directly; for details, please see the nginx example tab.
                        ',
                        'value'       => $wcMfpcConfig->isHashkey() ? 'yes' : 'no',
                    ]);
                    ?>
                </fieldset>

                <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

                <fieldset id="<?php echo Data::plugin_constant ?>-exceptions">
                    <legend>Exception settings</legend>
                    <?php
                    woocommerce_wp_checkbox([
                        'id'          => 'cache_loggedin',
                        'label'       => 'Cache for logged in users',
                        'description' => 'Enable to cache pages even if user is logged in.',
                        'value'       => $wcMfpcConfig->isCacheLoggedin() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_home',
                        'label'       => 'Exclude home',
                        'description' => 'Enable to never cache home.',
                        'value'       => $wcMfpcConfig->isNocacheHome() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_feed',
                        'label'       => 'Exclude feeds',
                        'description' => 'Enable to never cache feeds.',
                        'value'       => $wcMfpcConfig->isNocacheFeed() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_archive',
                        'label'       => 'Exclude archives',
                        'description' => 'Enable to never cache archives.',
                        'value'       => $wcMfpcConfig->isNocacheArchive() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_page',
                        'label'       => 'Exclude pages',
                        'description' => 'Enable to never cache pages.',
                        'value'       => $wcMfpcConfig->isNocachePage() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_single',
                        'label'       => 'Exclude singulars',
                        'description' => 'Enable to never cache singulars.',
                        'value'       => $wcMfpcConfig->isNocacheSingle() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_dyn',
                        'label'       => 'Exclude dynamic requests',
                        'description' => 'Enable to exclude every dynamic request URL with GET parameters.',
                        'value'       => $wcMfpcConfig->isNocacheDyn() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'nocache_woocommerce',
                        'label'       => 'Exclude dynamic WooCommerce pages',
                        'description' => 'Enable to exclude every dynamic WooCommerce page.',
                        'value'       => $wcMfpcConfig->isNocacheWoocommerce() ? 'yes' : 'no',
                    ]);
                    ?>
                    <div class="description-addon"><b>Pattern:</b> <i><?php echo $wcMfpcConfig->getNocacheWoocommerceUrl(); ?></i></div>
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => 'nocache_cookies',
                        'label'       => 'Exclude based on cookies',
                        'class'       => 'short',
                        'description' => 'Exclude content based on cookies names starting with this from caching. Separate multiple cookies names with commas.',
                        'value'       => $wcMfpcConfig->getNocacheCookies(),
                    ]);
                    ?>
                    <div class="description-addon">
                      <b>WARNING:</b>
                      <i>If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value. </i>
                    </div>
                    <?php
                    woocommerce_wp_textarea_input([
                        'id'          => 'nocache_url',
                        'label'       => 'Exclude URLs',
                        'class'       => 'short',
                        'description' => '<b>WARINING: Use with caution!</b> Use multiple RegEx patterns like e.g. <em>pattern1|pattern2|etc</em>',
                        'value'       => $wcMfpcConfig->getNocacheUrl(),
                    ]);
                    ?>
                </fieldset>

                <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

                <fieldset id="<?php echo Data::plugin_constant; ?>-debug">
                  <legend>Header / Debug settings</legend>
                    <?php
                    woocommerce_wp_checkbox([
                        'id'          => 'pingback_header',
                        'label'       => 'X-Pingback header preservation',
                        'description' => 'Enable to preserve X-Pingback URL in response header.',
                        'value'       => $wcMfpcConfig->isPingbackHeader() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'response_header',
                        'label'       => 'X-Cache-Engine HTTP header',
                        'description' => 'Enable to add X-Cache-Engine HTTP header to HTTP responses.',
                        'value'       => $wcMfpcConfig->isResponseHeader() ? 'yes' : 'no',
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => 'generate_time',
                        'label'       => 'HTML debug comment',
                        'description' => 'Adds comment string including plugin name, cache engine and page generation time to every generated entry before closing <b>body</b> tag.',
                        'value'       => $wcMfpcConfig->isGenerateTime() ? 'yes' : 'no',
                    ]);
                    ?>
                </fieldset>

                <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>
            </form>

            <?php $this->renderActionButtons('reset'); ?>

        </div>
        <?php
    }

    /**
     * Renders information for administrators if conditions are met.
     *
     * @return Admin
     */
    private function renderMessages()
    {
        global $wcMfpcData, $wcMfpcConfig;

        /*
         * if options were saved
         */
        if (isset($_GET[ Data::key_save ]) && $_GET[ Data::key_save ] == 'true' || $this->status == 1) {

            Alert::alert('<strong>Settings saved.</strong>');

        }

        /*
         * if options were deleted
         */
        if (isset($_GET[ Data::key_delete ]) && $_GET[ Data::key_delete ] == 'true' || $this->status == 2) {

            Alert::alert('<strong>Plugin options deleted. </strong>');

        }

        /*
         * if flushed
         */
        if (isset($_GET[ Data::key_flush ]) && $_GET[ Data::key_flush ] == 'true' || $this->status == 3) {

            Alert::alert('<strong>Cache flushed.</strong>');

        }

        $settings_link = ' &raquo; <a href="' . $wcMfpcData->settings_link . '">WC-MFPC Settings</a>';

        /*
         * look for global settings array
         */
        if (! $this->global_saved) {

            Alert::alert(sprintf(
                'This site was reached as %s ( according to PHP HTTP_HOST ) and there are no settings present for this domain in the WC-MFPC configuration yet. Please save the %s for the domain or fix the webserver configuration!',
                $_SERVER[ 'HTTP_HOST' ], $settings_link
            ), LOG_WARNING);

        }

        /*
         * look for writable acache file
         */
        if (file_exists($wcMfpcData->acache) && ! is_writable($wcMfpcData->acache)) {

            Alert::alert(sprintf('Advanced cache file (%s) is not writeable!<br />Please change the permissions on the file.', $wcMfpcData->acache), LOG_WARNING);

        }

        /*
         * look for acache file
         */
        if (! file_exists($wcMfpcData->acache)) {

            Alert::alert(sprintf('Advanced cache file is yet to be generated, please save %s', $settings_link), LOG_WARNING);

        }

        /*
         * check if php memcached extension is active
         */
        if (! extension_loaded('memcached')) {

            Alert::alert('Memcached activated but the PHP extension was not found.<br />Please activate the module!', LOG_WARNING);

        }

        /*
         * If SASL is not used but authentication info was provided.
         */
        if (! ini_get('memcached.use_sasl') && (! empty($wcMfpcConfig->getAuthuser()) || ! empty($wcMfpcConfig->getAuthpass()))) {
            Alert::alert(
              "WARNING: you've entered username and/or password for memcached authentication ( or your browser's" .
              "autocomplete did ) which will not work unless you enable memcached sasl in the PHP settings:" .
              "add `memcached.use_sasl=1` to php.ini",
              LOG_ERR, true
            );
        }

        Alert::alert($this->getServersStatusAlert());

        return $this;
    }

    /**
     * Generates the Alert message string to show Memcached Servers status.
     *
     * @return string
     */
    private function getServersStatusAlert()
    {
        global $wcMfpc, $wcMfpcConfig;

        $servers = $wcMfpc->backend->status();

        if (empty ($servers) || ! is_array($servers)) {

            return '';
        }

        $message = '<strong>Driver: ' . $wcMfpcConfig->getCacheType() . '</strong><br><strong>Backend status:</strong></p><p>';

        foreach ($servers as $server_string => $status) {

            $message .= $server_string . " => ";

            if ($status == 0) {

                $message .= '<span class="error-msg">Down</span><br />';

            } elseif ($status == 1) {

                $message .= '<span class="ok-msg">Up & running</span><br />';

            } else {

                $message .= '<span class="error-msg">Unknown, please try re-saving settings!</span><br />';

            }

        }

        return $message;
    }

    /**
     * Renders the Form with the action buttons for "Clear-Cache" & "Reset-Settings".
     *
     * @param string $button   'flush'|'reset'
     *
     * @return Admin
     */
    private function renderActionButtons($button = 'flush')
    {
        if (empty($button) || ! in_array($button, [ 'flush', 'reset' ])) {

            echo '<p class="error-msg"><b>NO or UNKNOWN action button defined to render.</b></p>';

            return $this;
        }
        ?>
        <form method="post" action="#" id="<?php echo Data::plugin_constant ?>-commands" class="plugin-admin">
          <p>
            <?php
            wp_nonce_field('wc-mfpc');

            if ($button === 'flush') {

                submit_button('&#x1f5d1; Flush Cache', 'secondary', Data::button_flush, false, [
                    'style' => 'color: #f33; font-weight: normal; margin: 1rem 1rem 1rem 0;',
                ]);
                echo '<span class="error-msg">Flushes Memcached. All entries in the cache are deleted, <b>including the ones that were set by other processes.</b></span>';

            } else {

                submit_button('&#x21bb; Reset Settings', 'secondary', Data::button_delete, false, [
                    'style' => 'color: #f33; font-weight: normal; margin: 1rem 1rem 1rem 0;',
                ]);
                echo '<span class="error-msg"><b>Resets ALL settings on this page to DEFAULT.</b></span>';

            }
            ?>
          </p>
        </form>
        <?php

        return $this;
    }

}
