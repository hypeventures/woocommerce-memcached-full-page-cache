<?php
/*
 * advanced cache worker of WordPress plugin WC-MFPC
 */

if (! defined('ABSPATH')) { exit; }

include_once 'vendor\autoload.php';

error_log('worker running');

/* check for WP cache enabled*/
if (! defined('WP_CACHE') || WP_CACHE != true) {

    error_log('WP_CACHE is not true');

    return false;
}

/* no cache for post request (comments, plugins and so on) */
if ($_SERVER[ "REQUEST_METHOD" ] == 'POST') {

    error_log('POST requests are never cached');

    return false;
}

/*
 * Try to avoid enabling the cache if sessions are managed
 * with request parameters and a session is active
 */
if (defined('SID') && SID != '') {

    error_log('SID found, skipping cache');

    return false;
}

/* check for config */
if (! isset($wcMfpcConfig)) {

    error_log('$wcMfpcConfig variable not found');

    return false;
}

/* request uri */
$wc_mfpc_uri = $_SERVER[ 'REQUEST_URI' ];

/* no cache for robots.txt */
if (stripos($wc_mfpc_uri, 'robots.txt')) {

    error_log('Skippings robots.txt hit');

    return false;
}

/* multisite files can be too large for memcached */
if (function_exists('is_multisite') && stripos($wc_mfpc_uri, '/files/') && is_multisite()) {

    error_log('Skippings multisite /files/ hit');

    return false;
}

/* check if config is network active: use network config */
if (! empty ($wcMfpcConfig[ 'network' ])) {

    #$wcMfpcConfig = $wcMfpcConfig['network']; ToDo: Remove the entire function if possible.
    error_log('using "network" level config');

/* check if config is active for site : use site config */
} elseif (! empty ($wcMfpcConfig[ $_SERVER[ 'HTTP_HOST' ] ])) {

    $wcMfpcConfig = $wcMfpcConfig[ $_SERVER[ 'HTTP_HOST' ] ];
    error_log("using {$_SERVER[ 'HTTP_HOST' ]} level config");

/* plugin config not found :( */
} else {

    error_log("no usable config found");

    return false;
}

/* no cache for WooCommerce URL patterns */
if (
    isset($wcMfpcConfig[ 'nocache_woocommerce' ])
    && ! empty($wcMfpcConfig[ 'nocache_woocommerce' ])
    && isset($wcMfpcConfig[ 'nocache_woocommerce_url' ])
    && trim($wcMfpcConfig[ 'nocache_woocommerce_url' ])
) {

    $pattern = sprintf('#%s#', trim($wcMfpcConfig[ 'nocache_woocommerce_url' ]));

    if (preg_match($pattern, $wc_mfpc_uri)) {

        error_log("Cache exception based on WooCommenrce URL regex pattern matched, skipping");

        return false;
    }

}

/* no cache for uri with query strings, things usually go bad that way */
if (isset($wcMfpcConfig[ 'nocache_dyn' ]) && ! empty($wcMfpcConfig[ 'nocache_dyn' ]) && stripos($wc_mfpc_uri, '?') !== false) {

    error_log('Dynamic url cache is disabled ( url with "?" ), skipping');

    return false;
}

/* check for cookies that will make us not cache the content, like logged in WordPress cookie */
if (! empty($wcMfpcConfig[ 'nocache_cookies' ])) {

    $nocache_cookies = array_map('trim', explode(",", $wcMfpcConfig[ 'nocache_cookies' ]));

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

/* no cache for excluded URL patterns */
if (isset($wcMfpcConfig[ 'nocache_url' ]) && trim($wcMfpcConfig[ 'nocache_url' ])) {

    $pattern = sprintf('#%s#', trim($wcMfpcConfig[ 'nocache_url' ]));

    if (preg_match($pattern, $wc_mfpc_uri)) {

        error_log("Cache exception based on URL regex pattern matched, skipping");

        return false;
    }

}

/* canonical redirect storage */
$wc_mfpc_redirect = null;
/* fires up the backend storage array with current config */
$wc_mfpc_backend = new \InvincibleBrands\WcMfpc\Memcached($wcMfpcConfig);

/*
 * no cache for for logged in users unless it's set identifier cookies are listed in backend as var for easier usage
 */
if (empty($wcMfpcConfig[ 'cache_loggedin' ])) {

    foreach ($_COOKIE as $n => $v) {

        foreach ($wc_mfpc_backend->cookies as $nocache_cookie) {

            if (strpos($n, $nocache_cookie) === 0) {

                error_log("No cache for cookie: {$n}, skipping");

                return false;
            }

        }

    }

} elseif(current_user_can('administrator')) {

    error_log('No cache for administrators');

}

/* will store time of page generation */
$wc_mfpc_gentime = 0;

/* backend connection failed, no caching :( */
if ($wc_mfpc_backend->status() === false) {

    error_log("Backend offline");

    return false;
}

/* try to get data & meta keys for current page */
$wc_mfpc_keys   = [ 'meta' => $wcMfpcConfig[ 'prefix_meta' ], 'data' => $wcMfpcConfig[ 'prefix_data' ] ];
$wc_mfpc_values = [];
error_log("Trying to fetch entries");

foreach ($wc_mfpc_keys as $internal => $key) {

    $key   = $wc_mfpc_backend->key($key);
    $value = $wc_mfpc_backend->get($key);

    if (! $value) {

        error_log("No cached data found");
        /* does not matter which is missing, we need both, if one fails, no caching */
        wc_mfpc_start();

        return;
    } else {

        /* store results */
        $wc_mfpc_values[ $internal ] = $value;
        error_log('Got value for ' . $internal);

    }

}

/* serve cache 404 status */
if (isset($wc_mfpc_values[ 'meta' ][ 'status' ]) && $wc_mfpc_values[ 'meta' ][ 'status' ] == 404) {

    error_log("Serving 404");
    header("HTTP/1.1 404 Not Found");
    /* if I kill the page serving here, the 404 page will not be showed at all, so we do not do that
     * flush();
     * die();
     */

}
/* server redirect cache */
if (isset($wc_mfpc_values[ 'meta' ][ 'redirect' ]) && $wc_mfpc_values[ 'meta' ][ 'redirect' ]) {

    error_log("Serving redirect to {$wc_mfpc_values['meta']['redirect']}");
    header('Location: ' . $wc_mfpc_values[ 'meta' ][ 'redirect' ]);
    /* cut the connection as fast as possible */
    flush();
    die();

}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if (array_key_exists("HTTP_IF_MODIFIED_SINCE", $_SERVER) && ! empty($wc_mfpc_values[ 'meta' ][ 'lastmodified' ])) {

    $if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER[ "HTTP_IF_MODIFIED_SINCE" ]));

    /* check is cache is still valid */
    if ($if_modified_since >= $wc_mfpc_values[ 'meta' ][ 'lastmodified' ]) {

        error_log("Serving 304 Not Modified");
        header("HTTP/1.0 304 Not Modified");
        /* connection cut for faster serving */
        flush();
        die();

    }

}
/*
 * SERVING CACHED PAGE -------------------------------------------------------------------------------------------------
 */

/* if we reach this point it means data was found & correct, serve it */
if (! empty ($wc_mfpc_values[ 'meta' ][ 'mime' ])) {

    header('Content-Type: ' . $wc_mfpc_values[ 'meta' ][ 'mime' ]);

}

/* set expiry date */
if (isset($wc_mfpc_values[ 'meta' ][ 'expire' ]) && ! empty ($wc_mfpc_values[ 'meta' ][ 'expire' ])) {

    $hash = md5($wc_mfpc_uri . $wc_mfpc_values[ 'meta' ][ 'expire' ]);

    switch ($wc_mfpc_values[ 'meta' ][ 'type' ]) {

        case 'home':
        case 'feed':
            $expire = $wcMfpcConfig[ 'browsercache_home' ];
            break;
        case 'archive':
            $expire = $wcMfpcConfig[ 'browsercache_taxonomy' ];
            break;
        case 'single':
            $expire = $wcMfpcConfig[ 'browsercache' ];
            break;
        default:
            $expire = 0;

    }

    header('Cache-Control: public,max-age=' . $expire . ',s-maxage=' . $expire . ',must-revalidate');
    header('Expires: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values[ 'meta' ][ 'expire' ]) . " GMT");
    header('ETag: ' . $hash);
    unset($expire, $hash);

} else {

    /* in case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled */
    header('Expires: ' . gmdate("D, d M Y H:i:s", time()) . " GMT");
    /* if I set these, the 304 not modified will never, ever kick in, so not setting these
     * leaving here as a reminder why it should not be set */
    //header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
    //header('Pragma: no-cache');

}

/* if shortlinks were set */
if (isset($wc_mfpc_values['meta']['shortlink']) && !empty ( $wc_mfpc_values['meta']['shortlink'] ) ) {

	header( 'Link:<'. $wc_mfpc_values['meta']['shortlink'] .'>; rel=shortlink' );

}

/* if last modifications were set (for posts & pages) */
if (isset($wc_mfpc_values['meta']['lastmodified']) && !empty($wc_mfpc_values['meta']['lastmodified']) ) {

	header( 'Last-Modified: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values['meta']['lastmodified'] ). " GMT" );

}

/* pingback urls, if existx */
if ( isset($wc_mfpc_values['meta']['pingback']) && !empty( $wc_mfpc_values['meta']['pingback'] ) && isset($wcMfpcConfig['pingback_header']) && $wcMfpcConfig['pingback_header'] ) {

	header( 'X-Pingback: ' . $wc_mfpc_values['meta']['pingback'] );

}

/* for debugging */
if ( isset($wcMfpcConfig['response_header']) && $wcMfpcConfig['response_header'] ) {

	header( 'X-Cache-Engine: WC-MFPC with ' . $wcMfpcConfig['cache_type'] .' via PHP');

}

/* HTML data */
if ( isset($wcMfpcConfig['generate_time']) && $wcMfpcConfig['generate_time'] == '1' && stripos($wc_mfpc_values['data'], '</body>') ) {

	$mtime = explode ( " ", microtime() );
	$wc_mfpc_gentime = ( $mtime[1] + $mtime[0] ) - $wc_mfpc_gentime;

	$insertion = "\n<!-- WC-MFPC cache output stats\n\tcache engine: ". $wcMfpcConfig['cache_type'] ."\n\tUNIX timestamp: ". time() . "\n\tdate: ". date( 'c' ) . "\n\tfrom server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
	$index = stripos( $wc_mfpc_values['data'] , '</body>' );

	$wc_mfpc_values['data'] = substr_replace( $wc_mfpc_values['data'], $insertion, $index, 0);

}

error_log("Serving data");
echo trim($wc_mfpc_values['data']);

flush();
die();

/*** END SERVING CACHED PAGE ***/


/*** GENERATING CACHE ENTRY ***/
/**
 * starts caching function
 *
 */
function wc_mfpc_start( ) {
	/* set start time */
	global $wc_mfpc_gentime;

	$mtime = explode ( " ", microtime() );
	$wc_mfpc_gentime = $mtime[1] + $mtime[0];

	// ToDo: Check if this might be useful!!!
    if (! current_user_can('administrator') && ! is_admin()) {
    #if (! is_admin()) {

        show_admin_bar(false);

    }

	/* start object "colleting" and pass it the the actual storer function  */
	ob_start('wc_mfpc_callback');
}

/**
 * callback function for WordPress redirect urls
 *
 * @param $redirect_url
 * @param $requested_url
 *
 * @return mixed
 */
function wc_mfpc_redirect_callback ($redirect_url, $requested_url) {
	global $wc_mfpc_redirect;

	$wc_mfpc_redirect = $redirect_url;

	return $redirect_url;
}

/**
 * write cache function, called when page generation ended
 *
 * @param $buffer
 *
 * @return string
 */
function wc_mfpc_callback( $buffer ) {
	/* use global config */
	global $wcMfpcConfig;

	/* backend was already set up, try to use it */
	global $wc_mfpc_backend;

	/* check is it's a redirect */
	global $wc_mfpc_redirect;

	/* no is_home = error, WordPress functions are not availabe */
	if (!function_exists('is_home')) {

		return $buffer;
    }

	/* no <body> close tag = not HTML, also no <rss>, not feed, don't cache */
	if ( stripos($buffer, '</body>') === false && stripos($buffer, '</rss>') === false ) {

		return $buffer;
    }

	/* reset meta to solve conflicts */
	$meta = array();

	/* trim unneeded whitespace from beginning / ending of buffer */
	$buffer = trim( $buffer );

	/* Can be a trackback or other things without a body.
	   We do not cache them, WP needs to get those calls. */
	if (strlen($buffer) == 0) {

		return '';
    }

	if ( isset($wcMfpcConfig[ 'nocache_comment' ]) && !empty($wcMfpcConfig[ 'nocache_comment' ]) && trim($wcMfpcConfig[ 'nocache_comment' ])) {

		$pattern = sprintf('#%s#', trim($wcMfpcConfig['nocache_comment']));
		error_log ( sprintf("Testing comment with pattern: %s", $pattern));

		if ( preg_match($pattern, $buffer) ) {

			error_log ( "Cache exception based on content regex pattern matched, skipping");

			return $buffer;
		}

	}

	if ( is_home() || is_feed() ) {

		if (is_home()) {

			$meta['type'] = 'home';

		} elseif(is_feed()) {

			$meta['type'] = 'feed';

		}

		if (isset($wcMfpcConfig['browsercache_home']) && !empty($wcMfpcConfig['browsercache_home']) && $wcMfpcConfig['browsercache_home'] > 0) {

			$meta['expire'] = time() + $wcMfpcConfig['browsercache_home'];

		}

		error_log( 'Getting latest post for for home & feed');

		/* get newest post and set last modified accordingly */
		$args = array(
			'numberposts' => 1,
			'orderby' => 'modified',
			'order' => 'DESC',
			'post_status' => 'publish',
		);
		$recent_post = wp_get_recent_posts( $args, OBJECT );

		if ( !empty($recent_post)) {

			$recent_post = array_pop($recent_post);

			if (!empty ( $recent_post->post_modified_gmt ) ) {

				$meta['lastmodified'] = strtotime ( $recent_post->post_modified_gmt );

			}

		}

	} elseif ( is_archive() ) {

		$meta['type'] = 'archive';

		if (isset($wcMfpcConfig['browsercache_taxonomy']) && !empty($wcMfpcConfig['browsercache_taxonomy']) && $wcMfpcConfig['browsercache_taxonomy'] > 0) {

			$meta['expire'] = time() + $wcMfpcConfig['browsercache_taxonomy'];

		}

		global $wp_query;

		if ( null != $wp_query->tax_query && !empty($wp_query->tax_query)) {

			error_log( 'Getting latest post for taxonomy: ' . json_encode($wp_query->tax_query));

			$args = array(
				'numberposts' => 1,
				'orderby' => 'modified',
				'order' => 'DESC',
				'post_status' => 'publish',
				'tax_query' => $wp_query->tax_query,
			);
			$recent_post =  get_posts( $args, OBJECT );

			if ( !empty($recent_post)) {

				$recent_post = array_pop($recent_post);

				if (!empty ( $recent_post->post_modified_gmt ) ) {

					$meta['lastmodified'] = strtotime ( $recent_post->post_modified_gmt );

				}

			}

		}

	} elseif ( is_single() || is_page() ) {

		$meta['type'] = 'single';

		if (isset($wcMfpcConfig['browsercache']) && !empty($wcMfpcConfig['browsercache']) && $wcMfpcConfig['browsercache'] > 0) {

			$meta['expire'] = time() + $wcMfpcConfig['browsercache'];

		}

		/*
		 * try if post is available if made with archieve, last listed post can make this go bad
		 */
		global $post;

		if ( !empty($post) && !empty ( $post->post_modified_gmt ) ) {

			/* get last modification data */
			$meta['lastmodified'] = strtotime ( $post->post_modified_gmt );

			/* get shortlink, if possible */
			if (function_exists('wp_get_shortlink')) {

				$shortlink = wp_get_shortlink( );

				if (!empty ( $shortlink ) )

					$meta['shortlink'] = $shortlink;

			}

		}

	} else {

		$meta['type'] = 'unknown';

	}

	if ( $meta['type'] != 'unknown' ) {

		/* check if caching is disabled for page type */
		$nocache_key = 'nocache_'. $meta['type'];

		/* don't cache if prevented by rule */
		if ( $wcMfpcConfig[ $nocache_key ] == 1 ) {

			return $buffer;
		}

	}

	if ( is_404() ) {

		$meta['status'] = 404;

	}

	/* redirect page */
	if ( $wc_mfpc_redirect != null) {

		$meta['redirect'] =  $wc_mfpc_redirect;

	}

	/* feed is xml, all others forced to be HTML */
	if ( is_feed() ) {

		$meta['mime'] = 'text/xml;charset=';

	} else {

		$meta['mime'] = 'text/html;charset=';

	}

	/* set mimetype */
	$meta['mime'] = $meta['mime'] . $wcMfpcConfig['charset'];

	/* store pingback url if pingbacks are enabled */
	if ( get_option ( 'default_ping_status' ) == 'open' ) {

		$meta['pingback'] = get_bloginfo('pingback_url');

	}

	$to_store = $buffer;

	/* add generation info is option is set, but only to HTML */
	if ( $wcMfpcConfig['generate_time'] == '1' && stripos($buffer, '</body>') ) {

		global $wc_mfpc_gentime;

		$mtime = explode ( " ", microtime() );
		$wc_mfpc_gentime = ( $mtime[1] + $mtime[0] )- $wc_mfpc_gentime;

		$insertion = "\n<!-- WC-MFPC cache generation stats" . "\n\tgeneration time: ". round( $wc_mfpc_gentime, 3 ) ." seconds\n\tgeneraton UNIX timestamp: ". time() . "\n\tgeneraton date: ". date( 'c' ) . "\n\tgenerator server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
		$index     = stripos( $buffer , '</body>' );
		$to_store  = substr_replace( $buffer, $insertion, $index, 0);

	}

	/**
	 * Allows to edit the content to be stored in cache.
	 *
	 * This hooks allows the user to edit the page content right before it is about
	 * to be stored in the cache. This could be useful for alterations like
	 * minification.
	 *
	 * @since 1.10.2
	 *
	 * @param string $to_store The content to be stored in cache.
	 */
	$to_store = apply_filters( 'wc-mfpc-to-store', $to_store );

	$prefix_meta = $wc_mfpc_backend->key ( $wcMfpcConfig['prefix_meta'] );
	$wc_mfpc_backend->set ( $prefix_meta, $meta );

	$prefix_data = $wc_mfpc_backend->key ( $wcMfpcConfig['prefix_data'] );
	$wc_mfpc_backend->set ( $prefix_data , $to_store );

	if ( !empty( $meta['status'] ) && $meta['status'] == 404 ) {

		header("HTTP/1.1 404 Not Found");

	} else {

		/* vital for nginx, make no problem at other places */
		header("HTTP/1.1 200 OK");

	}

	/* echoes HTML out */
	return trim($buffer);
}
/*** END GENERATING CACHE ENTRY ***/
