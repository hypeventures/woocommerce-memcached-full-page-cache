<?php
/*
    Copyright 2019 Achim Galeski ( achim@invinciblebrands.com )

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
    public $connection = null;

    /**
     * @var bool
     */
    public $alive = false;

    /**
     * @var Config|array
     */
    protected $config = [];

    /**
     * @var array
     */
    public $servers = [];

    /**
     * @var array
     */
    public $status = [];

    /**
     * @var array
     */
    public $uriMap = [];

    /**
     * Memcached constructor.
     *
     * @param Config|array $config
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

            error_log('Memcached extension missing, wc-mfpc will not be able to function correctly!', LOG_WARNING);

            return;
        }

        /*
         * Abort if server list is empty.
         */
        if (empty ($this->servers) && ! $this->alive) {

            error_log("Memcached servers list is empty, init failed.", LOG_WARNING);

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

            error_log('Error initializing Memcached PHP extension, exiting.');

            return;
        }

        /* check if we already have a list of servers, only add server(s) if it's not already connected */
        $servers_alive = [];

        if (! empty ($this->status)) {

            $servers_alive = $this->connection->getServerList();

            /* create check array if memcached servers are already connected */
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

        /* memcached is now alive */
        $this->alive = true;
    }

    /**
     * Returns an array which contains 4 keys for each permalink in the array $toClear.
     *
     * @param array $toClear [ 'permalink' => true, ]
     * @param Config|array $config
     *
     * @return array
     */
    public function getKeys($toClear, $config) {

        $result = [];

        #error_log('getKeys $toClear: ' . var_export($toClear, true));

        foreach ($toClear as $link => $dummy) {

            $result[ $config[ 'prefix_data' ] . $link ]          = true;
            $result[ $config[ 'prefix_meta' ] . $link ]          = true;
            $result[ $config[ 'prefix_data' ] . $link . 'feed' ] = true;
            $result[ $config[ 'prefix_meta' ] . $link . 'feed' ] = true;

        }

        #error_log('getKeys $result: ' . var_export($result, true));

        return $result;
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

        #error_log(sprintf('original key configuration: %s', $this->config[ 'key' ]));
        #error_log(sprintf('setting key for: %s', $key_base));
        #error_log(sprintf('setting key to: %s', $key));

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
     * public get function, transparent proxy to internal function based on memcached
     *
     * @param string $key Cache key to get value for
     *
     * @return mixed     False when entry not found or entry value on success
     */
    public function get(&$key)
    {
        if (! $this->isAlive()) {

            return false;
        }

        #error_log(sprintf('GET %s', $key));

        $result = $this->connection->get($key);;

        if (empty($result)) {

            error_log(sprintf('failed to get entry: %s', $key));

        }

        return $result;
    }

    /**
     * function to check memcached aliveness
     *
     * @return bool  True, if memcached is alive, false in case it's not
     */
    public function isAlive()
    {
        if (! $this->alive) {

            error_log("Memcached is not active, exiting function " . __FUNCTION__, LOG_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Sets key and data in Memcached with expiration.
     * Contains Hook: 'wc_mfpc_custom_expire' to customize expiration time.
     *
     * @param string $key     Cache key to set with ( reference only, for speed )
     * @param mixed  $data    Data to set ( reference only, for speed )
     *
     * @return mixed $result status of set function
     */
    public function set(&$key, &$data)
    {
        if (! $this->isAlive()) {

            return false;
        }

        #error_log('SET ' . $key);

        $expire = empty($this->config[ 'expire' ]) ? 0 : (int) $this->config[ 'expire' ];

        /**
         * Filter to enable customization of expire time.
         * Allows 3rd party Developers to change the expiration time based on page type etc.
         *
         * @param int $expire  Integer to be set as "Expires:" header.
         *                     Default value is the expire setting, which you can set at the Plugins setting page.
         *
         * @return int
         */
        $expire = (int) apply_filters('wc_mfpc_custom_expire', $expire);

        $result = $this->connection->set($key, $data, $expire);

        return $result;
    }

    /**
     * Flush memcached entries
     */
    public function flush()
    {
        return $this->connection->flush();
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
        if (empty($keys)) {

            return false;
        }

        $keys   = $this->getKeys($keys, $this->config);
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
     * Sets the status of each server in an array
     *
     * @todo Test if changing of the server list is really necessary. It might increase Memcached server requests.
     *
     * @return bool|array $this->status  Array of configured servers with aliveness value
     */
    public function status()
    {
        if (! $this->isAlive()) {

            return false;
        }

        $changed = false;
        $servers = $this->connection->getServerList();

        foreach ($servers as $i => $server) {

            $server_id                  = $server[ 'host' ] . self::port_separator . $server[ 'port' ];
            $this->status[ $server_id ] = 0;

            /*
             * Instantiate a new Memcached connection for this server to test it independently.
             */
            $memcached = new \Memcached();
            $memcached->addServer($server[ 'host' ], $server[ 'port' ]);

            if ($memcached->set('wc-mfpc', time())) {

                $this->status[ $server_id ] = 1;

            } else {

                unset($servers[ $i ]);
                $changed = true;

            }

            unset($memcached);

        }

        /*
         * If there are indeed servers which do not respond, remove them from the pool.
         */
        if ($changed && ! empty($servers)) {

            $this->connection->resetServerList();
            $this->connection->addServers($servers);

        }

        return $this->status;
    }

}
