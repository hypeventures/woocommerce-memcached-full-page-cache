# WooCommerce Memcached Full Page Cache

WooCommerce full page cache plugin based on Memcached.

## Installation
...

## Settings
...

## Customization

### Hooks

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
    
    } elseif ($this->config[ 'expire_taxonomy' ] !== '' && (is_tax() || is_category() || is_tag() || is_archive())) {
    
        $expire = (int) $this->config[ 'expire_taxonomy' ];
    }
    
    return $expire;
}
add_filter('wc_mfpc_custom_expire', 'cust_wc_mfpc_set_expire');
```

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
             * Remove the taxonomy name from the link, lots of plugins remove this for SEO, it's better to
             * include them than leave them out. In worst case, some 404 pages are cached as well
             */
            $link             = str_replace('/' . $taxonomy->rewrite[ 'slug' ], '', $link);
            $toClear[ $link ] = true;
  
        }
  
    }
    
    return $toClear;
}
add_filter('wc_mfpc_custom_to_clear_before, 'cust_wc_mfpc_set_expire', 10, 3);
```

* ` `

...

Example:
```

```

