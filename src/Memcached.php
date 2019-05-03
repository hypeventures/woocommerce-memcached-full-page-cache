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
    protected $config = [];

    /**
     * @var array
     */
    protected $servers = [];

    /**
     * @var array
     */
    protected $status = [];

    /**
     * @var array
     */
    protected $uriMap = [];

    /**
     * Memcached constructor.
     *
     * @param array $config
     *
     * @return bool|void
     */
    public function __construct($config = [])
    {
        if (empty ($config)) {

            return false;
        }

        $this->config = $config;
        $this->setUriMap();
        $this->setServers();
        $this->init();

        /*
         * Todo: Evaluate if using a filter can be avoided OR if 3rd party support should be granted via the filter.
         */
        if (function_exists('is_admin') && is_admin() && function_exists('add_filter')) {

            add_filter('wc_mfpc_clear_keys_array', [ &$this, 'getKeys' ], 10, 2);

        }
    }

    /**
     * Sets the uriMap array according to $_SERVER contents.
     * 
     * @return void
     */
    protected function setUriMap()
    {
        /*
         * Handle typical LoadBalancer scenarios like on AWS.
         */
        if (
            empty($_SERVER[ 'HTTPS' ])
            && isset($_SERVER[ 'HTTP_X_FORWARDED_PROTO' ])
            && $_SERVER[ 'HTTP_X_FORWARDED_PROTO' ] === 'https'
        ) {

            $_SERVER[ 'HTTPS' ] = 'on';

        }

        $this->uriMap = [
            '$scheme'           => ! empty($_SERVER[ 'HTTPS' ]) ? 'https' : 'http',
            '$host'             => isset($_SERVER[ 'HTTP_HOST' ]) ? $_SERVER[ 'HTTP_HOST' ] : '',
            '$request_uri'      => isset($_SERVER[ 'REQUEST_URI' ]) ? $_SERVER[ 'REQUEST_URI' ] : '',
            '$remote_user'      => isset($_SERVER[ 'REMOTE_USER' ]) ? $_SERVER[ 'REMOTE_USER' ] : '',
            '$cookie_PHPSESSID' => isset($_COOKIE[ 'PHPSESSID' ]) ? $_COOKIE[ 'PHPSESSID' ] : '',
        ];
    }

    /**
     * Returns an array which contains 4 keys for each permalink in the array $toClear.
     *
     * @param array $toClear [ 'permalink' => true, ]
     * @param array $config
     *
     * @return array
     */
    public function getKeys($toClear, $config) {

        $result = [];

        foreach ($toClear as $link => $dummy) {

            $result[ $config[ 'prefix_data' ] . $link ]          = true;
            $result[ $config[ 'prefix_meta' ] . $link ]          = true;
            $result[ $config[ 'prefix_data' ] . $link . 'feed' ] = true;
            $result[ $config[ 'prefix_meta' ] . $link . 'feed' ] = true;

        }

        return $result;
    }

    /**
     * Split hosts strings into server array.
     *
     * @return void
     */
    protected function setServers()
    {
        if (empty($this->config[ 'hosts' ])) {

            return;
        }

        $servers = explode(self::host_separator, $this->config[ 'hosts' ]);

        foreach ($servers as $server) {

            if (stristr($server, 'unix://')) {

                $host = str_replace('unix:/', '', $server);
                $port = 0;

            } else {

                $separator = strpos($server, self::port_separator);
                $host      = substr($server, 0, $separator);
                $port      = substr($server, $separator + 1);

            }

            $this->servers[ $server ] = [
                'host' => $host,
                'port' => $port,
            ];

        }
    }

    /**
     * Initializes Memcached.
     *
     * @todo Evaluate to split init() further up and create connect() & addServers()
     *
     * @return void
     */
    protected function init()
    {
        /*
         * Abort if PHP Memcached extension is not available.
         */
        if (! class_exists('Memcached')) {

            error_log(' Memcached extension missing, wc-mfpc will not be able to function correctly!', LOG_WARNING);

            return;
        }

        /*
         * Abort if server list is empty.
         */
        if (empty ($this->servers) && ! $this->alive) {

            error_log("Memcached servers list is empty, init failed", LOG_WARNING);

            return;
        }

        /*
         * Initialize connection in case there's none yet.
         */
        if ($this->connection === null) {

            /*
             * Use binary protocol & no compression if possible => good for nginx and still fast.
             */
            $this->connection = new \Memcached();
            $this->connection->setOption(\Memcached::OPT_COMPRESSION, false);

            if (! empty($this->config[ 'memcached_binary' ])) {

                $this->connection->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);

            }

            if (! empty($this->config[ 'authpass' ]) && ! empty($this->config[ 'authuser' ])) {

                $this->connection->setSaslAuthData($this->config[ 'authuser' ], $this->config[ 'authpass' ]);

            }

        }

        /* check if initialization was success or not */
        if ($this->connection === null) {

            error_log('error initializing Memcached PHP extension, exiting');

            return;
        }

        /* check if we already have a list of servers, only add server(s) if it's not already connected */
        $servers_alive = [];

        if (! empty ($this->status)) {

            $servers_alive = $this->connection->getServerList();

            /* create check array if backend servers are already connected */
            if (! empty ($servers_alive)) {

                foreach ($servers_alive as $skey => $server) {

                    $skey                   = $server[ 'host' ] . ":" . $server[ 'port' ];
                    $servers_alive[ $skey ] = true;

                }

            }

        }

        /* adding servers */
        foreach ($this->servers as $server_id => $server) {

            /* only add servers that does not exists already  in connection pool */
            if (! @array_key_exists($server_id, $servers_alive)) {

                $this->connection->addServer($server[ 'host' ], $server[ 'port' ]);
                error_log($server_id . ' added');

            }

        }

        /* backend is now alive */
        $this->alive = true;
    }

    /**
     * Sets current backend alive status for Memcached servers.
     *
     * @todo Evaluate merging _status() into status()
     */
    protected function _status()
    {
        error_log("checking server statuses");

        /*
         * Get the server list from connection.
         */
        $servers = $this->connection->getServerList();
        $changed = false;

        foreach ($servers as $i => $server) {

            $server_id = $server[ 'host' ] . self::port_separator . $server[ 'port' ];

            /*
             * reset server status to offline
             */
            $this->status[ $server_id ] = 0;

            /*
             * Instantiate a new Memcached connection for this server to test it independently.
             */
            $memcached = new \Memcached();
            $memcached->addServer($server[ 'host' ], $server[ 'port' ]);

            if ($memcached->set('wc-mfpc', time())) {

                error_log(sprintf('%s server is up & running', $server_id));
                $this->status[ $server_id ] = 1;

            } else {

                /*
                 * If the server did not respond remove it from the list.
                 */
                unset($servers[ $i ]);
                $changed = true;

            }

            unset($memcached);

        }

        /*
         * If there are indeed servers which do not respond, remove them from the pool.
         */
        if ($changed) {

            $this->connection->resetServerList();
            $this->connection->addServers($servers);

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
        $uriMap   = $customUrimap ?: $this->uriMap;
        $key_base = self::mapUriMap($uriMap, $this->config[ 'key' ]);
        $key      = $prefix . $key_base;

        error_log(sprintf('original key configuration: %s', $this->config[ 'key' ]));
        error_log(sprintf('setting key for: %s', $key_base));
        error_log(sprintf('setting key to: %s', $key));

        return $key;
    }

    /**
     * @param array  $uriMap
     * @param string $subject
     *
     * @return mixed
     */
    public static function mapUriMap($uriMap, $subject)
    {
        return str_replace(array_keys($uriMap), $uriMap, $subject);
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

        if (empty($result)) {

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
     * @param string $key     Cache key to set with ( reference only, for speed )
     * @param mixed  $data    Data to set ( reference only, for speed )
     * @param mixed  $expire
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
        error_log(sprintf('set %s expiration time: %s', $key, $this->config[ 'expire' ]));

        /* expiration time based is based on type from now on */
        /* fallback */
        if ($expire === false) {

            $expire = empty($this->config[ 'expire' ]) ? 0 : (int) $this->config[ 'expire' ];

        }

        if ((is_home() || is_feed()) && isset($this->config[ 'expire_home' ])) {

            $expire = (int) $this->config[ 'expire_home' ];

        } elseif ((is_tax() || is_category() || is_tag() || is_archive()) && isset($this->config[ 'expire_taxonomy' ])) {

            $expire = (int) $this->config[ 'expire_taxonomy' ];

        }

        /* log the current action */
        error_log(sprintf('SET %s', $key));

        /* proxy to internal function */
        $result = $this->_set($key, $data, $expire);

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

            error_log(sprintf('Unable to set entry: %s', $key));
            error_log(sprintf('Memcached error code: %s', $code));

        }

        return $result;
    }

    /**
     * "Next generation clean" ... what the hell that might be ...
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
     * @param int     $post_id ID of post to invalidate
     * @param boolean $force   Force flush cache
     *
     * @return bool
     */
    public function clear($post_id = 0, $force = false)
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
        if (($this->config[ 'invalidation_method' ] === 0 || $force === true)) {

            /* log action */
            error_log('flushing cache');

            /* proxy to internal function */
            $result = $this->flush();

            if ($result === false) {

                error_log('failed to flush cache', LOG_WARNING);

            }

            return $result;
        }

        /* storage for entries to clear */
        $to_clear = [];

        /* clear taxonomies if settings requires it */
        if ($this->config[ 'invalidation_method' ] == 2) {

            /* this will only clear the current blog's entries */
            $this->getTaxonomyLinks($to_clear);

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
                /* uriMap */
                $uriMap                       = self::parseUriMap($permalink, $this->uriMap);
                $uriMap[ '$request_uri' ]     = $uriMap[ '$request_uri' ] . ($current_page_id ? $current_page_id . '/' : '');
                $clear_cache_key              = self::mapUriMap($uriMap, $this->config[ 'key' ]);
                $to_clear[ $clear_cache_key ] = true;
                $current_page_id              = 1 + (int) $current_page_id;

            } while ($number_of_pages > 1 && $current_page_id <= $number_of_pages);

        }

        /* Hook to custom clearing array. */
        $to_clear = apply_filters('wc_mfpc_to_clear_array', $to_clear, $post_id);

        /* run clear */
        return $this->clear_keys($to_clear);
    }

    /**
     * Flush memcached entries
     */
    public function flush()
    {
        return $this->connection->flush();
    }

    /**
     * Collects all permalinks of all taxonomy terms used in invalidation
     *
     * @param array &$links  Passed by reference array that has to be filled up with the links
     */
    public function getTaxonomyLinks(&$links)
    {
        $taxonomies = get_taxonomies([
            'public' => true,
        ], 'objects');

        if (empty($taxonomies)) {

            return;
        }

        foreach ($taxonomies as $taxonomy) {

            $terms = get_terms([
                'taxonomy'     => $taxonomy->name,
                'hide_empty'   => true,
                'fields'       => 'all',
                'hierarchical' => false,
            ]);

            if (empty ($terms)) {

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
                 * include them than leave them out in worst case, we cache some 404 as well
                 */
                $link           = str_replace('/' . $taxonomy->rewrite[ 'slug' ], '', $link);
                $links[ $link ] = true;

            }

        }
    }

    /**
     * @param string $uri
     * @param mixed  $default_uriMap
     *
     * @return array
     */
    public static function parseUriMap($uri, $default_uriMap = null)
    {
        $uri_parts = parse_url($uri);
        $uri_map   = [
            '$scheme'      => $uri_parts[ 'scheme' ],
            '$host'        => $uri_parts[ 'host' ],
            '$request_uri' => $uri_parts[ 'path' ],
        ];

        if (is_array($default_uriMap)) {

            $uri_map = array_merge($default_uriMap, $uri_map);

        }

        return $uri_map;
    }

    /**
     * unset entries by key
     *
     * @param array $keys
     *
     * @return bool
     */
    public function clear_keys($keys)
    {
        $to_clear = apply_filters('wc_mfpc_clear_keys_array', $keys, $this->config);

        return $this->_clear($to_clear);
    }

    /**
     * Removes entry from Memcached or flushes Memcached storage
     *
     * @todo Evaluate merging _clear() into clear()
     *
     * @param mixed $keys String / array of string of keys to delete entries with
     *
     * @return bool
     */
    protected function _clear(&$keys)
    {
        $result = false;

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

                $result = true;

                error_log(sprintf('entry deleted: %s', $key));

            }

        }

        return $result;
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

}
