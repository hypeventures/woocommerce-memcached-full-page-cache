# WooCommerce Memcached Full Page Cache

WooCommerce full page cache plugin using Memcached.

__CREDITS:__ This plugin is based on [WP-FFPC](https://github.com/petermolnar/wp-ffpc) 
by [Peter Molnar](https://github.com/petermolnar).

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WooCommerce v3.5+](./assets/badge-wc.svg)](https://wordpress.org)
[![WordPress v4.9+](./assets/badge-wp4.svg)](https://wordpress.org/news/category/releases/)
[![WordPress v5.x+](./assets/badge-wp5.svg)](https://wordpress.org)
[![PHP v7.x](./assets/badge-php7.svg)](https://php.net)
[![PHP Memcached](./assets/badge-memcached.svg)](https://www.php.net/manual/de/book.memcached.php)

## Installation

1. Upload contents of `woocommerce-memcached-full-page-cache.zip` (_OR clone this repository_) to the 
`/wp-content/plugins/` directory of your wordpress installation.
2. Enable WordPress caching via adding `define('WP_CACHE', true);` in the file `wp-config.php`
3. Activate the plugin in WordPress
4. Check the settings in `WooCommerce` => `Full Page Cache` menu in your Wordpress admin backend.
5. __(!) Save the settings (!)__ to generate `/wp-content/advanced-cache.php` and activate caching.

...

## Settings
__Settings link:__ yourdomain.xyz/wp-admin/admin.php?page=wc-mfpc-settings

OR

__WP Admin__ => __WooCommerce__ => Full Page Cache

Defaults:
```
'hosts'                   => '127.0.0.1:11211',
'memcached_binary'        => 'yes',
'authpass'                => '',
'authuser'                => '',
'expire'                  => '86400',
'browsercache'            => '14400',
'prefix_meta'             => 'meta-',
'prefix_data'             => 'data-',
'charset'                 => 'utf-8',
'cache_loggedin'          => 'yes',
'nocache_cookies'         => '',
'nocache_woocommerce_url' => '^/DYNAMIC/|^/DYNAMIC/|^/DYNAMIC/|^/wc\\-api|^/\\?wc\\-api=',
'nocache_url'             => '^/wc\\-|^/wp\\-|addons|removed|gdsr|wp_rg|wp_session​|wc_session​',
'response_header'         => 'yes',
'comments_invalidate'     => 'yes',
'pingback_header'         => '0',
```

## Customization

You can use hooks to customize the behaviour of this plugin.

__Filter Hooks:__

_wc-mfpc-advanced-cache.php_ :
- wc_mfpc_custom_skip_load_from_cache
- wc_mfpc_custom_skip_caching
- wc_mfpc_custom_cache_content
- wc_mfpc_custom_cache_meta
        
_Memcached::class_ :
- wc_mfpc_custom_build_url
- wc_mfpc_custom_build_key
- wc_mfpc_custom_build_keys
- wc_mfpc_custom_expire
        
_Admin::class_ :
- wc_mfpc_custom_advanced_cache_config

__Action Hooks:__

_AdminView::class_ :
- wc_mfpc_settings_form_top
- wc_mfpc_settings_form_bottom
- wc_mfpc_settings_form_memcached_connection
- wc_mfpc_settings_form_cache
- wc_mfpc_settings_form_exception
- wc_mfpc_settings_form_debug


### Hook: Custom SkipLoadFromCache

* `wc_mfpc_custom_skip_load_from_cache`

...

Example #1:
```
/**
 * Function to customize whether a page is processed by wp-ffpc at all.
 *
 * @param bool   $skip    Default: false - return TRUE for skipping
 * @param array  $config  Array with config from advanced-cache.php
 * @param string $uri     Requested URL string
 *
 * @return bool $skip
 */
function cust_wc_mfpc_set_skip_load_from_cache($skip = false, $config = [], $uri = '')
{
    /*
     * If you do somehting like this, don't forget to add this cookie also to your 
     * cache exclude list in nginx.
     */
    if (! empty($_COOKIE[ 'SUPER_SPECIAL_COOKIE' ]) {

        $skip = true;

    }
    
    return $skip
}
add_filter('wc_mfpc_custom_skip_load_from_cache', 'cust_wc_mfpc_set_skip_load_from_cache')
```
Example #2:
 ```
if (! empty($_COOKIE[ 'SUPER_SPECIAL_COOKIE' ]) {
    
    add_filter('wc_mfpc_custom_skip_load_from_cache', '__return_true');

}
 ```

### Hook: Custom Expire

* `wc_mfpc_custom_expire`

This hook lets you customize the Expire time of the response header.

Example:
```
/**
 * Function to customize expriration header.
 *
 * @param int $expire
 *
 * @return int
 */
function cust_wc_mfpc_set_expire($expire = 0)
{
    if ($this->config[ 'expire_home' ] !== '' && (is_home() || is_feed())) {
    
        $expire = (int) $this->config[ 'expire_home' ];
    
    } elseif (
              $this->config[ 'expire_taxonomy' ] !== '' 
              && (is_tax() || is_category() || is_tag() || is_archive())
    ) {
    
        $expire = (int) $this->config[ 'expire_taxonomy' ];
        
    }
    
    return $expire;
}
add_filter('wc_mfpc_custom_expire', 'cust_wc_mfpc_set_expire');
```

---

### Hooks: Custom ToClear

* `wc_mfpc_custom_to_clear_before`
* `wc_mfpc_custom_to_clear_after`

These hooks let you customize handling of a "Clear/Delete from Memcached" event.

__Note:__  
`wc_mfpc_custom_to_clear_before` can return bool false to prevent any further processing and abort default clearing of
the cache. This is not the case for `wc_mfpc_custom_to_clear_after`!

Examples:
```
/**
 * Function to customize array of permalinks $toClear.
 *
 * @see InvincibleBrands\WcMfpc\WcMfpc::clearMemcached()
 *
 * @param array      $toClear  Array of keys which should be cleared afterwards.
 * @param string|int $postId   Id of the post in question IF it is a post. Needs to be checked!
 * @param InvincibleBrands\WcMfpc\Memcached $memcached  
 *   Instance of this Memcached class with active server connection.
 *
 * @return array $toClear
 */
function cust_wc_mfpc_set_to_clear($toClear = [], $postId = '', $memcached = null)
{
    /*
     * Replace $something with the condition of your choice to trigger flushing
     */
    if ($something) {
    
        $memcached->flush();
    
        return false;
    }
    
    /*
     * If you want to flush also the taxonomies whlie cearling a post from cache, the following will do.
     */
    $taxonomies = get_taxonomies([
        'public' => true,
    ], 'objects');
  
    if (empty($taxonomies)) {
  
        return $toClear;
    }
  
    foreach ($taxonomies as $taxonomy) {
  
        $terms = get_terms([
            'taxonomy'     => $taxonomy->name,
            'hide_empty'   => true,
            'fields'       => 'all',
            'hierarchical' => false,
        ]);
  
        if (empty($terms)) {
  
            continue;
  
        }
  
        foreach ($terms as $term) {
  
            if (empty($term->count)) {
  
                continue;
  
            }
  
            $link             = get_term_link($term->slug, $taxonomy->name);
            $toClear[ $link ] = true;
  
            /*
             * Remove the taxonomy name from the link, lots of plugins remove this for SEO, it's better
             * to include them than leave them out. In worst case, some 404 pages are cached as well.
             */
            $link             = str_replace('/' . $taxonomy->rewrite[ 'slug' ], '', $link);
            $toClear[ $link ] = true;
  
        }
  
    }
    
    return $toClear;
}
add_filter('wc_mfpc_custom_to_clear_before, 'cust_wc_mfpc_set_expire', 10, 3);
```

---

### Hook: Custom CacheContent

* `wc_mfpc_custom_cache_content`

This hook lets you customize the raw content of a page, before it gets stored in cache.

It can be found here: [wc-mfpc-advanced-cache.php](wc-mfpc-advanced-cache.php)

Example:
```
/**
 * Function to customize the html content of any page.
 *
 * @param string $cacheContent  The content to be stored in cache.
 *
 * @return string $cacheContent
 */
function cust_wc_mfpc_set_cache_content($cacheContent = '')
{    
    /*
     * Add generation date info, but only to HTML.
     */
    if (stripos($cacheContent, '</body>')) {

        $insertion    = "\n<!-- WC-MFPC cache created date: " . date('c') . " -->\n";
        $index        = stripos($buffer, '</body>');
        $cacheContent = substr_replace($buffer, $insertion, $index, 0);
    }
    
    return $cacheContent;
}
add_filter('wc_mfpc_custom_cache_content', 'cust_wc_mfpc_set_cache_content');
```

---

### Hook: Custom CacheMeta

* `wc_mfpc_custom_cache_meta`

This hook lets you customize the cache meta data of a page, before it gets stored in cache.

It can be found here: [wc-mfpc-advanced-cache.php](wc-mfpc-advanced-cache.php)

Example:
```
/**
 * Function to customize the cached meta data array of any page.
 *
 * @param array $cacheMeta  The meta data array to be stored in cache.
 *
 * @return array $cacheMeta
 */
function cust_wc_mfpc_set_cache_meta($cacheMeta = '')
{
    /*
     * Example:
     * Change browsercache expire time for certain page types before storing meta data.
     */
    if (is_home() || is_feed) {

        $cacheMeta[ 'expire' ] = time() + 1800;

    } elseif (is_archive()) {

        $cacheMeta[ 'expire' ] = time() + 3600;

    } elseif (is_product()) {

        $cacheMeta[ 'expire' ] = time() + 7200;

    }
    
    return $cacheMeta;
}
add_filter('wc_mfpc_custom_cache_meta', 'cust_wc_mfpc_set_cache_meta');
```

### Hook: Custom SkipCaching

* `wc_mfpc_custom_skip_caching`

...

Example #1:
```
/**
 * Function to custom skip storing data in cache.
 *
 * @param bool $skip       Set TRUE to skip caching.
 * @param string $content  The page content.
 *
 * @return bool $skip
 */
function cust_wc_mfpc_set_skip_caching($skip = false, $content = '')
{
    if (! empty(stripos($content, '<div class="skip-me-from-cache">'))) {

        $skip = true;

    }

    return $skip;
}
add_filter('wc_mfpc_custom_skip_caching', 'cust_wc_mfpc_set_skip_caching')
```
Example #2:
```
/*
 * Somewhere in your plugin / theme when rendering a special, individual & dynamic page
 * which should never be cached.
 */
add_filter('wc_mfpc_custom_skip_caching', '__return_true');
```

### Hook:

* ` `

...

Example:
```

```

