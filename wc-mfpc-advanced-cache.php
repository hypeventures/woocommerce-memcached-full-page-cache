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

/**
 * Advanced cache worker of "WooCommerce Memcached Full Page Cache" Plugin.
 *
 * @see \InvincibleBrands\WcMfpc\Config::getConfig()
 *
 * @var \InvincibleBrands\WcMfpc\Config[]string|array $wc_mfpc_config_array
 */

if (! defined('ABSPATH')) { exit; }

error_log('--------------------------------------------------------------------------------------------------');

/*
 * check for WP cache enabled
 */
if (! defined('WP_CACHE') || WP_CACHE != true) {

    error_log('WP_CACHE is not true');

    return false;
}

/*
 * no cache for post request (comments, plugins and so on)
 */
if ($_SERVER[ "REQUEST_METHOD" ] === 'POST') {

    error_log('POST (AJAX) requests are never cached');

    return false;
}

/*
 * Try to avoid enabling the cache if sessions are managed with request parameters and a session is active.
 */
if (defined('SID') && SID != '') {

    error_log('SID found, skipping cache');

    return false;
}


/*
 * check for config
 */
if (! isset($wc_mfpc_config_array) || empty($wc_mfpc_config_array[ $_SERVER[ 'HTTP_HOST' ] ])) {

    error_log("No config found.");

    return false;
}

$wc_mfpc_config_array = $wc_mfpc_config_array[ $_SERVER[ 'HTTP_HOST' ] ];
$wc_mfpc_uri          = $_SERVER[ 'REQUEST_URI' ];

if (
    /**
     * Filter to skip loading page from cache.
     * Allows 3rd parties to implement their own conditions to skip loading a page from cache.
     *
     * @param bool   $skip                  Default: false - return TRUE for skipping
     * @param array  $wc_mfpc_config_array  Array with config from advanced-cache.php
     * @param string $wc_mfpc_uri           Requested URL string
     *
     * @return bool $skip
     */
    (bool) apply_filters('wc_mfpc_custom_skip_load_from_cache', $skip = false, $wc_mfpc_config_array, $wc_mfpc_uri)
) {

    return false;
}

/*
 * no cache for uri with query strings, things usually go bad that way
 */
if (stripos($wc_mfpc_uri, '?') !== false) {

    error_log('Dynamic url cache is disabled ( url with "?" ), skipping');

    return false;
}

/*
 * no cache for WooCommerce URL patterns
 */
if (isset($wc_mfpc_config_array[ 'nocache_woocommerce_url' ])) {

    $pattern = sprintf('#%s#', $wc_mfpc_config_array[ 'nocache_woocommerce_url' ]);

    if (preg_match($pattern, $wc_mfpc_uri)) {

        error_log("Cache exception based on WooCommenrce URL regex pattern matched, skipping");

        return false;
    }

}

/*
 * NEVER cache for admins!
 */
if (isset($_COOKIE[ 'wc-mfpc-nocache' ])) {

    error_log('No cache for admin users! Skipping.');

    return false;
}

/*
 * no cache for for logged in users
 */
if (empty($wc_mfpc_config_array[ 'cache_loggedin' ])) {

    $nocache_cookies = [ 'comment_author_', 'wordpressuser_', 'wp-postpass_', 'wordpress_logged_in_' ];

    foreach ($_COOKIE as $cookie => $value) {

        foreach ($nocache_cookies as $nocache_cookie) {

            if (strpos($cookie, $nocache_cookie) === 0) {

                error_log("No cache for cookie: {$cookie}, skipping");

                return false;
            }

        }

    }

}

/*
 * check for cookies that will make us not cache the content, like logged in WordPress cookie
 */
if (! empty($wc_mfpc_config_array[ 'nocache_cookies' ])) {

    $nocache_cookies = array_map('trim', explode(",", $wc_mfpc_config_array[ 'nocache_cookies' ]));

    if (! empty($nocache_cookies)) {

        foreach ($_COOKIE as $n => $v) {

            /* check for any matches to user-added cookies to no-cache */
            foreach ($nocache_cookies as $nocache_cookie) {

                if (strpos($n, $nocache_cookie) === 0) {

                    error_log("Cookie exception matched: {$n}, skipping");

                    return false;
                }

            }

        }

    }

}

/*
 * No cache for excluded URL patterns
 */
if (! empty($wc_mfpc_config_array[ 'nocache_url' ])) {

    $pattern = sprintf('#%s#', trim($wc_mfpc_config_array[ 'nocache_url' ]));

    if (preg_match($pattern, $wc_mfpc_uri)) {

        error_log("Cache exception based on URL regex pattern matched, skipping");

        return false;
    }

}

/*
 * Initialize canonical redirect storage.
 */
$wc_mfpc_redirect = null;

/*
 * Connect to Memcached via actual config.
 */
include_once __DIR__ . '/src/Memcached.php';
$wc_mfpc_memcached = new \InvincibleBrands\WcMfpc\Memcached($wc_mfpc_config_array);

/*
 * Check memcached connection.
 */
if (empty($wc_mfpc_memcached->status())) {

    error_log("Backend offline");

    return false;
}

error_log("Trying to fetch entries");

/*
 * Try to get data & meta keys for current page
 */
$wc_mfpc_values      = [];
$wc_mfpc_permalink   = $wc_mfpc_memcached->buildUrl();

foreach ([ 'data', 'meta', ] as $type) {

    $key   = $wc_mfpc_memcached->buildKey($wc_mfpc_permalink, $type);
    $value = $wc_mfpc_memcached->get($key);

    if (empty($value)) {

        /*
         * ToDo: Remove! Testing only.
         */
        error_log("No cached data found");

        /*
         * It does not matter which is missing, we need both, if one fails, no caching
         */
        wc_mfpc_start();

        return;

    }

    $wc_mfpc_values[ $type ] = $value;

    /*
     * ToDo: Remove! Testing only.
     */
    error_log("Got value for $type : $key");

}

/*
 * Serve cache 404 status
 */
if (isset($wc_mfpc_values[ 'meta' ][ 'status' ]) && $wc_mfpc_values[ 'meta' ][ 'status' ] === 404) {

    /*
     * ToDo: Remove! Testing only.
     */
    error_log("Serving 404");

    header("HTTP/1.1 404 Not Found");

    /*
     * If the page stops serving here, the 404 page will not be showed at all.
     *
     * flush();
     * die();
     */

}

/*
 * Server redirect cache
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'redirect' ])) {

    /*
     * ToDo: Remove! Testing only.
     */
    error_log("Serving redirect to {$wc_mfpc_values['meta']['redirect']}");
    header('Location: ' . $wc_mfpc_values[ 'meta' ][ 'redirect' ]);

    flush();
    die();

}

/*
 * Page is already cached on client side.
 */
if (isset($_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ]) && ! empty($wc_mfpc_values[ 'meta' ][ 'lastmodified' ])) {

    $if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER[ "HTTP_IF_MODIFIED_SINCE" ]));

    /*
     * Check is cache is still valid.
     */
    if ($if_modified_since >= $wc_mfpc_values[ 'meta' ][ 'lastmodified' ]) {

        /*
         * ToDo: Remove! Testing only.
         */
        error_log("Serving 304 Not Modified");
        header("HTTP/1.0 304 Not Modified");

        flush();
        die();

    }

}

/*
 * BEGIN SERVING CACHED PAGE -------------------------------------------------------------------------------------------
 */

/*
 * Set Content-Type header.
 */
if (! empty ($wc_mfpc_values[ 'meta' ][ 'mime' ])) {

    header('Content-Type: ' . $wc_mfpc_values[ 'meta' ][ 'mime' ]);

}

/*
 * Set Cache-Control, Expire & ETag headers.
 */
if (! empty ($wc_mfpc_values[ 'meta' ][ 'expire' ])) {

    $hash   = md5($wc_mfpc_uri . $wc_mfpc_values[ 'meta' ][ 'expire' ]);
    $expire = $wc_mfpc_values[ 'meta' ][ 'expire' ];

    header('Cache-Control: public,max-age=' . $expire . ',s-maxage=' . $expire . ',must-revalidate');
    header('Expires: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values[ 'meta' ][ 'expire' ]) . " GMT");
    header('ETag: ' . $hash);

    unset($expire, $hash);

} else {

    /*
     * In case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled
     */
    header('Expires: ' . gmdate("D, d M Y H:i:s", time()) . " GMT");

}

/*
 * Set Link header for shortlink.
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'shortlink' ])) {

    header('Link:<' . $wc_mfpc_values[ 'meta' ][ 'shortlink' ] . '>; rel=shortlink');

}

/*
 * Set Last-Modified header.
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'lastmodified' ])) {

    header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values[ 'meta' ][ 'lastmodified' ]) . " GMT");

}

/*
 * Set X-Pingback header.
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'pingback' ]) && ! empty($wc_mfpc_config_array[ 'pingback_header' ])) {

	header('X-Pingback: ' . $wc_mfpc_values[ 'meta' ][ 'pingback' ]);

}

/*
 * Set X-Cache-Engine header.
 */
if (! empty($wc_mfpc_config_array[ 'response_header' ])) {

	header( 'X-Cache-Engine: WC-MFPC with Memcached via PHP');

}

/*
 * ToDo: Remove! Testing only.
 */
error_log("Serving data");

echo $wc_mfpc_values[ 'data' ];

flush();
die();

/*
 * END SERVING CACHED PAGE
 *
 * ---------------------------------------------------------------------------------------------------------------------
 *
 * BEGIN GENERATING CACHE ENTRY
 */

/**
 * Initializes the caching function.
 *
 * @return void
 *
 */
function wc_mfpc_start()
{
	ob_start('wc_mfpc_output_buffer_callback');
}

/**
 * Callback function for WordPress redirect urls.
 *
 * @see \InvincibleBrands\WcMfpc\WcMfpc::init()
 *
 * @param string $redirectUrl
 *
 * @return string
 */
function wc_mfpc_redirect_callback ($redirectUrl = '')
{
	global $wc_mfpc_redirect;

	$wc_mfpc_redirect = $redirectUrl;

	return $redirectUrl;
}

/**
 * Write cache function, called when page generation ended.
 *
 * @param string $content
 *
 * @return string
 */
function wc_mfpc_output_buffer_callback($content = '')
{
    /**
     * @var string                               $wc_mfpc_permalink
     * @var InvincibleBrands\WcMfpc\Config|array $wc_mfpc_config_array
     * @var InvincibleBrands\WcMfpc\Memcached    $wc_mfpc_memcached
     * @var string                               $wc_mfpc_redirect
     * @var WP_Query                             $wp_query
     * @var WP_Post                              $post
     */
	global $wc_mfpc_permalink, $wc_mfpc_config_array, $wc_mfpc_memcached, $wc_mfpc_redirect, $wp_query, $post;

    $content = trim($content);

    /*
     * Skip if any of these essential conditions are met.
     */
	if (
        /**
         * Filter to skip storing data in cache entirely.
         * This allows 3rd parties to skip caching in custom scenarios.
         *
         * @param bool $skip       Set TRUE to skip caching.
         * @param string $content  The page content.
         *
         * @return bool $skip
         */
        (bool) apply_filters('wc_mfpc_custom_skip_caching', $skip = false, $content)
        || empty($content)
	    || empty($wp_query)
        || (stripos($content, '</body>') === false && stripos($content, '</rss>') === false)
    ) {

		return $content;
    }

    /*
     * Skip if current user has a admin-bar. Is this the case, set the cookie to skip cache directly in the future.
     */
    if (is_admin_bar_showing()) {

        error_log('skipping administrator!');
        setcookie('wc-mfpc-nocache', 1, time() + 86400);

        return $content;
    }

	$config    = &$wc_mfpc_config_array;
	$cacheMeta = [];

	/*if (is_product()) {

        error_log('caching product category');
        $cacheMeta[ 'type' ] = 'product';

        if (! empty ($post->post_modified_gmt)) {

            $cacheMeta[ 'lastmodified' ] = strtotime($post->post_modified_gmt);

        }

    } elseif (is_product_category()) {

	    error_log('caching product category');

    } elseif (is_shop()) {

        error_log('caching shop');

    } else*/if ($wp_query->is_home() || $wp_query->is_feed()) {

		if ($wp_query->is_home()) {

            $cacheMeta[ 'type' ] = 'home';

		} elseif($wp_query->is_feed()) {

            $cacheMeta[ 'type' ] = 'feed';

		}

		error_log( 'Getting latest post for for home & feed');

		/*
		 * Get newest post and set last modified accordingly
		 */
        $recent_post = wp_get_recent_posts([
            'numberposts' => 1,
            'orderby'     => 'modified',
            'order'       => 'DESC',
            'post_status' => 'publish',
        ], OBJECT );

		if (! empty($recent_post)) {

			$recent_post = array_pop($recent_post);

            if (! empty ($recent_post->post_modified_gmt)) {

                $cacheMeta[ 'lastmodified' ] = strtotime($recent_post->post_modified_gmt);

			}

		}

	} elseif ($wp_query->is_archive()) {

        $cacheMeta[ 'type' ] = 'archive';

		if (! empty($wp_query->tax_query)) {

			error_log( 'Getting latest post for taxonomy: ' . json_encode($wp_query->tax_query));

            $recent_post = get_posts([
                'numberposts' => 1,
                'orderby'     => 'modified',
                'order'       => 'DESC',
                'post_status' => 'publish',
                'tax_query'   => $wp_query->tax_query,
            ]);

            if (! empty($recent_post)) {

				$recent_post = array_pop($recent_post);

                if (! empty ($recent_post->post_modified_gmt)) {

                    $cacheMeta[ 'lastmodified' ] = strtotime($recent_post->post_modified_gmt);

				}

			}

		}

	} elseif ($wp_query->is_single() || $wp_query->is_page()) {

        $cacheMeta[ 'type' ] = 'single';

		/*
		 * Check if post is available. If made with archive, last listed post can make this go bad.
		 */
		if (! empty($post) && ! empty($post->post_modified_gmt)) {

            $cacheMeta[ 'lastmodified' ] = strtotime($post->post_modified_gmt);

			/*
			 * get shortlink, if possible
			 */
			if (function_exists('wp_get_shortlink')) {

                $shortlink = wp_get_shortlink();

                if (! empty ($shortlink)) {

                    $cacheMeta[ 'shortlink' ] = $shortlink;

                }

            }

		}

	} else {

        $cacheMeta[ 'type' ] = 'unknown';

	}

    if ($cacheMeta[ 'type' ] !== 'unknown') {

        if (! empty($config[ 'browsercache' ])) {

            $cacheMeta[ 'expire' ] = time() + $config[ 'browsercache' ];

        }

	}

    if ($wp_query->is_404()) {

        $cacheMeta[ 'status' ] = 404;

    }

	/*
	 * Check if redirect must be set
	 */
    if ($wc_mfpc_redirect !== null) {

        $cacheMeta[ 'redirect' ] = $wc_mfpc_redirect;

    }

    $cacheMeta[ 'mime' ] = 'text/html;charset=';

    /*
	 * Change header for Feeds to XML. Default = HTML
	 */
    if ($wp_query->is_feed()) {

        $cacheMeta[ 'mime' ] = 'text/xml;charset=';

    }

	/*
	 * Add charset to complete mime-type.
	 */
    $cacheMeta[ 'mime' ] = $cacheMeta[ 'mime' ] . $config[ 'charset' ];

	/*
	 * Store pingback url if pingbacks are enabled
	 */
    if (get_option('default_ping_status') === 'open') {

        $cacheMeta[ 'pingback' ] = get_bloginfo('pingback_url');

    }

	$cacheContent = $content;

	/**
	 * Allows to edit the page content before it is stored in cache.
	 * This hook allows 3rd parties to edit the meta data right before it is about to be stored in the cache. This
     * could be useful for alterations like minification.
	 *
	 * @param string $cacheContent  The content to be stored in cache.
     *
     * @return string $cacheContent
	 */
    $cacheContent = (string) apply_filters('wc_mfpc_custom_cache_content', $cacheContent);

    $keyData = $wc_mfpc_memcached->buildKey($wc_mfpc_permalink);
    $wc_mfpc_memcached->set($keyData, $cacheContent);

	/**
	 * Allows to edit the meta data before it is stored in cache.
	 * This hook allows 3rd parties to edit the page meta data right before it is about to be stored in the cache. This
     * could be useful for alterations like different browsercache expire times.
	 *
	 * @param string $cacheMeta  The meta to be stored in cache.
     *
     * @return string $cacheMeta
	 */
    $cacheMeta = (array) apply_filters('wc_mfpc_custom_cache_meta', $cacheMeta);

    $keyMeta = $wc_mfpc_memcached->buildKey($wc_mfpc_permalink, 'meta');
    $wc_mfpc_memcached->set($keyMeta, $cacheMeta);

    if (! empty($cacheMeta[ 'status' ]) && $cacheMeta[ 'status' ] === 404) {

        header("HTTP/1.1 404 Not Found");

    } else {

        header("HTTP/1.1 200 OK");

    }

	return $content;
}

/*
 * END GENERATING CACHE ENTRY-------------------------------------------------------------------------------------------
 */
