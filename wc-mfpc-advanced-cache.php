<?php

if (! defined('ABSPATH')) { exit; }

/**
 * advanced cache worker of WordPress plugin WC-MFPC
 */

function __wc_mfpc_debug__ ( $text ) {
	if ( defined('WC_MFPC__DEBUG_MODE') && WC_MFPC__DEBUG_MODE == true)
		error_log ( 'WC_MFPC_ACache' . ': ' . $text );
}

/* check for WP cache enabled*/
if ( !defined('WP_CACHE') || WP_CACHE != true ) {
	__wc_mfpc_debug__('WP_CACHE is not true');
	return false;
}

/* no cache for post request (comments, plugins and so on) */
if ($_SERVER["REQUEST_METHOD"] == 'POST') {
	__wc_mfpc_debug__('POST requests are never cached');
	return false;
}

/**
 * Try to avoid enabling the cache if sessions are managed
 * with request parameters and a session is active
 */
if (defined('SID') && SID != '') {
	__wc_mfpc_debug__('SID found, skipping cache');
	return false;
}

/* check for config */
if (!isset(WC_MFPC_CONFIG)) {
	__wc_mfpc_debug__('wc_mfpc_config variable not found');
	return false;
}

/* request uri */
$wc_mfpc_uri = $_SERVER['REQUEST_URI'];

/* no cache for robots.txt */
if ( stripos($wc_mfpc_uri, 'robots.txt') ) {
	__wc_mfpc_debug__ ( 'Skippings robots.txt hit');
	return false;
}

/* multisite files can be too large for memcached */
if ( function_exists('is_multisite') && stripos($wc_mfpc_uri, '/files/') && is_multisite() ) {
	__wc_mfpc_debug__ ( 'Skippings multisite /files/ hit');
	return false;
}

/* check if config is network active: use network config */
if (!empty ( WC_MFPC_CONFIG['network'] ) ) {
	WC_MFPC_CONFIG = WC_MFPC_CONFIG['network'];
	__wc_mfpc_debug__('using "network" level config');
}
/* check if config is active for site : use site config */
elseif ( !empty ( WC_MFPC_CONFIG[ $_SERVER['HTTP_HOST'] ] ) ) {
	WC_MFPC_CONFIG = WC_MFPC_CONFIG[ $_SERVER['HTTP_HOST'] ];
	__wc_mfpc_debug__("using {$_SERVER['HTTP_HOST']} level config");
}
/* plugin config not found :( */
else {
	__wc_mfpc_debug__("no usable config found");
	return false;
}

/* no cache for WooCommerce URL patterns */
if ( isset(WC_MFPC_CONFIG['nocache_woocommerce']) && !empty(WC_MFPC_CONFIG['nocache_woocommerce']) &&
     isset(WC_MFPC_CONFIG['nocache_woocommerce_url']) && trim(WC_MFPC_CONFIG['nocache_woocommerce_url']) ) {
	$pattern = sprintf('#%s#', trim(WC_MFPC_CONFIG['nocache_woocommerce_url']));
	if ( preg_match($pattern, $wc_mfpc_uri) ) {
		__wc_mfpc_debug__ ( "Cache exception based on WooCommenrce URL regex pattern matched, skipping");
		return false;
	}
}

/* no cache for uri with query strings, things usually go bad that way */
if ( isset(WC_MFPC_CONFIG['nocache_dyn']) && !empty(WC_MFPC_CONFIG['nocache_dyn']) && stripos($wc_mfpc_uri, '?') !== false ) {
	__wc_mfpc_debug__ ( 'Dynamic url cache is disabled ( url with "?" ), skipping');
	return false;
}

/* check for cookies that will make us not cache the content, like logged in WordPress cookie */
if ( isset(WC_MFPC_CONFIG['nocache_cookies']) && !empty(WC_MFPC_CONFIG['nocache_cookies']) ) {
	$nocache_cookies = array_map('trim',explode(",", WC_MFPC_CONFIG['nocache_cookies'] ) );

	if ( !empty( $nocache_cookies ) ) {
		foreach ($_COOKIE as $n=>$v) {
			/* check for any matches to user-added cookies to no-cache */
			foreach ( $nocache_cookies as $nocache_cookie ) {
				if( strpos( $n, $nocache_cookie ) === 0 ) {
					__wc_mfpc_debug__ ( "Cookie exception matched: {$n}, skipping");
					return false;
				}
			}
		}
	}
}

/* no cache for excluded URL patterns */
if ( isset(WC_MFPC_CONFIG['nocache_url']) && trim(WC_MFPC_CONFIG['nocache_url']) ) {
	$pattern = sprintf('#%s#', trim(WC_MFPC_CONFIG['nocache_url']));
	if ( preg_match($pattern, $wc_mfpc_uri) ) {
		__wc_mfpc_debug__ ( "Cache exception based on URL regex pattern matched, skipping");
		return false;
	}
}

/* canonical redirect storage */
$wc_mfpc_redirect = null;
/* fires up the backend storage array with current config */
include_once ('wc-mfpc-backend.php');
$backend_class = 'WC_MFPC_Backend_' . WC_MFPC_CONFIG['cache_type'];
$wc_mfpc_backend = new $backend_class ( WC_MFPC_CONFIG );

//$wc_mfpc_backend = new WC_MFPC_Backend( WC_MFPC_CONFIG );

/* no cache for for logged in users unless it's set
   identifier cookies are listed in backend as var for easier usage
*/
if ( !isset(WC_MFPC_CONFIG['cache_loggedin']) || WC_MFPC_CONFIG['cache_loggedin'] == 0 || empty(WC_MFPC_CONFIG['cache_loggedin']) ) {

	foreach ($_COOKIE as $n=>$v) {
		foreach ( $wc_mfpc_backend->cookies as $nocache_cookie ) {
			if( strpos( $n, $nocache_cookie ) === 0 ) {
				__wc_mfpc_debug__ ( "No cache for cookie: {$n}, skipping");
				return false;
			}
		}
	}
}

/* will store time of page generation */
$wc_mfpc_gentime = 0;

/* backend connection failed, no caching :( */
if ( $wc_mfpc_backend->status() === false ) {
	__wc_mfpc_debug__ ( "Backend offline");
	return false;
}

/* try to get data & meta keys for current page */
$wc_mfpc_keys = array ( 'meta' => WC_MFPC_CONFIG['prefix_meta'], 'data' => WC_MFPC_CONFIG['prefix_data'] );
$wc_mfpc_values = array();

__wc_mfpc_debug__ ( "Trying to fetch entries");

foreach ( $wc_mfpc_keys as $internal => $key ) {
	$key = $wc_mfpc_backend->key ( $key );
	$value = $wc_mfpc_backend->get ( $key );

	if ( ! $value ) {
		__wc_mfpc_debug__("No cached data found");
		/* does not matter which is missing, we need both, if one fails, no caching */
		wc_mfpc_start();
		return;
	}
	else {
		/* store results */
		$wc_mfpc_values[ $internal ] = $value;
		__wc_mfpc_debug__('Got value for ' . $internal);
	}
}

/* serve cache 404 status */
if ( isset( $wc_mfpc_values['meta']['status'] ) &&  $wc_mfpc_values['meta']['status'] == 404 ) {
	__wc_mfpc_debug__("Serving 404");
	header("HTTP/1.1 404 Not Found");
	/* if I kill the page serving here, the 404 page will not be showed at all, so we do not do that
	 * flush();
	 * die();
	 */
}

/* server redirect cache */
if ( isset( $wc_mfpc_values['meta']['redirect'] ) && $wc_mfpc_values['meta']['redirect'] ) {
	__wc_mfpc_debug__("Serving redirect to {$wc_mfpc_values['meta']['redirect']}");
	header('Location: ' . $wc_mfpc_values['meta']['redirect'] );
	/* cut the connection as fast as possible */
	flush();
	die();
}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if ( array_key_exists( "HTTP_IF_MODIFIED_SINCE" , $_SERVER ) && !empty( $wc_mfpc_values['meta']['lastmodified'] ) ) {
	$if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
	/* check is cache is still valid */
	if ( $if_modified_since >= $wc_mfpc_values['meta']['lastmodified'] ) {
		__wc_mfpc_debug__("Serving 304 Not Modified");
		header("HTTP/1.0 304 Not Modified");
		/* connection cut for faster serving */
		flush();
		die();
	}
}

/*** SERVING CACHED PAGE ***/

/* if we reach this point it means data was found & correct, serve it */
if (!empty ( $wc_mfpc_values['meta']['mime'] ) )
	header('Content-Type: ' . $wc_mfpc_values['meta']['mime']);

/* set expiry date */
if (isset($wc_mfpc_values['meta']['expire']) && !empty ( $wc_mfpc_values['meta']['expire'] ) ) {
	$hash = md5 ( $wc_mfpc_uri . $wc_mfpc_values['meta']['expire'] );

	switch ($wc_mfpc_values['meta']['type']) {
		case 'home':
		case 'feed':
			$expire = WC_MFPC_CONFIG['browsercache_home'];
			break;
		case 'archive':
			$expire = WC_MFPC_CONFIG['browsercache_taxonomy'];
			break;
		case 'single':
			$expire = WC_MFPC_CONFIG['browsercache'];
			break;
		default:
			$expire = 0;
	}

	header('Cache-Control: public,max-age='.$expire.',s-maxage='.$expire.',must-revalidate');
	header('Expires: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values['meta']['expire'] ) . " GMT");
	header('ETag: '. $hash);
	unset($expire, $hash);
}
else {
	/* in case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled */
	header('Expires: ' . gmdate("D, d M Y H:i:s", time() ) . " GMT");
	/* if I set these, the 304 not modified will never, ever kick in, so not setting these
	 * leaving here as a reminder why it should not be set */
	//header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
	//header('Pragma: no-cache');
}

/* if shortlinks were set */
if (isset($wc_mfpc_values['meta']['shortlink']) && !empty ( $wc_mfpc_values['meta']['shortlink'] ) )
	header( 'Link:<'. $wc_mfpc_values['meta']['shortlink'] .'>; rel=shortlink' );

/* if last modifications were set (for posts & pages) */
if (isset($wc_mfpc_values['meta']['lastmodified']) && !empty($wc_mfpc_values['meta']['lastmodified']) )
	header( 'Last-Modified: ' . gmdate("D, d M Y H:i:s", $wc_mfpc_values['meta']['lastmodified'] ). " GMT" );

/* pingback urls, if existx */
if ( isset($wc_mfpc_values['meta']['pingback']) && !empty( $wc_mfpc_values['meta']['pingback'] ) && isset(WC_MFPC_CONFIG['pingback_header']) && WC_MFPC_CONFIG['pingback_header'] )
	header( 'X-Pingback: ' . $wc_mfpc_values['meta']['pingback'] );

/* for debugging */
if ( isset(WC_MFPC_CONFIG['response_header']) && WC_MFPC_CONFIG['response_header'] )
	header( 'X-Cache-Engine: WC-MFPC with ' . WC_MFPC_CONFIG['cache_type'] .' via PHP');

/* HTML data */
if ( isset(WC_MFPC_CONFIG['generate_time']) && WC_MFPC_CONFIG['generate_time'] == '1' && stripos($wc_mfpc_values['data'], '</body>') ) {
	$mtime = explode ( " ", microtime() );
	$wc_mfpc_gentime = ( $mtime[1] + $mtime[0] ) - $wc_mfpc_gentime;

	$insertion = "\n<!-- WC-MFPC cache output stats\n\tcache engine: ". WC_MFPC_CONFIG['cache_type'] ."\n\tUNIX timestamp: ". time() . "\n\tdate: ". date( 'c' ) . "\n\tfrom server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
	$index = stripos( $wc_mfpc_values['data'] , '</body>' );

	$wc_mfpc_values['data'] = substr_replace( $wc_mfpc_values['data'], $insertion, $index, 0);
}

__wc_mfpc_debug__("Serving data");
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

	/* start object "colleting" and pass it the the actual storer function  */
	ob_start('wc_mfpc_callback');
}

/**
 * callback function for WordPress redirect urls
 *
 */
function wc_mfpc_redirect_callback ($redirect_url, $requested_url) {
	global $wc_mfpc_redirect;
	$wc_mfpc_redirect = $redirect_url;
	return $redirect_url;
}

/**
 * write cache function, called when page generation ended
 */
function wc_mfpc_callback( $buffer ) {
	/* use global config */
	global WC_MFPC_CONFIG;
	/* backend was already set up, try to use it */
	global $wc_mfpc_backend;
	/* check is it's a redirect */
	global $wc_mfpc_redirect;

	/* no is_home = error, WordPress functions are not availabe */
	if (!function_exists('is_home'))
		return $buffer;

	/* no <body> close tag = not HTML, also no <rss>, not feed, don't cache */
	if ( stripos($buffer, '</body>') === false && stripos($buffer, '</rss>') === false )
		return $buffer;

	/* reset meta to solve conflicts */
	$meta = array();

	/* trim unneeded whitespace from beginning / ending of buffer */
	$buffer = trim( $buffer );

	/* Can be a trackback or other things without a body.
	   We do not cache them, WP needs to get those calls. */
	if (strlen($buffer) == 0)
		return '';

	if ( isset(WC_MFPC_CONFIG[ 'nocache_comment' ]) && !empty(WC_MFPC_CONFIG[ 'nocache_comment' ]) && trim(WC_MFPC_CONFIG[ 'nocache_comment' ])) {
		$pattern = sprintf('#%s#', trim(WC_MFPC_CONFIG['nocache_comment']));
		__wc_mfpc_debug__ ( sprintf("Testing comment with pattern: %s", $pattern));
		if ( preg_match($pattern, $buffer) ) {
			__wc_mfpc_debug__ ( "Cache exception based on content regex pattern matched, skipping");
			return $buffer;
		}
	}

	if ( is_home() || is_feed() ) {
		if (is_home())
			$meta['type'] = 'home';
		elseif(is_feed())
			$meta['type'] = 'feed';

		if (isset(WC_MFPC_CONFIG['browsercache_home']) && !empty(WC_MFPC_CONFIG['browsercache_home']) && WC_MFPC_CONFIG['browsercache_home'] > 0) {
			$meta['expire'] = time() + WC_MFPC_CONFIG['browsercache_home'];
		}

		__wc_mfpc_debug__( 'Getting latest post for for home & feed');
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

	}
	elseif ( is_archive() ) {
		$meta['type'] = 'archive';
		if (isset(WC_MFPC_CONFIG['browsercache_taxonomy']) && !empty(WC_MFPC_CONFIG['browsercache_taxonomy']) && WC_MFPC_CONFIG['browsercache_taxonomy'] > 0) {
			$meta['expire'] = time() + WC_MFPC_CONFIG['browsercache_taxonomy'];
		}

		global $wp_query;

		if ( null != $wp_query->tax_query && !empty($wp_query->tax_query)) {
			__wc_mfpc_debug__( 'Getting latest post for taxonomy: ' . json_encode($wp_query->tax_query));

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

	}
	elseif ( is_single() || is_page() ) {
		$meta['type'] = 'single';
		if (isset(WC_MFPC_CONFIG['browsercache']) && !empty(WC_MFPC_CONFIG['browsercache']) && WC_MFPC_CONFIG['browsercache'] > 0) {
			$meta['expire'] = time() + WC_MFPC_CONFIG['browsercache'];
		}

		/* try if post is available
			if made with archieve, last listed post can make this go bad
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

	}
	else {
		$meta['type'] = 'unknown';
	}

	if ( $meta['type'] != 'unknown' ) {
		/* check if caching is disabled for page type */
		$nocache_key = 'nocache_'. $meta['type'];

		/* don't cache if prevented by rule */
		if ( WC_MFPC_CONFIG[ $nocache_key ] == 1 ) {
			return $buffer;
		}
	}

	if ( is_404() )
		$meta['status'] = 404;

	/* redirect page */
	if ( $wc_mfpc_redirect != null)
		$meta['redirect'] =  $wc_mfpc_redirect;

	/* feed is xml, all others forced to be HTML */
	if ( is_feed() )
		$meta['mime'] = 'text/xml;charset=';
	else
		$meta['mime'] = 'text/html;charset=';

	/* set mimetype */
	$meta['mime'] = $meta['mime'] . WC_MFPC_CONFIG['charset'];

	/* store pingback url if pingbacks are enabled */
	if ( get_option ( 'default_ping_status' ) == 'open' )
		$meta['pingback'] = get_bloginfo('pingback_url');

	$to_store = $buffer;

	/* add generation info is option is set, but only to HTML */
	if ( WC_MFPC_CONFIG['generate_time'] == '1' && stripos($buffer, '</body>') ) {
		global $wc_mfpc_gentime;
		$mtime = explode ( " ", microtime() );
		$wc_mfpc_gentime = ( $mtime[1] + $mtime[0] )- $wc_mfpc_gentime;

		$insertion = "\n<!-- WC-MFPC cache generation stats" . "\n\tgeneration time: ". round( $wc_mfpc_gentime, 3 ) ." seconds\n\tgeneraton UNIX timestamp: ". time() . "\n\tgeneraton date: ". date( 'c' ) . "\n\tgenerator server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
		$index = stripos( $buffer , '</body>' );

		$to_store = substr_replace( $buffer, $insertion, $index, 0);
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

	$prefix_meta = $wc_mfpc_backend->key ( WC_MFPC_CONFIG['prefix_meta'] );
	$wc_mfpc_backend->set ( $prefix_meta, $meta );

	$prefix_data = $wc_mfpc_backend->key ( WC_MFPC_CONFIG['prefix_data'] );
	$wc_mfpc_backend->set ( $prefix_data , $to_store );

	if ( !empty( $meta['status'] ) && $meta['status'] == 404 ) {
		header("HTTP/1.1 404 Not Found");
	}
	else {
		/* vital for nginx, make no problem at other places */
		header("HTTP/1.1 200 OK");
	}

	/* echoes HTML out */
	return trim($buffer);
}
/*** END GENERATING CACHE ENTRY ***/
