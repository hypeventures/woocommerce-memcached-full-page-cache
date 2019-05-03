<?php
/*
 * advanced cache worker of WordPress plugin WC-MFPC
 */

if (! defined('ABSPATH')) { exit; }

error_log('------------------------------------------------------------------------------------------------------------------------');
error_log('worker running');

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

    error_log('POST requests are never cached');

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
if (! isset($wc_mfpc_config_array)) {

    error_log('$wc_mfpc_config_array variable not found');

    return false;
}

/*
 * Set request uri
 */
$wc_mfpc_uri = $_SERVER[ 'REQUEST_URI' ];

/*
 * Check if config is available.
 * ToDo: check this on single blog pages (no multisite). Eventually tweaking is needed.
 */
if (empty($wc_mfpc_config_array[ $_SERVER[ 'HTTP_HOST' ] ])) {

    error_log("no usable config found");

    return false;
}

/*
 * Set Config
 */
$wc_mfpc_config_array = $wc_mfpc_config_array[ $_SERVER[ 'HTTP_HOST' ] ];
error_log("using {$_SERVER[ 'HTTP_HOST' ]} level config");

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

    foreach ($_COOKIE as $n => $v) {

        foreach ($nocache_cookies as $nocache_cookie) {

            if (strpos($n, $nocache_cookie) === 0) {

                error_log("No cache for cookie: {$n}, skipping");

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
 * no cache for excluded URL patterns
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

/*
 * Initialize getime storage
 */
$wc_mfpc_gentime = 0;

/*
 * Try to get data & meta keys for current page
 */
$wc_mfpc_keys   = [ 'meta' => $wc_mfpc_config_array[ 'prefix_meta' ], 'data' => $wc_mfpc_config_array[ 'prefix_data' ] ];
$wc_mfpc_values = [];
error_log("Trying to fetch entries");

foreach ($wc_mfpc_keys as $internal => $key) {

    $key   = $wc_mfpc_memcached->key($key);
    $value = $wc_mfpc_memcached->get($key);

    if (empty($value)) {


        /*
         * It does not matter which is missing, we need both, if one fails, no caching
         */
        wc_mfpc_start();
        error_log("No cached data found");

        return;

    } else {

        /*
         * store results
         */
        $wc_mfpc_values[ $internal ] = $value;
        error_log('Got value for ' . $internal);

    }

}

/*
 * Serve cache 404 status
 */
if (isset($wc_mfpc_values[ 'meta' ][ 'status' ]) && $wc_mfpc_values[ 'meta' ][ 'status' ] === 404) {

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

    error_log("Serving redirect to {$wc_mfpc_values['meta']['redirect']}");
    header('Location: ' . $wc_mfpc_values[ 'meta' ][ 'redirect' ]);

    /*
     * Cut the connection as fast as possible
     */
    flush();
    die();

}

/*
 * Page is already cached on client side (chrome likes to do this, anyway, it's quite efficient)
 */
if (isset($_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ]) && ! empty($wc_mfpc_values[ 'meta' ][ 'lastmodified' ])) {

    $if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER[ "HTTP_IF_MODIFIED_SINCE" ]));

    /*
     * check is cache is still valid
     */
    if ($if_modified_since >= $wc_mfpc_values[ 'meta' ][ 'lastmodified' ]) {

        error_log("Serving 304 Not Modified");
        header("HTTP/1.0 304 Not Modified");

        /*
         * Cut the connection as fast as possible
         */
        flush();
        die();

    }

}

/*
 * BEGIN SERVING CACHED PAGE -------------------------------------------------------------------------------------------
 */

/*
 * if we reach this point it means data was found & correct, serve it
 */
if (! empty ($wc_mfpc_values[ 'meta' ][ 'mime' ])) {

    header('Content-Type: ' . $wc_mfpc_values[ 'meta' ][ 'mime' ]);

}

/*
 * Set expiry date
 */
if (! empty ($wc_mfpc_values[ 'meta' ][ 'expire' ])) {

    $hash = md5($wc_mfpc_uri . $wc_mfpc_values[ 'meta' ][ 'expire' ]);

    switch ($wc_mfpc_values[ 'meta' ][ 'type' ]) {

        case 'home':
        case 'feed':
            $expire = $wc_mfpc_config_array[ 'browsercache_home' ];
            break;
        case 'archive':
            $expire = $wc_mfpc_config_array[ 'browsercache_taxonomy' ];
            break;
        case 'single':
            $expire = $wc_mfpc_config_array[ 'browsercache' ];
            break;
        default:
            $expire = 0;

    }

    header('Cache-Control: public,max-age=' . $expire . ',s-maxage=' . $expire . ',must-revalidate');
    header('Expires: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values[ 'meta' ][ 'expire' ]) . " GMT");
    header('ETag: ' . $hash);
    unset($expire, $hash);

} else {

    /*
     * In case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled
     */
    header('Expires: ' . gmdate("D, d M Y H:i:s", time()) . " GMT");

    /*
     * if these are set, the 304 not modified will never kick in, so these are not set.
     * leaving here as a reminder why it should not be set
     *
     * header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
     * header('Pragma: no-cache');
     */

}

/*
 * If shortlinks were set
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'shortlink' ])) {

    header('Link:<' . $wc_mfpc_values[ 'meta' ][ 'shortlink' ] . '>; rel=shortlink');

}

/*
 * If last modifications were set (for posts & pages)
 */
if (! empty($wc_mfpc_values[ 'meta' ][ 'lastmodified' ])) {

    header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values[ 'meta' ][ 'lastmodified' ]) . " GMT");

}

/*
 * If PingBack was set
 */
if (! empty( $wc_mfpc_values[ 'meta' ][ 'pingback' ] ) && ! empty($wc_mfpc_config_array['pingback_header' ])) {

	header('X-Pingback: ' . $wc_mfpc_values[ 'meta' ][ 'pingback' ]);

}

/*
 * If debugging header is should be set.
 */
if (! empty($wc_mfpc_config_array[ 'response_header' ])) {

	header( 'X-Cache-Engine: WC-MFPC with Memcached via PHP');

}

/*
 * HTML data
 */
if (! empty($wc_mfpc_config_array[ 'generate_time' ]) && stripos($wc_mfpc_values[ 'data' ], '</body>')) {

    $mtime           = explode(" ", microtime());
    $wc_mfpc_gentime = ($mtime[ 1 ] + $mtime[ 0 ]) - $wc_mfpc_gentime;

    $insertion = "\n<!-- WC-MFPC cache output stats\n\tcache engine: " . $wc_mfpc_config_array[ 'cache_type' ]
                 . "\n\tUNIX timestamp: " . time() . "\n\tdate: " . date('c') . "\n\tfrom server: "
                 . $_SERVER[ 'SERVER_ADDR' ] . " -->\n";

    $index                    = stripos($wc_mfpc_values[ 'data' ], '</body>');
    $wc_mfpc_values[ 'data' ] = substr_replace($wc_mfpc_values[ 'data' ], $insertion, $index, 0);

}

error_log("Serving data");
echo trim($wc_mfpc_values['data']);

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
    global $wc_mfpc_gentime;

    $mtime           = explode(" ", microtime());
    $wc_mfpc_gentime = $mtime[ 1 ] + $mtime[ 0 ];

	/*
	 * Start object "colleting" and pass it the the actual storing function
	 */
	ob_start('wc_mfpc_callback');
}

/**
 * Callback function for WordPress redirect urls.
 *
 * @see \InvincibleBrands\WcMfpc\WcMfpc::init()
 *
 * @param string $redirect_url
 * @param $requested_url
 *
 * @return mixed
 */
function wc_mfpc_redirect_callback ($redirect_url = '')
{
	global $wc_mfpc_redirect;

	$wc_mfpc_redirect = $redirect_url;

	return $redirect_url;
}

/**
 * Write cache function, called when page generation ended.
 *
 * @param $buffer
 *
 * @return string
 */
function wc_mfpc_callback( $buffer )
{
	global $wc_mfpc_config_array, $wc_mfpc_memcached, $wc_mfpc_redirect;

	$config = $wc_mfpc_config_array;

	/*
	 * If true, WordPress functions are not availabe => skip writing cache.
	 */
    if (! function_exists('is_home')) {

		return $buffer;
    }

    /*
     * Skip if current user has a admin-bar. Sets the cookie to skip cache directly in the future.
     */
    if (is_admin_bar_showing()) {

        error_log('------------------> skipping administrator!');
        setcookie('wc-mfpc-nocache', 1, time() + 86400);

        return $buffer;
    }

	/*
	 * If no <body> or <rss> close tag is found, don't cache
	 */
	if (stripos($buffer, '</body>') === false && stripos($buffer, '</rss>') === false) {

		return $buffer;
    }

	$meta   = [];
    $buffer = trim($buffer);

	/*
	 * Filter anything without a body => also skip caching.
	 */
	if (strlen($buffer) == 0) {

		return '';
    }

	if ( is_home() || is_feed() ) {

		if (is_home()) {

            $meta[ 'type' ] = 'home';

		} elseif(is_feed()) {

            $meta[ 'type' ] = 'feed';

		}

        if (! empty($config[ 'browsercache_home' ])) {

            $meta[ 'expire' ] = time() + $config[ 'browsercache_home' ];

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

                $meta[ 'lastmodified' ] = strtotime($recent_post->post_modified_gmt);

			}

		}

	} elseif ( is_archive() ) {

		$meta['type'] = 'archive';

		if (! empty($config['browsercache_taxonomy'])) {

			$meta['expire'] = time() + $config['browsercache_taxonomy'];

		}

		global $wp_query;

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

                    $meta[ 'lastmodified' ] = strtotime($recent_post->post_modified_gmt);

				}

			}

		}

	} elseif ( is_single() || is_page() ) {

        $meta[ 'type' ] = 'single';

        if (! empty($config[ 'browsercache' ])) {

            $meta[ 'expire' ] = time() + $config[ 'browsercache' ];

		}

		global $post;

		/*
		 * Check if post is available. If made with archive, last listed post can make this go bad.
		 */
		if (! empty($post) && ! empty($post->post_modified_gmt)) {

            $meta[ 'lastmodified' ] = strtotime($post->post_modified_gmt);

			/*
			 * get shortlink, if possible
			 */
			if (function_exists('wp_get_shortlink')) {

                $shortlink = wp_get_shortlink();

                if (! empty ($shortlink)) {

                    $meta[ 'shortlink' ] = $shortlink;

                }

            }

		}

	} else {

        $meta[ 'type' ] = 'unknown';

	}

    if ($meta[ 'type' ] != 'unknown') {

		/*
		 * check if caching is disabled for page type
		 */
        $nocache_key = 'nocache_' . $meta[ 'type' ];

		/*
		 * Skip caching if prevented for this meta type by rule
		 */
        if ($config[ $nocache_key ] == 1) {

			return $buffer;
		}

	}

    if (is_404()) {

        $meta[ 'status' ] = 404;

    }

	/*
	 * Check if redirect must be set
	 */
    if ($wc_mfpc_redirect != null) {

        $meta[ 'redirect' ] = $wc_mfpc_redirect;

    }

	/*
	 * Feed is xml, all others forced to be HTML
	 */
    if (is_feed()) {

        $meta[ 'mime' ] = 'text/xml;charset=';

    } else {

        $meta[ 'mime' ] = 'text/html;charset=';

    }

	/*
	 * Set mime-type.
	 */
    $meta[ 'mime' ] = $meta[ 'mime' ] . $config[ 'charset' ];

	/* store pingback url if pingbacks are enabled */
    if (get_option('default_ping_status') == 'open') {

        $meta[ 'pingback' ] = get_bloginfo('pingback_url');

    }

	$to_store = $buffer;

	/*
	 * add generation info is option is set, but only to HTML
	 */
    if (! empty($config[ 'generate_time' ]) && stripos($buffer, '</body>')) {

        global $wc_mfpc_gentime;

        $mtime           = explode(" ", microtime());
        $wc_mfpc_gentime = ($mtime[ 1 ] + $mtime[ 0 ]) - $wc_mfpc_gentime;

        $insertion = "\n<!-- WC-MFPC cache generation stats" . "\n\tgeneration time: " . round($wc_mfpc_gentime, 3) . " seconds\n\tgeneraton UNIX timestamp: " . time() . "\n\tgeneraton date: " . date('c') . "\n\tgenerator server: " . $_SERVER[ 'SERVER_ADDR' ] . " -->\n";
        $index     = stripos($buffer, '</body>');
        $to_store  = substr_replace($buffer, $insertion, $index, 0);
    }

	/**
	 * Allows to edit the content to be stored in cache.
	 * This hooks allows the user to edit the page content right before it is about to be stored in the cache. This
     * could be useful for alterations like minification.
	 *
	 * @param string $to_store The content to be stored in cache.
     *
     * @return string $to_store
	 */
    $to_store = apply_filters('wc-mfpc-to-store', $to_store);

    $prefix_meta = $wc_mfpc_memcached->key($config[ 'prefix_meta' ]);
    $wc_mfpc_memcached->set($prefix_meta, $meta);

    $prefix_data = $wc_mfpc_memcached->key($config[ 'prefix_data' ]);
    $wc_mfpc_memcached->set($prefix_data, $to_store);

    if (! empty($meta[ 'status' ]) && $meta[ 'status' ] === 404) {

        header("HTTP/1.1 404 Not Found");

    } else {

        /*
         * Vital header for nginx
         */
        header("HTTP/1.1 200 OK");

    }

	/*
	 * Return buffer to be echoed.
	 */
	return trim($buffer);
}

/*
 * END GENERATING CACHE ENTRY-------------------------------------------------------------------------------------------
 */
