# WooCommerce Memcached Full Page Cache

WooCommerce full page cache plugin based on Memcached.

## Installation
...

## Settings
...

## Customization

### Hooks

* `wc-mfpc-custom-expire`

This hook lets you customize the Expire time of the response header.

Example:
```
function cust_wc_mfpc_set_expire($expire = null) {

    if ($this->config[ 'expire_home' ] !== '' && (is_home() || is_feed())) {
    
        $expire = (int) $this->config[ 'expire_home' ];
    
    } elseif ($this->config[ 'expire_taxonomy' ] !== '' && (is_tax() || is_category() || is_tag() || is_archive())) {
    
        $expire = (int) $this->config[ 'expire_taxonomy' ];
    }
    
}
add_filter('wc-mpfc-custom-expire', 'cust_wc_mfpc_set_expire');
```

* ` `

