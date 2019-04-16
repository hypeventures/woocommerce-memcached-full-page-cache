<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

use InvincibleBrands\WcMfpc\Admin\Admin;

/**
 * Class WcMfpc
 *
 * @package InvincibleBrands\WcMfpc
 */
class WcMfpc
{

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var array|mixed
     */
    protected $defaults = [];

    /**
     * @var int
     */
    protected $status = 0;

    /**
     * @var bool
     */
    protected $network = false;

    /**
     * @var string
     */
    protected $settings_link = '';

    /**
     * @var string
     */
    protected $settings_slug = '';

    /**
     * @var
     */
    protected $plugin_url;

    /**
     * @var
     */
    protected $plugin_dir;

    /**
     * @var
     */
    protected $common_url;

    /**
     * @var
     */
    protected $common_dir;

    /**
     * @var string
     */
    protected $plugin_file;

    /**
     * @var string
     */
    protected $plugin_settings_page;

    /**
     * @var
     */
    protected $admin_css_handle;

    /**
     * @var
     */
    protected $admin_css_url;

    /**
     * @var null
     */
    protected $utils = null;

    /**
     * @var string
     */
    private $precache_logfile = '';

    /**
     * @var string
     */
    private $precache_phpfile = '';

    /**
     * @var string
     */
    private $global_config_key = '';

    /**
     * @var array
     */
    private $global_config = [];

    /**
     * @var bool
     */
    private $global_saved = false;

    /**
     * @var string
     */
    private $acache_worker = '';

    /**
     * @var string
     */
    private $acache = '';

    /**
     * @var array
     */
    private $select_cache_type = [];

    /**
     * @var array
     */
    private $select_invalidation_method = [];

    /**
     * @var array
     */
    private $select_schedules = [];

    /**
     * @var array
     */
    private $valid_cache_type = [];

    /**
     * @var array
     */
    private $list_uri_vars = [];

    /**
     * @var bool
     */
    private $shell_function = false;

    /**
     * @var array
     */
    private $shell_possibilities = [];

    /**
     * @var null|Memcached
     */
    private $backend = null;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * WcMfpc constructor.
     *
     * @param mixed $defaults   Default value(s) for plugin option(s)
     *
     * @return void
     */
    public function __construct($defaults = [])
    {
        $this->plugin_file          = Data::plugin_constant . '/' . Data::plugin_constant . '.php';
        $this->defaults             = $defaults;
        $this->plugin_settings_page = Data::plugin_constant . '-settings';
        $this->plugin_url           = plugin_dir_url(__FILE__);
        $this->plugin_dir           = plugin_dir_path(dirname(__FILE__));
        $this->admin_css_handle     = Data::plugin_constant . '-admin-css';
        $this->admin_css_url        = $this->plugin_url . 'assets/admin.css';

        add_action('init', [ &$this, 'plugin_init' ]);
        add_action('plugins_loaded', [ &$this, 'plugin_load_textdomain' ]);
    }

    /**
     * @return void
     */
    public function plugin_post_construct()
    {
    }

    /**
     * replaces http:// with https:// in an url if server is currently running on https
     *
     * @param string $url URL to check
     *
     * @return string URL with correct protocol
     */
    public static function replace_if_ssl($url)
    {
        if (isset($_SERVER[ 'HTTP_X_FORWARDED_PROTO' ]) && $_SERVER[ 'HTTP_X_FORWARDED_PROTO' ] == 'https') {

            $_SERVER[ 'HTTPS' ] = 'on';

        }

        if (isset($_SERVER[ 'HTTPS' ]) && ((strtolower($_SERVER[ 'HTTPS' ]) == 'on') || ($_SERVER[ 'HTTPS' ] == '1'))) {

            $url = str_replace('http://', 'https://', $url);

        }

        return $url;
    }

    /**
     * @return void
     */
    public function plugin_init()
    {

        /* initialize plugin, plugin specific init functions */
        $this->plugin_pre_init();

        register_activation_hook($this->plugin_file, [ &$this, 'plugin_activate' ]);
        register_deactivation_hook($this->plugin_file, [ &$this, 'plugin_deactivate' ]);

        if (is_admin()) {

            $admin = new Admin();
            $admin->setHooks();
            $admin->plugin_options_read();

        }

        /* setup plugin, plugin specific setup functions that need options */
        $this->plugin_post_init();
    }

    /**
     * init hook function runs before admin panel hook, themeing and options read
     */
    public function plugin_pre_init()
    {
        /* advanced cache "worker" file */
        $this->acache_worker = $this->plugin_dir . Data::plugin_constant . '-acache.php';
        /* WordPress advanced-cache.php file location */
        $this->acache = WP_CONTENT_DIR . '/advanced-cache.php';
        /* precache log */
        $this->precache_logfile = sys_get_temp_dir() . '/' . Data::precache_log;
        /* this is the precacher php worker file */
        $this->precache_phpfile = sys_get_temp_dir() . '/' . Data::precache_php;
        /* search for a system function */
        $this->shell_possibilities = [ 'shell_exec', 'exec', 'system', 'passthru' ];
        /* get disabled functions list */
        $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions')));

        foreach ($this->shell_possibilities as $possible) {

            if (function_exists($possible) && ! (ini_get('safe_mode') || in_array($possible, $disabled_functions))) {

                /* set shell function */
                $this->shell_function = $possible;
                break;

            }

        }

        if (! isset($_SERVER[ 'HTTP_HOST' ])) {

            $_SERVER[ 'HTTP_HOST' ] = '127.0.0.1';

        }

        /* set global config key; here, because it's needed for migration */
        if ($this->network) {

            $this->global_config_key = 'network';

        } else {

            $sitedomain = parse_url(get_option('siteurl'), PHP_URL_HOST);

            if ($_SERVER[ 'HTTP_HOST' ] != $sitedomain) {

                $this->errors[ 'domain_mismatch' ] = sprintf(__("Domain mismatch: the site domain configuration (%s) does not match the HTTP_HOST (%s) variable in PHP. Please fix the incorrect one, otherwise the plugin may not work as expected.", 'wc-mfpc'), $sitedomain, $_SERVER[ 'HTTP_HOST' ]);

            }

            $this->global_config_key = $_SERVER[ 'HTTP_HOST' ];

        }

        /* cache type possible values array */
        $this->select_cache_type = [
            'memcached' => __('PHP Memcached', 'wc-mfpc'),
        ];

        /* check for required functions / classes for the cache types */
        $this->valid_cache_type = [
            'memcached' => class_exists('Memcached') ? true : false,
        ];

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

        /* get current wp_cron schedules */
        $wp_schedules = wp_get_schedules();
        /* add 'null' to switch off timed precache */
        $schedules[ 'null' ] = __('do not use timed precache');

        foreach ($wp_schedules as $interval => $details) {

            $schedules[ $interval ] = $details[ 'display' ];

        }

        $this->select_schedules = $schedules;
    }

    /**
     * additional init, steps that needs the plugin options
     */
    public function plugin_post_init()
    {
        /* initiate backend */
        $this->backend = new Memcached($this->options);

        /* cache invalidation hooks */
        add_action('transition_post_status', [ &$this->backend, 'clear_ng' ], 10, 3);

        /* comments invalidation hooks */
        if ($this->options[ 'comments_invalidate' ]) {

            add_action('comment_post', [ &$this->backend, 'clear' ], 0);
            add_action('edit_comment', [ &$this->backend, 'clear' ], 0);
            add_action('trashed_comment', [ &$this->backend, 'clear' ], 0);
            add_action('pingback_post', [ &$this->backend, 'clear' ], 0);
            add_action('trackback_post', [ &$this->backend, 'clear' ], 0);
            add_action('wp_insert_comment', [ &$this->backend, 'clear' ], 0);

        }

        /* invalidation on some other ocasions as well */
        add_action('switch_theme', [ &$this->backend, 'clear' ], 0);
        add_action('deleted_post', [ &$this->backend, 'clear' ], 0);
        add_action('edit_post', [ &$this->backend, 'clear' ], 0);

        /* add filter for catching canonical redirects */
        if (WP_CACHE) {

            add_filter('redirect_canonical', 'wc_mfpc_redirect_callback', 10, 2);

        }

        /* add precache coldrun action */
        add_action(Data::precache_id, [ &$this, 'precache_coldrun' ]);

        /* link on to settings for plugins page */
        $settings_link = ' &raquo; <a href="' . $this->settings_link . '">' . __('WC-MFPC Settings', 'wc-mfpc') . '</a>';

        /* look for WP_CACHE */
        if (! WP_CACHE) {

            $this->errors[ 'no_wp_cache' ] = __("WP_CACHE is disabled. Without that, cache plugins, like this, will not work. Please add `define ( 'WP_CACHE', true );` to the beginning of wp-config.php.", 'wc-mfpc');

        }

        /* look for global settings array */
        if (! $this->global_saved) {

            $this->errors[ 'no_global_saved' ] = sprintf(__('This site was reached as %s ( according to PHP HTTP_HOST ) and there are no settings present for this domain in the WC-MFPC configuration yet. Please save the %s for the domain or fix the webserver configuration!', 'wc-mfpc'),
                $_SERVER[ 'HTTP_HOST' ], $settings_link
            );

        }

        /* look for writable acache file */
        if (file_exists($this->acache) && ! is_writable($this->acache)) {

            $this->errors[ 'no_acache_write' ] = sprintf(__('Advanced cache file (%s) is not writeable!<br />Please change the permissions on the file.', 'wc-mfpc'), $this->acache);

        }

        /* look for acache file */
        if (! file_exists($this->acache)) {

            $this->errors[ 'no_acache_saved' ] = sprintf(__('Advanced cache file is yet to be generated, please save %s', 'wc-mfpc'), $settings_link);

        }

        /* look for extensions that should be available */
        foreach ($this->valid_cache_type as $backend => $status) {

            if ($this->options[ 'cache_type' ] == $backend && ! $status) {

                $this->errors[ 'no_backend' ] = sprintf(__('%s cache backend activated but no PHP %s extension was found.<br />Please either use different backend or activate the module!', 'wc-mfpc'), $backend, $backend);

            }

        }

        $filtered_errors = apply_filters('wc_mfpc_post_init_errors_array', $this->errors);

        if ($filtered_errors) {

            if (php_sapi_name() != "cli") {

                foreach ($this->errors as $e => $msg) {

                    static::alert($msg, LOG_WARNING, $this->network);

                }

            }

        }
    }

    /**
     * display formatted alert message
     *
     * @param string  $msg     Error message
     * @param string  $error   "level" of error
     * @param boolean $network WordPress network or not, DEPRECATED
     *
     * @return bool
     */
    static public function alert($msg, $level = LOG_WARNING, $network = false)
    {
        if (empty($msg)) {

            return false;
        }

        switch ($level) {

            case LOG_ERR:
            case LOG_WARNING:
                $css = "error";
                break;
            default:
                $css = "updated";
                break;

        }

        $r = '<div class="' . $css . '"><p>' . sprintf(__('%s', 'PluginUtils'), $msg) . '</p></div>';

        if (version_compare(phpversion(), '5.3.0', '>=')) {

            add_action('admin_notices', function () use ($r) {
                echo $r;
            }, 10);

        } else {

            global $tmp;

            $tmp = $r;
            $f   = create_function('', 'global $tmp; echo $tmp;');

            add_action('admin_notices', $f);

        }
    }

    /**
     * activation hook function, to be extended
     */
    public function plugin_activate()
    {
        /* we leave this empty to avoid not detecting WP network correctly */
    }

    /**
     * deactivation hook function, to be extended
     */
    public function plugin_deactivate()
    {
        /* remove current site config from global config */
        $this->update_global_config(true);
    }

    /**
     * uninstall hook function, to be extended
     */
    public function plugin_uninstall($delete_options = true)
    {
        /* delete advanced-cache.php file */
        unlink($this->acache);

        /* delete site settings */
        if ($delete_options) {

            $this->plugin_options_delete();

        }
    }

    /**
     * admin panel, load plugin textdomain
     */
    public function plugin_load_textdomain()
    {
        load_plugin_textdomain('wc-mfpc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Select options field processor
     *
     * @param array $elements  Array to build <option> values of
     * @param mixed $current   The current active element
     * @param bool  $print     Is true, the options will be printed, otherwise the string will be returned
     *
     * @return mixed $opt      Prints or returns the options string
     */
    protected function print_select_options($elements, $current, $valid = false, $print = true)
    {
        if (is_array($valid)) {

            $check_disabled = true;

        } else {

            $check_disabled = false;

        }

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
     * run full-site precache
     */
    public function precache_coldrun()
    {
        /* container for links to precache, well be accessed by reference */
        $links = [];

        /* when plugin is  network wide active, we need to pre-cache for all link of all blogs */
        if ($this->network) {

            /* list all blogs */
            global $wpdb;

            $pfix      = empty ($wpdb->base_prefix) ? 'wp_' : $wpdb->base_prefix;
            $blog_list = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $pfix . "blogs ORDER BY blog_id", ''));

            foreach ($blog_list as $blog) {

                if ($blog->archived != 1 && $blog->spam != 1 && $blog->deleted != 1) {

                    /* get permalinks for this blog */
                    $this->precache_list_permalinks($links, $blog->blog_id);

                }

            }

        } else {

            /* no network, better */
            $this->precache_list_permalinks($links, false);

        }

        /* double check if we do have any links to pre-cache */
        if (! empty ($links)) {

            $this->precache($links);

        }
    }

    /**
     * gets all post-like entry permalinks for a site, returns values in passed-by-reference array
     *
     * @param      $links
     * @param bool $site
     */
    private function precache_list_permalinks(&$links, $site = false)
    {
        /* $post will be populated when running throught the posts */
        global $post;

        include_once(ABSPATH . "wp-load.php");
        /* if a site id was provided, save current blog and change to the other site */

        if ($site !== false) {

            $current_blog = get_current_blog_id();
            switch_to_blog($site);
            $url = $this->_site_url($site);
            //$url = get_blog_option ( $site, 'siteurl' );
            if (substr($url, -1) !== '/') {

                $url = $url . '/';

            }
            $links[ $url ] = true;

        }

        /* get all published posts */
        $args  = [
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];
        $posts = new \WP_Query($args);

        /* get all the posts, one by one  */
        while ($posts->have_posts()) {

            $posts->the_post();

            /* get the permalink for currently selected post */
            switch ($post->post_type) {

                case 'revision':
                case 'nav_menu_item':
                    break;
                case 'page':
                    $permalink = get_page_link($post->ID);
                    break;
                /*
                         * case 'post':
                            $permalink = get_permalink( $post->ID );
                            break;
                        */
                case 'attachment':
                    $permalink = get_attachment_link($post->ID);
                    break;
                default:
                    $permalink = get_permalink($post->ID);
                    break;

            }

            /* in case the bloglinks are relative links add the base url, site specific */
            $baseurl = empty($url) ? static::_site_url() : $url;

            if (! strstr($permalink, $baseurl)) {

                $permalink = $baseurl . $permalink;

            }

            /* collect permalinks */
            $links[ $permalink ] = true;
        }

        $this->backend->taxonomy_links($links);
        /* just in case, reset $post */
        wp_reset_postdata();

        /* switch back to original site if we navigated away */
        if ($site !== false) {

            switch_to_blog($current_blog);

        }
    }

    /**
     * read option; will handle network wide or standalone site options
     */
    public static function _site_url($site = '', $network = false)
    {
        if ($network && ! empty($site)) {
            $url = get_blog_option($site, 'siteurl');
        } else {
            $url = get_bloginfo('url');
        }

        return $url;
    }

    /**
     * generate cache entry for every available permalink, might be very-very slow,
     * therefore it starts a background process
     *
     * @param $links
     *
     * @return void
     */
    private function precache(&$links)
    {
        /* double check if we do have any links to pre-cache */
        if (! empty ($links) && ! $this->precache_running()) {

            $out = '<?php
                $links = ' . var_export($links, true) . ';

                echo "permalink\tgeneration time (s)\tsize ( kbyte )\n";
                
                foreach ( $links as $permalink => $dummy ) {
                
                    $starttime = explode ( " ", microtime() );
                    $starttime = $starttime[1] + $starttime[0];
    
                    $page = file_get_contents( $permalink );
                    $size = round ( ( strlen ( $page ) / 1024 ), 2 );
    
                    $endtime = explode ( " ", microtime() );
                    $endtime = round( ( $endtime[1] + $endtime[0] ) - $starttime, 2 );
    
                    echo $permalink . "\t" .  $endtime . "\t" . $size . "\n";
                    unset ( $page, $size, $starttime, $endtime );
                    sleep( 1 );
                    
                }
                
                unlink ( "' . $this->precache_phpfile . '" );
            ?>';

            file_put_contents($this->precache_phpfile, $out);
            /* call the precache worker file in the background */
            $shellfunction = $this->shell_function;
            $shellfunction('php ' . $this->precache_phpfile . ' >' . $this->precache_logfile . ' 2>&1 &');

        }
    }

    /**
     * check is precache is still ongoing
     *
     * @return bool
     */
    private function precache_running()
    {
        $return = false;

        /* if the precache file exists, it did not finish running as it should delete itself on finish */
        if (file_exists($this->precache_phpfile)) {

            $return = true;

        }

        return $return;
    }

    /**
     * UTILS
     */

    /**
     * @return null|Memcached
     */
    public function getBackend()
    {
        return $this->backend;
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
        $settings_link = '<a href="' . $this->settings_link . '">' . __('Settings', 'wc-mfpc') . '</a>';
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * @param $key
     *
     * @return bool|mixed
     */
    public function getoption($key)
    {
        return (empty($this->options[ $key ])) ? false : $this->options[ $key ];
    }

    /**
     * print value of an element from defaults array
     *
     * @param mixed $e Element index of $this->defaults array
     */
    protected function print_default($e)
    {
        _e('Default : ', 'wc-mfpc');
        $select = 'select_' . $e;
        if (@is_array($this->$select)) {
            $x = $this->$select;
            $this->print_var($x[ $this->defaults[ $e ] ]);
        } else {
            $this->print_var($this->defaults[ $e ]);
        }
    }

    /**
     * function to easily print a variable
     *
     * @param mixed   $var Variable to dump
     * @param boolean $ret Return text instead of printing if true
     *
     * @return mixed
     */
    protected function print_var($var, $ret = false)
    {
        if (@is_array($var) || @is_object($var) || @is_bool($var)) {
            $var = var_export($var, true);
        }
        if ($ret) {
            return $var;
        } else {
            echo $var;
        }
    }

}
