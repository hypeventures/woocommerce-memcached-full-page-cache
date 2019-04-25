<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Memcached
 *
 * @package InvincibleBrands\WcMfpc
 */
class Memcached
{

    const host_separator = ',';
    const port_separator = ':';

    /**
     * @var array
     */
    public $cookies = [];

    /**
     * @var null|\Memcached
     */
    protected $connection = null;

    /**
     * @var bool
     */
    protected $alive = false;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var array
     */
    protected $status = [];

    /**
     * @var array
     */
    protected $urimap = [];

    /**
     * Memcached constructor.
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        /* no config, nothing is going to work */
        if (empty ($config)) {

            return false;
        }
        $this->options = $config;
        /* these are the list of the cookies to look for when looking for logged in user */
        $this->cookies = [ 'comment_author_', 'wordpressuser_', 'wp-postpass_', 'wordpress_logged_in_' ];
        /* map the key with the predefined schemes */
        $ruser   = isset ($_SERVER[ 'REMOTE_USER' ]) ? $_SERVER[ 'REMOTE_USER' ] : '';
        $ruri    = isset ($_SERVER[ 'REQUEST_URI' ]) ? $_SERVER[ 'REQUEST_URI' ] : '';
        $rhost   = isset ($_SERVER[ 'HTTP_HOST' ]) ? $_SERVER[ 'HTTP_HOST' ] : '';
        $scookie = isset ($_COOKIE[ 'PHPSESSID' ]) ? $_COOKIE[ 'PHPSESSID' ] : '';
        if (isset($_SERVER[ 'HTTP_X_FORWARDED_PROTO' ]) && $_SERVER[ 'HTTP_X_FORWARDED_PROTO' ] == 'https') {
            $_SERVER[ 'HTTPS' ] = 'on';
        }
        $scheme       = (! empty($_SERVER[ 'HTTPS' ])) ? 'https' : 'http';
        $this->urimap = [
            '$scheme'           => $scheme,
            '$host'             => $rhost,
            '$request_uri'      => $ruri,
            '$remote_user'      => $ruser,
            '$cookie_PHPSESSID' => $scookie,
        ];
        /* split single line hosts entry */
        $this->set_servers();
        /* info level */
        error_log('init starting');
        /* call backend initiator based on cache type */
        $init = $this->_init();
        if (is_admin() && function_exists('add_filter')) {
            add_filter('wc_mfpc_clear_keys_array', function ($to_clear, $options) {
                $filtered_result = [];
                foreach ($to_clear as $link => $dummy) {
                    /* clear feeds, meta and data as well */
                    $filtered_result[ $options[ 'prefix_meta' ] . $link ]          = true;
                    $filtered_result[ $options[ 'prefix_data' ] . $link ]          = true;
                    $filtered_result[ $options[ 'prefix_meta' ] . $link . 'feed' ] = true;
                    $filtered_result[ $options[ 'prefix_data' ] . $link . 'feed' ] = true;
                }

                return $filtered_result;
            }, 10, 2
            );
        }
    }

    /**
     * split hosts string to backend servers
     */
    protected function set_servers()
    {
        if (empty ($this->options[ 'hosts' ])) {
            return false;
        }
        /* replace servers array in config according to hosts field */
        $servers              = explode(self::host_separator, $this->options[ 'hosts' ]);
        $options[ 'servers' ] = [];
        foreach ($servers as $snum => $sstring) {

            if (stristr($sstring, 'unix://')) {
                $host = str_replace('unix:/', '', $sstring);
                $port = 0;
            } else {
                $separator = strpos($sstring, self::port_separator);
                $host      = substr($sstring, 0, $separator);
                $port      = substr($sstring, $separator + 1);
            }
            $this->options[ 'servers' ][ $sstring ] = [
                'host' => $host,
                'port' => $port,
            ];
        }
    }

    /**
     * @return bool
     */
    protected function _init()
    {
        /* Memcached class does not exist, Memcached extension is not available */
        if (! class_exists('Memcached')) {

            error_log(' Memcached extension missing, wc-mfpc will not be able to function correctly!', LOG_WARNING);

            return false;
        }

        /* check for existing server list, otherwise we cannot add backends */
        if (empty ($this->options[ 'servers' ]) && ! $this->alive) {

            error_log("Memcached servers list is empty, init failed", LOG_WARNING);

            return false;
        }

        /* check is there's no backend connection yet */
        if ($this->connection === null) {

            $this->connection = new \Memcached();

            /* use binary and not compressed format, good for nginx and still fast */
            $this->connection->setOption(\Memcached::OPT_COMPRESSION, false);

            if ($this->options[ 'memcached_binary' ]) {

                $this->connection->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);

            }

            if (version_compare(phpversion('memcached'), '2.0.0', '>=') && ini_get('memcached.use_sasl') == 1 && isset($this->options[ 'authpass' ]) && ! empty($this->options[ 'authpass' ]) && isset($this->options[ 'authuser' ]) && ! empty($this->options[ 'authuser' ])) {

                $this->connection->setSaslAuthData($this->options[ 'authuser' ], $this->options[ 'authpass' ]);

            }

        }

        /* check if initialization was success or not */
        if ($this->connection === null) {

            error_log('error initializing Memcached PHP extension, exiting');

            return false;
        }

        /* check if we already have list of servers, only add server(s) if it's not already connected */
        $servers_alive = [];

        if (! empty ($this->status)) {

            $servers_alive = $this->connection->getServerList();

            /* create check array if backend servers are already connected */
            if (! empty ($servers)) {

                foreach ($servers_alive as $skey => $server) {

                    $skey                   = $server[ 'host' ] . ":" . $server[ 'port' ];
                    $servers_alive[ $skey ] = true;

                }

            }

        }

        /* adding servers */
        foreach ($this->options[ 'servers' ] as $server_id => $server) {

            /* only add servers that does not exists already  in connection pool */
            if (! @array_key_exists($server_id, $servers_alive)) {

                $this->connection->addServer($server[ 'host' ], $server[ 'port' ]);
                error_log($server_id . ' added');

            }

        }

        /* backend is now alive */
        $this->alive = true;
        $this->_status();
    }

    /**
     * sets current backend alive status for Memcached servers
     */
    protected function _status()
    {
        /* server status will be calculated by getting server stats */
        error_log("checking server statuses");
        /* get server list from connection */
        $servers = $this->connection->getServerList();
        foreach ($servers as $server) {
            $server_id = $server[ 'host' ] . self::port_separator . $server[ 'port' ];
            /* reset server status to offline */
            $this->status[ $server_id ] = 0;
            if ($this->connection->set('wc-mfpc', time())) {
                error_log(sprintf('%s server is up & running', $server_id));
                $this->status[ $server_id ] = 1;
            }
        }
    }

    /**
     * build key to make requests with
     *
     * @param string $prefix       prefix to add to prefix
     * @param array  $customUrimap to override defaults
     *
     * @return string
     */
    public function key($prefix, $customUrimap = null)
    {
        $urimap   = $customUrimap ?: $this->urimap;
        $key_base = self::map_urimap($urimap, $this->options[ 'key' ]);
        if ((isset($this->options[ 'hashkey' ]) && $this->options[ 'hashkey' ] == true) || $this->options[ 'cache_type' ] == 'redis') {
            $key_base = sha1($key_base);
        }
        $key = $prefix . $key_base;
        error_log(sprintf('original key configuration: %s', $this->options[ 'key' ]));
        error_log(sprintf('setting key for: %s', $key_base));
        error_log(sprintf('setting key to: %s', $key));

        return $key;
    }

    /**
     * @param array  $urimap
     * @param string $subject
     *
     * @return mixed
     */
    public static function map_urimap($urimap, $subject)
    {
        return str_replace(array_keys($urimap), $urimap, $subject);
    }

    /**
     * public get function, transparent proxy to internal function based on backend
     *
     * @param string $key Cache key to get value for
     *
     * @return mixed False when entry not found or entry value on success
     */
    public function get(&$key)
    {
        /* look for backend aliveness, exit on inactive backend */
        if (! $this->is_alive()) {
            error_log('WARNING: Backend offline');

            return false;
        }
        /* log the current action */
        error_log(sprintf('GET %s', $key));
        $result = $this->_get($key);
        if ($result === false || $result === null) {
            error_log(sprintf('failed to get entry: %s', $key));
        }

        return $result;
    }

    /**
     * function to check backend aliveness
     *
     * @return boolean true if backend is alive, false if not
     */
    protected function is_alive()
    {
        if (! $this->alive) {
            error_log("backend is not active, exiting function " . __FUNCTION__, LOG_WARNING);

            return false;
        }

        return true;
    }

    /**
     * get function for Memcached backend
     *
     * @param string $key Key to get values for
     *
     * @return mixed
     */
    protected function _get(&$key)
    {
        return $this->connection->get($key);
    }

    /**
     * public set function, transparent proxy to internal function based on backend
     *
     * @param string $key  Cache key to set with ( reference only, for speed )
     * @param mixed  $data Data to set ( reference only, for speed )
     *
     * @return mixed $result status of set function
     */
    public function set(&$key, &$data, $expire = false)
    {
        /* look for backend aliveness, exit on inactive backend */
        if (! $this->is_alive()) {
            return false;
        }
        /* log the current action */
        error_log(sprintf('set %s expiration time: %s', $key, $this->options[ 'expire' ]));
        /* expiration time based is based on type from now on */
        /* fallback */
        if ($expire === false) {
            $expire = empty ($this->options[ 'expire' ]) ? 0 : $this->options[ 'expire' ];
        }
        if ((is_home() || is_feed()) && isset($this->options[ 'expire_home' ])) {
            $expire = (int) $this->options[ 'expire_home' ];
        } elseif ((is_tax() || is_category() || is_tag() || is_archive()) && isset($this->options[ 'expire_taxonomy' ])) {
            $expire = (int) $this->options[ 'expire_taxonomy' ];
        }
        /* log the current action */
        error_log(sprintf('SET %s', $key));
        /* proxy to internal function */
        $result = $this->_set($key, $data, $expire);
        /* check result validity */
        if ($result === false || $result === null) {
            error_log(sprintf('failed to set entry: %s', $key), LOG_WARNING);
        }

        return $result;
    }

    /**
     * Set function for Memcached backend
     *
     * @param string $key  Key to set with
     * @param mixed  $data Data to set
     *
     * @return bool
     */
    protected function _set(&$key, &$data, &$expire)
    {
        $result = $this->connection->set($key, $data, $expire);
        /* if storing failed, log the error code */
        if ($result === false) {
            $code = $this->connection->getResultCode();
            error_log(sprintf('unable to set entry: %s', $key));
            error_log(sprintf('Memcached error code: %s', $code));
            //throw new Exception ( 'Unable to store Memcached entry ' . $key .  ', error code: ' . $code );
        }

        return $result;
    }

    /**
     * "Next generation clean" ... what the hell that might be ...
     * ToDo: Check if this can be removed.
     *
     * @param $new_status
     * @param $old_status
     * @param $post
     */
    public function clear_ng($new_status, $old_status, $post)
    {
        $this->clear($post->ID);
    }

    /**
     * public get function, transparent proxy to internal function based on backend
     *
     * @param string  $post_id ID of post to invalidate
     * @param boolean $force   Force flush cache
     *
     * @return bool
     */
    public function clear($post_id = false, $force = false)
    {
        /* look for backend aliveness, exit on inactive backend */
        if (! $this->is_alive()) {
            return false;
        }
        /* exit if no post_id is specified */
        if (empty ($post_id) && $force === false) {
            error_log('not clearing unidentified post', LOG_WARNING);

            return false;
        }
        /* if invalidation method is set to full, flush cache */
        if (($this->options[ 'invalidation_method' ] === 0 || $force === true)) {
            /* log action */
            error_log('flushing cache');
            /* proxy to internal function */
            $result = $this->_flush();
            if ($result === false) {
                error_log('failed to flush cache', LOG_WARNING);
            }

            return $result;
        }
        /* storage for entries to clear */
        $to_clear = [];
        /* clear taxonomies if settings requires it */
        if ($this->options[ 'invalidation_method' ] == 2) {
            /* this will only clear the current blog's entries */
            $this->taxonomy_links($to_clear);
        }
        /* clear pasts index page if settings requires it */
        if ($this->options[ 'invalidation_method' ] == 3) {
            $posts_page_id = get_option('page_for_posts');
            $post_type     = get_post_type($post_id);
            if ($post_type === 'post' && $posts_page_id != $post_id) {
                $this->clear($posts_page_id, $force);
            }
        }
        /* if there's a post id pushed, it needs to be invalidated in all cases */
        if (! empty ($post_id)) {

            /* need permalink functions */
            if (! function_exists('get_permalink')) {
                include_once(ABSPATH . 'wp-includes/link-template.php');
            }
            /* get permalink */
            $permalink = get_permalink($post_id);
            /* no path, don't do anything */
            if (empty($permalink) && $permalink != false) {
                error_log(sprintf('unable to determine path from Post Permalink, post ID: %s', $post_id), LOG_WARNING);

                return false;
            }
            /*
             * It is possible that post/page is paginated with <!--nextpage-->
             * Wordpress doesn't seem to expose the number of pages via API.
             * So let's just count it.
             */
            $content_post    = get_post($post_id);
            $content         = $content_post->post_content;
            $number_of_pages = 1 + (int) preg_match_all('/<!--nextpage-->/', $content, $matches);
            $current_page_id = '';
            do {
                /* urimap */
                $urimap                       = self::parse_urimap($permalink, $this->urimap);
                $urimap[ '$request_uri' ]     = $urimap[ '$request_uri' ] . ($current_page_id ? $current_page_id . '/' : '');
                $clear_cache_key              = self::map_urimap($urimap, $this->options[ 'key' ]);
                $to_clear[ $clear_cache_key ] = true;
                $current_page_id              = 1 + (int) $current_page_id;
            } while ($number_of_pages > 1 && $current_page_id <= $number_of_pages);
        }
        /* Hook to custom clearing array. */
        $to_clear = apply_filters('wc_mfpc_to_clear_array', $to_clear, $post_id);
        /* run clear */
        $this->clear_keys($to_clear);
    }

    /**
     * Flush memcached entries
     */
    protected function _flush()
    {
        return $this->connection->flush();
    }

    /**
     * to collect all permalinks of all taxonomy terms used in invalidation & precache
     *
     * @param array &$links Passed by reference array that has to be filled up with the links
     * @param mixed $site   Site ID or false; used in WordPress Network
     */
    public function taxonomy_links(&$links, $site = false)
    {

        if ($site !== false) {
            $current_blog = get_current_blog_id();
            switch_to_blog($site);
            $url = get_blog_option($site, 'siteurl');
            if (substr($url, -1) !== '/') {
                $url = $url . '/';
            }
            $links[ $url ] = true;
        }
        /* we're only interested in public taxonomies */
        $args = [
            'public' => true,
        ];
        /* get taxonomies as objects */
        $taxonomies = get_taxonomies($args, 'objects');
        if (! empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                /* reset array, just in case */
                $terms = [];
                /* get all the terms for this taxonomy, only if not empty */
                $sargs = [
                    'hide_empty'   => true,
                    'fields'       => 'all',
                    'hierarchical' => false,
                ];
                $terms = get_terms($taxonomy->name, $sargs);
                if (! empty ($terms)) {
                    foreach ($terms as $term) {

                        /* skip terms that have no post associated and somehow slipped
                         * throught hide_empty */
                        if ($term->count == 0) {
                            continue;
                        }
                        /* get the permalink for the term */
                        $link = get_term_link($term->slug, $taxonomy->name);
                        /* add to container */
                        $links[ $link ] = true;
                        /* remove the taxonomy name from the link, lots of plugins remove this for SEO, it's better to include them than leave them out in worst case, we cache some 404 as well
                        */
                        $link = str_replace('/' . $taxonomy->rewrite[ 'slug' ], '', $link);
                        /* add to container */
                        $links[ $link ] = true;
                    }
                }
            }
        }
        /* switch back to original site if we navigated away */
        if ($site !== false) {
            switch_to_blog($current_blog);
        }
    }

    /**
     * @param string $uri
     * @param mixed  $default_urimap
     *
     * @return array
     */
    public static function parse_urimap($uri, $default_urimap = null)
    {
        $uri_parts = parse_url($uri);
        $uri_map   = [
            '$scheme'      => $uri_parts[ 'scheme' ],
            '$host'        => $uri_parts[ 'host' ],
            '$request_uri' => $uri_parts[ 'path' ],
        ];
        if (is_array($default_urimap)) {
            $uri_map = array_merge($default_urimap, $uri_map);
        }

        return $uri_map;
    }

    /**
     * unset entries by key
     *
     * @param array $keys
     */
    public function clear_keys($keys)
    {
        $to_clear = apply_filters('wc_mfpc_clear_keys_array', $keys, $this->options);
        $this->_clear($to_clear);
    }

    /**
     * Removes entry from Memcached or flushes Memcached storage
     *
     * @param mixed $keys String / array of string of keys to delete entries with
     */
    protected function _clear(&$keys)
    {

        /* make an array if only one string is present, easier processing */
        if (! is_array($keys)) {
            $keys = [ $keys => true ];
        }
        foreach ($keys as $key => $dummy) {
            $kresult = $this->connection->delete($key);
            if ($kresult === false) {
                $code = $this->connection->getResultCode();
                error_log(sprintf('unable to delete entry: %s', $key));
                error_log(sprintf('Memcached error code: %s', $code));
            } else {
                error_log(sprintf('entry deleted: %s', $key));
            }
        }
    }

    /**
     * clear cache triggered by new comment
     *
     * @param int              $comment_id     Comment ID
     * @param null|\WP_Comment $comment_object The whole comment object ?
     *
     * @return bool
     */
    public function clear_by_comment($comment_id = 0, $comment_object = null)
    {
        if (empty($comment_id)) {
            return false;
        }
        $comment = get_comment($comment_id);
        $post_id = $comment->comment_post_ID;
        if (! empty($post_id)) {
            $this->clear($post_id);
        }
        unset ($comment);
        unset ($post_id);
    }

    /**
     * get backend aliveness
     *
     * @return bool|array Array of configured servers with aliveness value
     */
    public function status()
    {

        /* look for backend aliveness, exit on inactive backend */
        if (! $this->is_alive()) {

            return false;

        }

        $this->_status();

        return $this->status;
    }

    /**
     * get current array of servers
     * ToDo: Remove - this seems to be unused...
     *
     * @return array Server list in current config
     */
    public function get_servers()
    {
        $r = isset ($this->options[ 'servers' ]) ? $this->options[ 'servers' ] : '';

        return $r;
    }

}
