# WooCommerce Memcached Full Page Cache

WooCommerce full page cache plugin using Memcached.

__CREDITS:__ This plugin is based on [WP-FFPC](https://github.com/petermolnar/wp-ffpc) 
by [Peter Molnar](https://github.com/petermolnar).

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

...

## Customization

You can use hooks to customize the behaviour of this plugin.

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
 * Function to customize the cached meta data of any page.
 *
 * @param array $cacheMeta  The content to be stored in cache.
 *
 * @return array $cacheMeta
 */
function cust_wc_mfpc_set_cache_meta($cacheMeta = '')
{    
    
    
    return $cacheMeta;
}
add_filter('wc_mfpc_custom_cache_meta', 'cust_wc_mfpc_set_cache_meta');
```

* ` `

...

Example:
```

```
