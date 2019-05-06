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
        $this->setServers();
        $this->init();
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

        $this->alive = true;
    }

    /**
     * Builds and returns the current request URL which should equal the permalinks.
     *
     * @return string
     */
    public function buildUrl()
    {
        $scheme = 'http://';

        if (isset($_SERVER[ 'HTTPS' ]) && strtolower($_SERVER[ 'HTTPS' ]) === 'on') {

            $scheme = 'https://';

        }

        $url = $scheme . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ];

        /**
         * Hook to customize the page url which will be used as part of the key.
         *
         * @param string $url     Url string that was set by default.
         * @param string $scheme  Either 'https://' or 'http://' depending on $_SERVER[ 'HTTPS' ].
         *
         * @return string $url
         */
        return (string) apply_filters('wc_mfpc_custom_build_url', $url, $scheme);
    }

    /**
     * Get the cache key for a given permalink and data type.
     *
     * @param string $permalink  The permalink of the page in question.
     * @param string $type       The cache data type. Either 'data' or 'meta'. Default: 'data'
     *
     * @return string  The key for the given data type.
     */
    public function buildKey($permalink = '', $type = 'data')
    {
        return $this->config[ 'prefix_' . $type ] . $permalink;
    }

    /**
     * Returns an array which contains the cache keys for each permalink in the parameter array.
     *
     * @param array $permalinks  [ 'permalink' => true, ]
     *
     * @return array  [ 'dataKey' => true, 'metaKEy' => true ]
     */
    public function buildKeys($permalinks = [])
    {

        $result = [];

        foreach ($permalinks as $permalink => $dummy) {

            $result[ $this->buildKey($permalink, 'data') ] = true;
            $result[ $this->buildKey($permalink, 'meta') ] = true;

        }

        return $result;
    }

    /**
     * public get function, transparent proxy to internal function based on memcached
     *
     * @param string $key Cache key to get value for
     *
     * @return mixed     False when entry not found or entry value on success
     */
    public function get($key)
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
    public function set($key, &$data)
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
     * Deletes cache entries for a given array of permalinks.
     *
     * @param array $permalinks  [ 'permalink' => true, ]
     *
     * @return bool
     */
    public function clearLinks($permalinks = [])
    {
        if (! $this->isAlive() || empty($permalinks)) {

            return false;
        }

        $keys   = $this->buildKeys($permalinks);
        $result = false;

        foreach ($keys as $key => $dummy) {

            $kresult = $this->connection->delete($key);

            if ($kresult === false) {

                error_log('unable to delete entry: ' . $key);

            } else {

                $result = true;

                error_log('entry deleted: ' . $key);

            }

        }

        return $result;
    }

    /**
     * Sets the status of each server in an array.
     * This will create a new \Memcached object and add a single server for each server in the actual connection in
     * order to test it independently.
     *
     * @return bool|array $this->status  Array of configured servers with aliveness value
     */
    public function getStatusArray()
    {
        if (! $this->isAlive()) {

            return false;
        }

        $servers = $this->connection->getServerList();

        if (empty($servers)) {

            $this->status = [];

            return $this->status;
        }

        if (count($servers) === 1 && $this->connection->set('wc-mfpc', time())) {

            $this->status[ $servers[ 0 ][ 'host' ] . self::port_separator . $servers[ 0 ][ 'port' ] ] = 1;

            return $this->status;
        }

        foreach ($servers as $server) {

            $serverId                  = $server[ 'host' ] . self::port_separator . $server[ 'port' ];
            $this->status[ $serverId ] = 0;

            $memcached = new \Memcached();
            $memcached->addServer($server[ 'host' ], $server[ 'port' ]);

            if ($memcached->set('wc-mfpc', time())) {

                $this->status[ $serverId ] = 1;

            }

            unset($memcached);

        }

        return $this->status;
    }

    /**
     * Returns the connection status.
     * This returns the \Memcached object result when setting a test key.
     *
     * @return array|bool
     */
    public function status()
    {
        if (! $this->isAlive()) {

            return false;
        }

        return $this->connection->set('wc-mfpc', time());
    }

}
