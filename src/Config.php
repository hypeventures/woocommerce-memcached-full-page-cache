<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

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
    public $hosts                   = '127.0.0.1:11211';

    /**
     * @var bool
     */
    public $memcached_binary        = true;

    /**
     * @var string
     */
    public $authpass                = '';

    /**
     * @var string
     */
    public $authuser                = '';

    /**
     * @var int
     */
    public $browsercache            = 14400;

    /**
     * @var int
     */
    public $browsercache_home       = 14400;

    /**
     * @var int
     */
    public $browsercache_taxonomy   = 14400;

    /**
     * @var int
     */
    public $expire                  = 86400;

    /**
     * @var int
     */
    public $expire_home             = 86400;

    /**
     * @var int
     */
    public $expire_taxonomy         = 86400;

    /**
     * @var int
     */
    public $invalidation_method     = 1;

    /**
     * @var string
     */
    public $prefix_meta             = 'meta-';

    /**
     * @var string
     */
    public $prefix_data             = 'data-';

    /**
     * @var string
     */
    public $charset                 = 'utf-8';

    /**
     * @var bool
     */
    public $log                     = true;

    /**
     * @var string
     */
    public $cache_type              = 'memcached';

    /**
     * @var bool
     */
    public $cache_loggedin          = false;

    /**
     * @var bool
     */
    public $nocache_home            = false;

    /**
     * @var bool
     */
    public $nocache_feed            = false;

    /**
     * @var bool
     */
    public $nocache_archive         = false;

    /**
     * @var bool
     */
    public $nocache_single          = false;

    /**
     * @var bool
     */
    public $nocache_page            = false;

    /**
     * @var string
     */
    public $nocache_cookies         = '';

    /**
     * @var string
     */
    public $nocache_woocommerce_url = '^/checkout/|^/my-account/|^/cart/|^/wc-api|^/\\?wc-api=';

    /**
     * @var string
     */
    public $nocache_url             = '^/wc-|^/wp-|addons|removed|gdsr|wp_rg|wp_session​|wc_session​';

    /**
     * @var bool
     */
    public $response_header         = true;

    /**
     * @var bool
     */
    public $generate_time           = false;

    /**
     * @var string
     */
    public $key                     = '$scheme://$host$request_uri';

    /**
     * @var bool
     */
    public $comments_invalidate     = true;

    /**
     * @var bool
     */
    public $pingback_header         = false;

    /**
     * Config constructor.
     *
     * @param array $config  Optional array with config from DB;
     */
    public function __construct($config = [])
    {
        add_action('wp_loaded', [ $this, 'setNocacheWoocommerceUrl' ]);
        $this->setConfig($config);
    }

    /**
     * Processes a given Config Array and sets its contents for all keys
     * which are a known attribute of this Config::class.
     *
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

                $this->{$key} = esc_attr($value);

            }

        }

        return $this;
    }

    /**
     * Returns an array of this Config::class
     *
     * @return array
     */
    public function getConfig()
    {
        return (array) $this;
    }

    /**
     * reads options stored in database and reads merges them with default values
     */
    public function read()
    {
        global $wcMfpcData;

        if ($wcMfpcData->network) {

            $options = get_site_option(Data::plugin_constant);

        } else {

            $options = get_option(Data::plugin_constant);

        }

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

        $this->setConfig($options);
    }

    /**
     * @return string
     */
    public function getHosts()
    {
        return $this->hosts;
    }

    /**
     * @return bool
     */
    public function isMemcachedBinary()
    {
        return $this->memcached_binary;
    }

    /**
     * @return string
     */
    public function getAuthpass()
    {
        return $this->authpass;
    }

    /**
     * @return string
     */
    public function getAuthuser()
    {
        return $this->authuser;
    }

    /**
     * @return int
     */
    public function getBrowsercache()
    {
        return $this->browsercache;
    }

    /**
     * @return int
     */
    public function getBrowsercacheHome()
    {
        return $this->browsercache_home;
    }

    /**
     * @return int
     */
    public function getBrowsercacheTaxonomy()
    {
        return $this->browsercache_taxonomy;
    }

    /**
     * @return int
     */
    public function getExpire()
    {
        return $this->expire;
    }

    /**
     * @return int
     */
    public function getExpireHome()
    {
        return $this->expire_home;
    }

    /**
     * @return int
     */
    public function getExpireTaxonomy()
    {
        return $this->expire_taxonomy;
    }

    /**
     * @return int
     */
    public function getInvalidationMethod()
    {
        return $this->invalidation_method;
    }

    /**
     * @return string
     */
    public function getPrefixMeta()
    {
        return $this->prefix_meta;
    }

    /**
     * @return string
     */
    public function getPrefixData()
    {
        return $this->prefix_data;
    }

    /**
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @return bool
     */
    public function isLog()
    {
        return $this->log;
    }

    /**
     * @return string
     */
    public function getCacheType()
    {
        return $this->cache_type;
    }

    /**
     * @return bool
     */
    public function isCacheLoggedin()
    {
        return $this->cache_loggedin;
    }

    /**
     * @return bool
     */
    public function isNocacheHome()
    {
        return $this->nocache_home;
    }

    /**
     * @return bool
     */
    public function isNocacheFeed()
    {
        return $this->nocache_feed;
    }

    /**
     * @return bool
     */
    public function isNocacheArchive()
    {
        return $this->nocache_archive;
    }

    /**
     * @return bool
     */
    public function isNocacheSingle()
    {
        return $this->nocache_single;
    }

    /**
     * @return bool
     */
    public function isNocachePage()
    {
        return $this->nocache_page;
    }

    /**
     * @return string
     */
    public function getNocacheCookies()
    {
        return $this->nocache_cookies;
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
    public function setNocacheWoocommerceUrl(string $nocache_woocommerce_url = '')
    {
        $this->nocache_woocommerce_url = $nocache_woocommerce_url;

        if (empty($nocache_woocommerce_url) && class_exists('WooCommerce')) {

            $home                  = home_url();
            $page_wc_checkout      = str_replace($home, '', wc_get_page_permalink('checkout'));
            $page_wc_myaccount     = str_replace($home, '', wc_get_page_permalink('myaccount'));
            $page_wc_cart          = str_replace($home, '', wc_get_page_permalink('cart'));
            $wcapi                 = '^/wc-api|^/\?wc-api=';
            $noCacheWooCommerceUrl = '^' . $page_wc_checkout . '|^' . $page_wc_myaccount . '|^' . $page_wc_cart . '|' . $wcapi;

            $this->nocache_woocommerce_url = $noCacheWooCommerceUrl;

        }
    }

    /**
     * @return string
     */
    public function getNocacheUrl()
    {
        return $this->nocache_url;
    }

    /**
     * @return bool
     */
    public function isResponseHeader()
    {
        return $this->response_header;
    }

    /**
     * @return bool
     */
    public function isGenerateTime()
    {
        return $this->generate_time;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return bool
     */
    public function isCommentsInvalidate()
    {
        return $this->comments_invalidate;
    }

    /**
     * @return bool
     */
    public function isPingbackHeader()
    {
        return $this->pingback_header;
    }

}