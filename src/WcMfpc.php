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
     * @var array
     */
    protected $options = [];

    /**
     * @var array|mixed
     */
    protected $defaults = [];

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
     * @var bool
     */
    private $shell_function = false;

    /**
     * @var null|Memcached
     */
    public $backend = null;

    /**
     * WcMfpc constructor.
     */
    public function __construct()
    {
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
        register_activation_hook($this->plugin_file, [ &$this, 'plugin_activate' ]);
        register_deactivation_hook($this->plugin_file, [ &$this, 'plugin_deactivate' ]);

        if (is_admin()) {

            $admin = new Admin();
            $admin->setHooks();
            $admin->plugin_options_read();
            $admin->plugin_pre_init();

        }

        /* setup plugin, plugin specific setup functions that need options */
        $this->plugin_post_init();
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
        if (! empty($this->options[ 'comments_invalidate' ])) {

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
    }

    /**
     * activation hook function, to be extended
     */
    public function plugin_activate()
    {
        /* we leave this empty to avoid not detecting WP network correctly */
    }

    /**
     * Removes current site config from global config on deactivation.
     */
    public function plugin_deactivate()
    {
        $admin = new Admin();
        /* remove current site config from global config */
        $admin->update_global_config(true);
    }

    /**
     * admin panel, load plugin textdomain
     */
    public function plugin_load_textdomain()
    {
        load_plugin_textdomain('wc-mfpc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
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
     *
     * @param string $site
     * @param bool   $network
     *
     * @return mixed|string
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
