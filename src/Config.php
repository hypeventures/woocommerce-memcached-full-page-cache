<?php

namespace InvincibleBrands\WcMfpc;


/**
 * Class Config
 *
 * @package InvincibleBrands\WcMfpc
 */
class Config
{

    /**
     * @var string
     */
    protected $hosts                   = '127.0.0.1:11211';

    /**
     * @var bool
     */
    protected $memcached_binary        = false;

    /**
     * @var string
     */
    protected $authpass                = '';

    /**
     * @var string
     */
    protected $authuser                = '';

    /**
     * @var int
     */
    protected $browsercache            = 0;

    /**
     * @var int
     */
    protected $browsercache_home       = 0;

    /**
     * @var int
     */
    protected $browsercache_taxonomy   = 0;

    /**
     * @var int
     */
    protected $expire                  = 300;

    /**
     * @var int
     */
    protected $expire_home             = 300;

    /**
     * @var int
     */
    protected $expire_taxonomy         = 300;

    /**
     * @var int
     */
    protected $invalidation_method     = 0;

    /**
     * @var string
     */
    protected $prefix_meta             = 'meta-';

    /**
     * @var string
     */
    protected $prefix_data             = 'data-';

    /**
     * @var string
     */
    protected $charset                 = 'utf-8';

    /**
     * @var bool
     */
    protected $log                     = true;

    /**
     * @var string
     */
    protected $cache_type              = 'memcached';

    /**
     * @var bool
     */
    protected $cache_loggedin          = false;

    /**
     * @var bool
     */
    protected $nocache_home            = false;

    /**
     * @var bool
     */
    protected $nocache_feed            = false;

    /**
     * @var bool
     */
    protected $nocache_archive         = false;

    /**
     * @var bool
     */
    protected $nocache_single          = false;

    /**
     * @var bool
     */
    protected $nocache_page            = false;

    /**
     * @var bool
     */
    protected $nocache_cookies         = false;

    /**
     * @var bool
     */
    protected $nocache_dyn             = true;

    /**
     * @var bool
     */
    protected $nocache_woocommerce     = true;

    /**
     * @var string
     */
    protected $nocache_woocommerce_url = '';

    /**
     * @var string
     */
    protected $nocache_url             = '^/wp-';

    /**
     * @var string
     */
    protected $nocache_comment         = '';

    /**
     * @var bool
     */
    protected $response_header         = false;

    /**
     * @var bool
     */
    protected $generate_time           = false;

    /**
     * @var string
     */
    protected $precache_schedule       = 'null';

    /**
     * @var string
     */
    protected $key                     = '$scheme://$host$request_uri';

    /**
     * @var bool
     */
    protected $comments_invalidate     = true;

    /**
     * @var bool
     */
    protected $pingback_header         = false;

    /**
     * @var bool
     */
    protected $hashkey                 = false;

    /**
     * Config constructor.
     *
     * @param array $config  Optional array with config from DB;
     */
    public function __construct($config = [])
    {
        $this->setConfig($config);
    }

    /**
     * @param array $config
     *
     * @return Config $this
     */
    public function setConfig($config = [])
    {
        if (empty($config) || ! is_array($config)) {

            return $this;
        }

        foreach ($config as $key => $value) {

            if (property_exists(self::class, $key)) {

                $this->$key = esc_attr($value);

            }

        }

        return $this;
    }

    /**
     * @return string
     */
    public function getHosts()
    {
        return $this->hosts;
    }

    /**
     * @param string $hosts
     */
    public function setHosts(string $hosts)
    {
        $this->hosts = $hosts;
    }

    /**
     * @return bool
     */
    public function isMemcachedBinary()
    {
        return $this->memcached_binary;
    }

    /**
     * @param bool $memcached_binary
     */
    public function setMemcachedBinary(bool $memcached_binary)
    {
        $this->memcached_binary = $memcached_binary;
    }

    /**
     * @return string
     */
    public function getAuthpass()
    {
        return $this->authpass;
    }

    /**
     * @param string $authpass
     */
    public function setAuthpass(string $authpass)
    {
        $this->authpass = $authpass;
    }

    /**
     * @return string
     */
    public function getAuthuser()
    {
        return $this->authuser;
    }

    /**
     * @param string $authuser
     */
    public function setAuthuser(string $authuser)
    {
        $this->authuser = $authuser;
    }

    /**
     * @return int
     */
    public function getBrowsercache()
    {
        return $this->browsercache;
    }

    /**
     * @param int $browsercache
     */
    public function setBrowsercache(int $browsercache)
    {
        $this->browsercache = $browsercache;
    }

    /**
     * @return int
     */
    public function getBrowsercacheHome()
    {
        return $this->browsercache_home;
    }

    /**
     * @param int $browsercache_home
     */
    public function setBrowsercacheHome(int $browsercache_home)
    {
        $this->browsercache_home = $browsercache_home;
    }

    /**
     * @return int
     */
    public function getBrowsercacheTaxonomy()
    {
        return $this->browsercache_taxonomy;
    }

    /**
     * @param int $browsercache_taxonomy
     */
    public function setBrowsercacheTaxonomy(int $browsercache_taxonomy)
    {
        $this->browsercache_taxonomy = $browsercache_taxonomy;
    }

    /**
     * @return int
     */
    public function getExpire()
    {
        return $this->expire;
    }

    /**
     * @param int $expire
     */
    public function setExpire(int $expire)
    {
        $this->expire = $expire;
    }

    /**
     * @return int
     */
    public function getExpireHome()
    {
        return $this->expire_home;
    }

    /**
     * @param int $expire_home
     */
    public function setExpireHome(int $expire_home)
    {
        $this->expire_home = $expire_home;
    }

    /**
     * @return int
     */
    public function getExpireTaxonomy()
    {
        return $this->expire_taxonomy;
    }

    /**
     * @param int $expire_taxonomy
     */
    public function setExpireTaxonomy(int $expire_taxonomy)
    {
        $this->expire_taxonomy = $expire_taxonomy;
    }

    /**
     * @return int
     */
    public function getInvalidationMethod()
    {
        return $this->invalidation_method;
    }

    /**
     * @param int $invalidation_method
     */
    public function setInvalidationMethod(int $invalidation_method)
    {
        $this->invalidation_method = $invalidation_method;
    }

    /**
     * @return string
     */
    public function getPrefixMeta()
    {
        return $this->prefix_meta;
    }

    /**
     * @param string $prefix_meta
     */
    public function setPrefixMeta(string $prefix_meta)
    {
        $this->prefix_meta = $prefix_meta;
    }

    /**
     * @return string
     */
    public function getPrefixData()
    {
        return $this->prefix_data;
    }

    /**
     * @param string $prefix_data
     */
    public function setPrefixData(string $prefix_data)
    {
        $this->prefix_data = $prefix_data;
    }

    /**
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     */
    public function setCharset(string $charset)
    {
        $this->charset = $charset;
    }

    /**
     * @return bool
     */
    public function isLog()
    {
        return $this->log;
    }

    /**
     * @param bool $log
     */
    public function setLog(bool $log)
    {
        $this->log = $log;
    }

    /**
     * @return string
     */
    public function getCacheType()
    {
        return $this->cache_type;
    }

    /**
     * @param string $cache_type
     */
    public function setCacheType(string $cache_type)
    {
        $this->cache_type = $cache_type;
    }

    /**
     * @return bool
     */
    public function isCacheLoggedin()
    {
        return $this->cache_loggedin;
    }

    /**
     * @param bool $cache_loggedin
     */
    public function setCacheLoggedin(bool $cache_loggedin)
    {
        $this->cache_loggedin = $cache_loggedin;
    }

    /**
     * @return bool
     */
    public function isNocacheHome()
    {
        return $this->nocache_home;
    }

    /**
     * @param bool $nocache_home
     */
    public function setNocacheHome(bool $nocache_home)
    {
        $this->nocache_home = $nocache_home;
    }

    /**
     * @return bool
     */
    public function isNocacheFeed()
    {
        return $this->nocache_feed;
    }

    /**
     * @param bool $nocache_feed
     */
    public function setNocacheFeed(bool $nocache_feed)
    {
        $this->nocache_feed = $nocache_feed;
    }

    /**
     * @return bool
     */
    public function isNocacheArchive()
    {
        return $this->nocache_archive;
    }

    /**
     * @param bool $nocache_archive
     */
    public function setNocacheArchive(bool $nocache_archive)
    {
        $this->nocache_archive = $nocache_archive;
    }

    /**
     * @return bool
     */
    public function isNocacheSingle()
    {
        return $this->nocache_single;
    }

    /**
     * @param bool $nocache_single
     */
    public function setNocacheSingle(bool $nocache_single)
    {
        $this->nocache_single = $nocache_single;
    }

    /**
     * @return bool
     */
    public function isNocachePage()
    {
        return $this->nocache_page;
    }

    /**
     * @param bool $nocache_page
     */
    public function setNocachePage(bool $nocache_page)
    {
        $this->nocache_page = $nocache_page;
    }

    /**
     * @return bool
     */
    public function isNocacheCookies()
    {
        return $this->nocache_cookies;
    }

    /**
     * @param bool $nocache_cookies
     */
    public function setNocacheCookies(bool $nocache_cookies)
    {
        $this->nocache_cookies = $nocache_cookies;
    }

    /**
     * @return bool
     */
    public function isNocacheDyn()
    {
        return $this->nocache_dyn;
    }

    /**
     * @param bool $nocache_dyn
     */
    public function setNocacheDyn(bool $nocache_dyn)
    {
        $this->nocache_dyn = $nocache_dyn;
    }

    /**
     * @return bool
     */
    public function isNocacheWoocommerce()
    {
        return $this->nocache_woocommerce;
    }

    /**
     * @param bool $nocache_woocommerce
     */
    public function setNocacheWoocommerce(bool $nocache_woocommerce)
    {
        $this->nocache_woocommerce = $nocache_woocommerce;
    }

    /**
     * @return string
     */
    public function getNocacheWoocommerceUrl()
    {
        return $this->nocache_woocommerce_url;
    }

    /**
     * @param string $nocache_woocommerce_url
     */
    public function setNocacheWoocommerceUrl(string $nocache_woocommerce_url)
    {
        $this->nocache_woocommerce_url = $nocache_woocommerce_url;
    }

    /**
     * @return string
     */
    public function getNocacheUrl()
    {
        return $this->nocache_url;
    }

    /**
     * @param string $nocache_url
     */
    public function setNocacheUrl(string $nocache_url)
    {
        $this->nocache_url = $nocache_url;
    }

    /**
     * @return string
     */
    public function getNocacheComment()
    {
        return $this->nocache_comment;
    }

    /**
     * @param string $nocache_comment
     */
    public function setNocacheComment(string $nocache_comment)
    {
        $this->nocache_comment = $nocache_comment;
    }

    /**
     * @return bool
     */
    public function isResponseHeader()
    {
        return $this->response_header;
    }

    /**
     * @param bool $response_header
     */
    public function setResponseHeader(bool $response_header)
    {
        $this->response_header = $response_header;
    }

    /**
     * @return bool
     */
    public function isGenerateTime()
    {
        return $this->generate_time;
    }

    /**
     * @param bool $generate_time
     */
    public function setGenerateTime(bool $generate_time)
    {
        $this->generate_time = $generate_time;
    }

    /**
     * @return string
     */
    public function getPrecacheSchedule()
    {
        return $this->precache_schedule;
    }

    /**
     * @param string $precache_schedule
     */
    public function setPrecacheSchedule(string $precache_schedule)
    {
        $this->precache_schedule = $precache_schedule;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param string $key
     */
    public function setKey(string $key)
    {
        $this->key = $key;
    }

    /**
     * @return bool
     */
    public function isCommentsInvalidate()
    {
        return $this->comments_invalidate;
    }

    /**
     * @param bool $comments_invalidate
     */
    public function setCommentsInvalidate(bool $comments_invalidate)
    {
        $this->comments_invalidate = $comments_invalidate;
    }

    /**
     * @return bool
     */
    public function isPingbackHeader()
    {
        return $this->pingback_header;
    }

    /**
     * @param bool $pingback_header
     */
    public function setPingbackHeader(bool $pingback_header)
    {
        $this->pingback_header = $pingback_header;
    }

    /**
     * @return bool
     */
    public function isHashkey()
    {
        return $this->hashkey;
    }

    /**
     * @param bool $hashkey
     */
    public function setHashkey(bool $hashkey)
    {
        $this->hashkey = $hashkey;
    }

}