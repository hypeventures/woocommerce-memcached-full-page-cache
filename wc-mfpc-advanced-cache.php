<?php

if (! defined('ABSPATH')) { exit; }

/**
 * advanced cache worker of WordPress plugin WP-FFPC
 */

function __wp_ffpc_debug__ ( $text ) {
	if ( defined('WP_FFPC__DEBUG_MODE') && WP_FFPC__DEBUG_MODE == true)
		error_log ( 'WP_FFPC_ACache' . ': ' . $text );
}

/* check for WP cache enabled*/
if ( !defined('WP_CACHE') || WP_CACHE != true ) {
	__wp_ffpc_debug__('WP_CACHE is not true');
	return false;
}

/* no cache for post request (comments, plugins and so on) */
if ($_SERVER["REQUEST_METHOD"] == 'POST') {
	__wp_ffpc_debug__('POST requests are never cached');
	return false;
}

/**
 * Try to avoid enabling the cache if sessions are managed
 * with request parameters and a session is active
 */
if (defined('SID') && SID != '') {
	__wp_ffpc_debug__('SID found, skipping cache');
	return false;
}

/* check for config */
if (!isset(WC_MFPC_CONFIG)) {
	__wp_ffpc_debug__('wp_ffpc_config variable not found');
	return false;
}

/* request uri */
$wp_ffpc_uri = $_SERVER['REQUEST_URI'];

/* no cache for robots.txt */
if ( stripos($wp_ffpc_uri, 'robots.txt') ) {
	__wp_ffpc_debug__ ( 'Skippings robots.txt hit');
	return false;
}

/* multisite files can be too large for memcached */
if ( function_exists('is_multisite') && stripos($wp_ffpc_uri, '/files/') && is_multisite() ) {
	__wp_ffpc_debug__ ( 'Skippings multisite /files/ hit');
	return false;
}

/* check if config is network active: use network config */
if (!empty ( WC_MFPC_CONFIG['network'] ) ) {
	WC_MFPC_CONFIG = WC_MFPC_CONFIG['network'];
	__wp_ffpc_debug__('using "network" level config');
}
/* check if config is active for site : use site config */
elseif ( !empty ( WC_MFPC_CONFIG[ $_SERVER['HTTP_HOST'] ] ) ) {
	WC_MFPC_CONFIG = WC_MFPC_CONFIG[ $_SERVER['HTTP_HOST'] ];
	__wp_ffpc_debug__("using {$_SERVER['HTTP_HOST']} level config");
}
/* plugin config not found :( */
else {
	__wp_ffpc_debug__("no usable config found");
	return false;
}

/* no cache for WooCommerce URL patterns */
if ( isset(WC_MFPC_CONFIG['nocache_woocommerce']) && !empty(WC_MFPC_CONFIG['nocache_woocommerce']) &&
     isset(WC_MFPC_CONFIG['nocache_woocommerce_url']) && trim(WC_MFPC_CONFIG['nocache_woocommerce_url']) ) {
	$pattern = sprintf('#%s#', trim(WC_MFPC_CONFIG['nocache_woocommerce_url']));
	if ( preg_match($pattern, $wp_ffpc_uri) ) {
		__wp_ffpc_debug__ ( "Cache exception based on WooCommenrce URL regex pattern matched, skipping");
		return false;
	}
}

/* no cache for uri with query strings, things usually go bad that way */
if ( isset(WC_MFPC_CONFIG['nocache_dyn']) && !empty(WC_MFPC_CONFIG['nocache_dyn']) && stripos($wp_ffpc_uri, '?') !== false ) {
	__wp_ffpc_debug__ ( 'Dynamic url cache is disabled ( url with "?" ), skipping');
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
					__wp_ffpc_debug__ ( "Cookie exception matched: {$n}, skipping");
					return false;
				}
			}
		}
	}
}

/* no cache for excluded URL patterns */
if ( isset(WC_MFPC_CONFIG['nocache_url']) && trim(WC_MFPC_CONFIG['nocache_url']) ) {
	$pattern = sprintf('#%s#', trim(WC_MFPC_CONFIG['nocache_url']));
	if ( preg_match($pattern, $wp_ffpc_uri) ) {
		__wp_ffpc_debug__ ( "Cache exception based on URL regex pattern matched, skipping");
		return false;
	}
}

/* canonical redirect storage */
$wp_ffpc_redirect = null;
/* fires up the backend storage array with current config */
include_once ('wp-ffpc-backend.php');
$backend_class = 'WP_FFPC_Backend_' . WC_MFPC_CONFIG['cache_type'];
$wp_ffpc_backend = new $backend_class ( WC_MFPC_CONFIG );

//$wp_ffpc_backend = new WP_FFPC_Backend( WC_MFPC_CONFIG );

/* no cache for for logged in users unless it's set
   identifier cookies are listed in backend as var for easier usage
*/
if ( !isset(WC_MFPC_CONFIG['cache_loggedin']) || WC_MFPC_CONFIG['cache_loggedin'] == 0 || empty(WC_MFPC_CONFIG['cache_loggedin']) ) {

	foreach ($_COOKIE as $n=>$v) {
		foreach ( $wp_ffpc_backend->cookies as $nocache_cookie ) {
			if( strpos( $n, $nocache_cookie ) === 0 ) {
				__wp_ffpc_debug__ ( "No cache for cookie: {$n}, skipping");
				return false;
			}
		}
	}
}

/* will store time of page generation */
$wp_ffpc_gentime = 0;

/* backend connection failed, no caching :( */
if ( $wp_ffpc_backend->status() === false ) {
	__wp_ffpc_debug__ ( "Backend offline");
	return false;
}

/* try to get data & meta keys for current page */
$wp_ffpc_keys = array ( 'meta' => WC_MFPC_CONFIG['prefix_meta'], 'data' => WC_MFPC_CONFIG['prefix_data'] );
$wp_ffpc_values = array();

__wp_ffpc_debug__ ( "Trying to fetch entries");

foreach ( $wp_ffpc_keys as $internal => $key ) {
	$key = $wp_ffpc_backend->key ( $key );
	$value = $wp_ffpc_backend->get ( $key );

	if ( ! $value ) {
		__wp_ffpc_debug__("No cached data found");
		/* does not matter which is missing, we need both, if one fails, no caching */
		wp_ffpc_start();
		return;
	}
	else {
		/* store results */
		$wp_ffpc_values[ $internal ] = $value;
		__wp_ffpc_debug__('Got value for ' . $internal);
	}
}

/* serve cache 404 status */
if ( isset( $wp_ffpc_values['meta']['status'] ) &&  $wp_ffpc_values['meta']['status'] == 404 ) {
	__wp_ffpc_debug__("Serving 404");
	header("HTTP/1.1 404 Not Found");
	/* if I kill the page serving here, the 404 page will not be showed at all, so we do not do that
	 * flush();
	 * die();
	 */
}

/* server redirect cache */
if ( isset( $wp_ffpc_values['meta']['redirect'] ) && $wp_ffpc_values['meta']['redirect'] ) {
	__wp_ffpc_debug__("Serving redirect to {$wp_ffpc_values['meta']['redirect']}");
	header('Location: ' . $wp_ffpc_values['meta']['redirect'] );
	/* cut the connection as fast as possible */
	flush();
	die();
}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if ( array_key_exists( "HTTP_IF_MODIFIED_SINCE" , $_SERVER ) && !empty( $wp_ffpc_values['meta']['lastmodified'] ) ) {
	$if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
	/* check is cache is still valid */
	if ( $if_modified_since >= $wp_ffpc_values['meta']['lastmodified'] ) {
		__wp_ffpc_debug__("Serving 304 Not Modified");
		header("HTTP/1.0 304 Not Modified");
		/* connection cut for faster serving */
		flush();
		die();
	}
}

/*** SERVING CACHED PAGE ***/

/* if we reach this point it means data was found & correct, serve it */
if (!empty ( $wp_ffpc_values['meta']['mime'] ) )
	header('Content-Type: ' . $wp_ffpc_values['meta']['mime']);

/* set expiry date */
if (isset($wp_ffpc_values['meta']['expire']) && !empty ( $wp_ffpc_values['meta']['expire'] ) ) {
	$hash = md5 ( $wp_ffpc_uri . $wp_ffpc_values['meta']['expire'] );

	switch ($wp_ffpc_values['meta']['type']) {
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
	header('Expires: ' . gmdate("D, d M Y H:i:s", $wp_ffpc_values['meta']['expire'] ) . " GMT");
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
if (isset($wp_ffpc_values['meta']['shortlink']) && !empty ( $wp_ffpc_values['meta']['shortlink'] ) )
	header( 'Link:<'. $wp_ffpc_values['meta']['shortlink'] .'>; rel=shortlink' );

/* if last modifications were set (for posts & pages) */
if (isset($wp_ffpc_values['meta']['lastmodified']) && !empty($wp_ffpc_values['meta']['lastmodified']) )
	header( 'Last-Modified: ' . gmdate("D, d M Y H:i:s", $wp_ffpc_values['meta']['lastmodified'] ). " GMT" );

/* pingback urls, if existx */
if ( isset($wp_ffpc_values['meta']['pingback']) && !empty( $wp_ffpc_values['meta']['pingback'] ) && isset(WC_MFPC_CONFIG['pingback_header']) && WC_MFPC_CONFIG['pingback_header'] )
	header( 'X-Pingback: ' . $wp_ffpc_values['meta']['pingback'] );

/* for debugging */
if ( isset(WC_MFPC_CONFIG['response_header']) && WC_MFPC_CONFIG['response_header'] )
	header( 'X-Cache-Engine: WP-FFPC with ' . WC_MFPC_CONFIG['cache_type'] .' via PHP');

/* HTML data */
if ( isset(WC_MFPC_CONFIG['generate_time']) && WC_MFPC_CONFIG['generate_time'] == '1' && stripos($wp_ffpc_values['data'], '</body>') ) {
	$mtime = explode ( " ", microtime() );
	$wp_ffpc_gentime = ( $mtime[1] + $mtime[0] ) - $wp_ffpc_gentime;

	$insertion = "\n<!-- WP-FFPC cache output stats\n\tcache engine: ". WC_MFPC_CONFIG['cache_type'] ."\n\tUNIX timestamp: ". time() . "\n\tdate: ". date( 'c' ) . "\n\tfrom server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
	$index = stripos( $wp_ffpc_values['data'] , '</body>' );

	$wp_ffpc_values['data'] = substr_replace( $wp_ffpc_values['data'], $insertion, $index, 0);
}

__wp_ffpc_debug__("Serving data");
echo trim($wp_ffpc_values['data']);

flush();
die();

/*** END SERVING CACHED PAGE ***/


/*** GENERATING CACHE ENTRY ***/
/**
 * starts caching function
 *
 */
function wp_ffpc_start( ) {
	/* set start time */
	global $wp_ffpc_gentime;
	$mtime = explode ( " ", microtime() );
	$wp_ffpc_gentime = $mtime[1] + $mtime[0];

	/* start object "colleting" and pass it the the actual storer function  */
	ob_start('wp_ffpc_callback');
}

/**
 * callback function for WordPress redirect urls
 *
 */
function wp_ffpc_redirect_callback ($redirect_url, $requested_url) {
	global $wp_ffpc_redirect;
	$wp_ffpc_redirect = $redirect_url;
	return $redirect_url;
}

/**
 * write cache function, called when page generation ended
 */
function wp_ffpc_callback( $buffer ) {
	/* use global config */
	global WC_MFPC_CONFIG;
	/* backend was already set up, try to use it */
	global $wp_ffpc_backend;
	/* check is it's a redirect */
	global $wp_ffpc_redirect;

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
		__wp_ffpc_debug__ ( sprintf("Testing comment with pattern: %s", $pattern));
		if ( preg_match($pattern, $buffer) ) {
			__wp_ffpc_debug__ ( "Cache exception based on content regex pattern matched, skipping");
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

		__wp_ffpc_debug__( 'Getting latest post for for home & feed');
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
			__wp_ffpc_debug__( 'Getting latest post for taxonomy: ' . json_encode($wp_query->tax_query));

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
	if ( $wp_ffpc_redirect != null)
		$meta['redirect'] =  $wp_ffpc_redirect;

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
		global $wp_ffpc_gentime;
		$mtime = explode ( " ", microtime() );
		$wp_ffpc_gentime = ( $mtime[1] + $mtime[0] )- $wp_ffpc_gentime;

		$insertion = "\n<!-- WP-FFPC cache generation stats" . "\n\tgeneration time: ". round( $wp_ffpc_gentime, 3 ) ." seconds\n\tgeneraton UNIX timestamp: ". time() . "\n\tgeneraton date: ". date( 'c' ) . "\n\tgenerator server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
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
	$to_store = apply_filters( 'wp-ffpc-to-store', $to_store );

	$prefix_meta = $wp_ffpc_backend->key ( WC_MFPC_CONFIG['prefix_meta'] );
	$wp_ffpc_backend->set ( $prefix_meta, $meta );

	$prefix_data = $wp_ffpc_backend->key ( WC_MFPC_CONFIG['prefix_data'] );
	$wp_ffpc_backend->set ( $prefix_data , $to_store );

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
