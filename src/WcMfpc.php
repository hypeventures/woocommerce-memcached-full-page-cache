<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class WcMfpc
 *
 * @package InvincibleBrands\WcMfpc
 */
class WcMfpc extends WcMfpcAbstract
{

    const host_separator  = ',';
    const port_separator  = ':';
    const donation_id_key = 'hosted_button_id=';
    const global_config_var = 'WC_MFPC_CONFIG';
    const key_save = 'saved';
    const key_delete = 'deleted';
    const key_flush = 'flushed';
    const slug_flush = '&flushed=true';
    const key_precache = 'precached';
    const slug_precache = '&precached=true';
    const key_precache_disabled = 'precache_disabled';
    const slug_precache_disabled = '&precache_disabled=true';
    const precache_log = 'wc-mfpc-precache-log';
    const precache_timestamp = 'wc-mfpc-precache-timestamp';
    const precache_php = 'wc-mfpc-precache.php';
    const precache_id = 'wc-mfpc-precache-task';
    private $precache_message = '';
    private $precache_logfile = '';
    private $precache_phpfile = '';
    private $global_option = '';
    private $global_config_key = '';
    private $global_config = array();
    private $global_saved = false;
    private $acache_worker = '';
    private $acache = '';
    private $nginx_sample = '';
    private $acache_backend = '';
    private $button_flush;
    private $button_precache;
    private $select_cache_type = array ();
    private $select_invalidation_method = array ();
    private $select_schedules = array();
    private $valid_cache_type = array ();
    private $list_uri_vars = array();
    private $shell_function = false;
    private $shell_possibilities = array ();
    private $backend = NULL;
    private $scheduled = false;
    private $errors = array();

	/**
	 *
	 */
	public function plugin_post_construct () {
		static::debug ( __CLASS__, 'post_construct' );
		$this->plugin_url = plugin_dir_url( __FILE__ );
		$this->plugin_dir = plugin_dir_path( __FILE__ );

		$this->admin_css_handle = $this->plugin_constant . '-admin-css';
		$this->admin_css_url = $this->plugin_url . 'wp-admin.css';
	}

	/**
	 * init hook function runs before admin panel hook, themeing and options read
	 */
	public function plugin_pre_init() {
		static::debug ( __CLASS__, 'pre_init' );
		/* advanced cache "worker" file */
		$this->acache_worker = $this->plugin_dir . $this->plugin_constant . '-acache.php';
		/* WordPress advanced-cache.php file location */
		$this->acache = WP_CONTENT_DIR . '/advanced-cache.php';
		/* nginx sample config file */
		$this->nginx_sample = $this->plugin_dir . $this->plugin_constant . '-nginx-sample.conf';
		/* backend driver file */
		$this->acache_backend = $this->plugin_dir . $this->plugin_constant . '-engine.php';
		/* flush button identifier */
		$this->button_flush = $this->plugin_constant . '-flush';
		/* precache button identifier */
		$this->button_precache = $this->plugin_constant . '-precache';
		/* global options identifier */
		$this->global_option = $this->plugin_constant . '-global';
		/* precache log */
		$this->precache_logfile = sys_get_temp_dir() . '/' . self::precache_log;
		/* this is the precacher php worker file */
		$this->precache_phpfile = sys_get_temp_dir() . '/' . self::precache_php;
		/* search for a system function */
		$this->shell_possibilities = array ( 'shell_exec', 'exec', 'system', 'passthru' );
		/* get disabled functions list */
		$disabled_functions = array_map('trim', explode(',', ini_get('disable_functions') ) );

		foreach ( $this->shell_possibilities as $possible ) {
			if ( function_exists ($possible) && ! ( ini_get('safe_mode') || in_array( $possible, $disabled_functions ) ) ) {
				/* set shell function */
				$this->shell_function = $possible;
				break;
			}
		}

		if (!isset($_SERVER['HTTP_HOST']))
			$_SERVER['HTTP_HOST'] = '127.0.0.1';

		/* set global config key; here, because it's needed for migration */
		if ( $this->network ) {
			$this->global_config_key = 'network';
		}
		else {
			$sitedomain = parse_url( get_option('siteurl') , PHP_URL_HOST);
			if ( $_SERVER['HTTP_HOST'] != $sitedomain ) {
				$this->errors['domain_mismatch'] = sprintf( __("Domain mismatch: the site domain configuration (%s) does not match the HTTP_HOST (%s) variable in PHP. Please fix the incorrect one, otherwise the plugin may not work as expected.", 'wc-mfpc'), $sitedomain, $_SERVER['HTTP_HOST'] );
			}

			$this->global_config_key = $_SERVER['HTTP_HOST'];
		}

		/* cache type possible values array */
		$this->select_cache_type = [
			'memcached' => __( 'PHP Memcached' , 'wc-mfpc'),
    ];
		/* check for required functions / classes for the cache types */

		$this->valid_cache_type = [
			'memcached' => class_exists ( 'Memcached') ? true : false,
    ];

		/* invalidation method possible values array */
		$this->select_invalidation_method = array (
			0 => __( 'flush cache' , 'wc-mfpc'),
			1 => __( 'only modified post' , 'wc-mfpc'),
			2 => __( 'modified post and all related taxonomies' , 'wc-mfpc'),
		);

		/* map of possible key masks */
		$this->list_uri_vars = array (
			'$scheme' => __('The HTTP scheme (i.e. http, https).', 'wc-mfpc'),
			'$host' => __('Host in the header of request or name of the server processing the request if the Host header is not available.', 'wc-mfpc'),
			'$request_uri' => __('The *original* request URI as received from the client including the args', 'wc-mfpc'),
			'$remote_user' => __('Name of user, authenticated by the Auth Basic Module', 'wc-mfpc'),
			'$cookie_PHPSESSID' => __('PHP Session Cookie ID, if set ( empty if not )', 'wc-mfpc'),
			'$accept_lang' => __('First HTTP Accept Lang set in the HTTP request', 'wc-mfpc'),
			//'$cookie_COOKnginy IE' => __('Value of COOKIE', 'wc-mfpc'),
			//'$http_HEADER' => __('Value of HTTP request header HEADER ( lowercase, dashes converted to underscore )', 'wc-mfpc'),
			//'$query_string' => __('Full request URI after rewrites', 'wc-mfpc'),
			//'' => __('', 'wc-mfpc'),
		);

		/* get current wp_cron schedules */
		$wp_schedules = wp_get_schedules();
		/* add 'null' to switch off timed precache */
		$schedules['null'] = __( 'do not use timed precache' );
		foreach ( $wp_schedules as $interval=>$details ) {
			$schedules[ $interval ] = $details['display'];
		}
		$this->select_schedules = $schedules;

	}

	/**
	 * additional init, steps that needs the plugin options
	 *
	 */
	public function plugin_post_init () {

		/* initiate backend */
		$backend_class = 'WC_MFPC_Backend_' . $this->options['cache_type'];
		$this->backend = new $backend_class ( $this->options );

		/* re-save settings after update */
		add_action( 'upgrader_process_complete', array ( &$this->plugin_upgrade ), 10, 2 );

		/* cache invalidation hooks */
		add_action(  'transition_post_status',  array( &$this->backend , 'clear_ng' ), 10, 3 );

		/* comments invalidation hooks */
		if ( $this->options['comments_invalidate'] ) {
			add_action( 'comment_post', array( &$this->backend , 'clear' ), 0 );
			add_action( 'edit_comment', array( &$this->backend , 'clear' ), 0 );
			add_action( 'trashed_comment', array( &$this->backend , 'clear' ), 0 );
			add_action( 'pingback_post', array( &$this->backend , 'clear' ), 0 );
			add_action( 'trackback_post', array( &$this->backend , 'clear' ), 0 );
			add_action( 'wp_insert_comment', array( &$this->backend , 'clear' ), 0 );
		}

		/* invalidation on some other ocasions as well */
		add_action( 'switch_theme', array( &$this->backend , 'clear' ), 0 );
		add_action( 'deleted_post', array( &$this->backend , 'clear' ), 0 );
		add_action( 'edit_post', array( &$this->backend , 'clear' ), 0 );

		/* add filter for catching canonical redirects */
		if ( WP_CACHE )
			add_filter('redirect_canonical', 'wc_mfpc_redirect_callback', 10, 2);

		/* add precache coldrun action */
		add_action( self::precache_id , array( &$this, 'precache_coldrun' ) );

		/* link on to settings for plugins page */
		$settings_link = ' &raquo; <a href="' . $this->settings_link . '">' . __( 'WC-MFPC Settings', 'wc-mfpc') . '</a>';

		/* check & collect errors */
		/* look for WP_CACHE */
		if ( ! WP_CACHE )
			$this->errors['no_wp_cache'] = __("WP_CACHE is disabled. Without that, cache plugins, like this, will not work. Please add `define ( 'WP_CACHE', true );` to the beginning of wp-config.php.", 'wc-mfpc');

		/* look for global settings array */
		if ( ! $this->global_saved )
			$this->errors['no_global_saved'] = sprintf( __('This site was reached as %s ( according to PHP HTTP_HOST ) and there are no settings present for this domain in the WC-MFPC configuration yet. Please save the %s for the domain or fix the webserver configuration!', 'wc-mfpc'), $_SERVER['HTTP_HOST'], $settings_link);

		/* look for writable acache file */
		if ( file_exists ( $this->acache ) && ! is_writable ( $this->acache ) )
			$this->errors['no_acache_write'] = sprintf(__('Advanced cache file (%s) is not writeable!<br />Please change the permissions on the file.', 'wc-mfpc'), $this->acache);

		/* look for acache file */
		if ( ! file_exists ( $this->acache ) )
			$this->errors['no_acache_saved'] = sprintf (__('Advanced cache file is yet to be generated, please save %s', 'wc-mfpc'), $settings_link );

		/* look for extensions that should be available */
		foreach ( $this->valid_cache_type as $backend => $status ) {
			if ( $this->options['cache_type'] == $backend && ! $status ) {
				$this->errors['no_backend'] = sprintf ( __('%s cache backend activated but no PHP %s extension was found.<br />Please either use different backend or activate the module!', 'wc-mfpc'), $backend, $backend );
			}
		}

		/* get the current runtime configuration for memcache in PHP because Memcache in binary mode is really problematic */
		if ( extension_loaded ( 'memcache' )  ) {
			$memcache_settings = ini_get_all( 'memcache' );
			if ( !empty ( $memcache_settings ) && $this->options['cache_type'] == 'memcache' && isset($memcache_settings['memcache.protocol']) )
			{
				$memcache_protocol = strtolower($memcache_settings['memcache.protocol']['local_value']);
				if ( $memcache_protocol == 'binary' ) {
					$this->errors['memcached_binary'] = __('WARNING: Memcache extension is configured to use binary mode. This is very buggy and the plugin will most probably not work correctly. <br />Please consider to change either to ASCII mode or to Memcached extension.', 'wc-mfpc');
				}
			}
		}

		$filtered_errors = apply_filters('wc_mfpc_post_init_errors_array', $this->errors);
		if ($filtered_errors) {
			if ( php_sapi_name() != "cli" ) {
				foreach ( $this->errors as $e => $msg ) {
					static::alert ( $msg, LOG_WARNING, $this->network );
				}
			}
		}

		add_filter('contextual_help', array( &$this, 'plugin_admin_nginx_help' ), 10, 2);
	}

	/**
	 * activation hook function, to be extended
	 */
	public function plugin_activate() {
		/* we leave this empty to avoid not detecting WP network correctly */
	}

	/**
	 * deactivation hook function, to be extended
	 */
	public function plugin_deactivate () {
		/* remove current site config from global config */
		$this->update_global_config( true );
	}

	/**
	 * uninstall hook function, to be extended
	 */
	public function plugin_uninstall( $delete_options = true ) {
		/* delete advanced-cache.php file */
		unlink ( $this->acache );

		/* delete site settings */
		if ( $delete_options ) {
			$this->plugin_options_delete ();
		}
	}

	/**
	 * once upgrade is finished, deploy advanced cache and save the new settings, just in case
	 */
	public function plugin_upgrade ( $upgrader_object, $hook_extra ) {
		if (is_plugin_active( $this->plugin_constant . DIRECTORY_SEPARATOR . $this->plugin_constant . '.php' )) {
			$this->update_global_config();
			$this->plugin_options_save();
			$this->deploy_advanced_cache();
			static::alert ( __('WC-MFPC settings were upgraded; please double check if everything is still working correctly.', 'wc-mfpc'), LOG_NOTICE );
		}
	}

	/**
	 * extending admin init
	 *
	 */
	public function plugin_extend_admin_init () {
		/* save parameter updates, if there are any */
		if ( isset( $_POST[ $this->button_flush ] ) && check_admin_referer ( 'wc-mfpc') ) {
			/* remove precache log entry */
			static::_delete_option( self::precache_log  );
			/* remove precache timestamp entry */
			static::_delete_option( self::precache_timestamp );

			/* remove precache logfile */
			if ( @file_exists ( $this->precache_logfile ) ) {
				unlink ( $this->precache_logfile );
			}

			/* remove precache PHP worker */
			if ( @file_exists ( $this->precache_phpfile ) ) {
				unlink ( $this->precache_phpfile );
			}

			/* flush backend */
			$this->backend->clear( false, true );
			$this->status = 3;
			header( "Location: ". $this->settings_link . self::slug_flush );
		}

		/* save parameter updates, if there are any */
		if ( isset( $_POST[ $this->button_precache ] ) && check_admin_referer ( 'wc-mfpc') ) {
			/* is no shell function is possible, fail */
			if ( $this->shell_function == false ) {
				$this->status = 5;
				header( "Location: ". $this->settings_link . self::slug_precache_disabled );
			}
			/* otherwise start full precache */
			else {
				$this->precache_message = $this->precache_coldrun();
				$this->status = 4;
				header( "Location: ". $this->settings_link . self::slug_precache );
			}
		}
	}

	/**
	 * admin help panel
	 */
	public function plugin_admin_help($contextual_help, $screen_id ) {

		/* add our page only if the screenid is correct */
		if ( strpos( $screen_id, $this->plugin_settings_page ) ) {
			$contextual_help = __('<p>Please visit <a href="http://wordpress.org/support/plugin/wc-mfpc">the official support forum of the plugin</a> for help.</p>', 'wc-mfpc');

			/* [TODO] give detailed information on errors & troubleshooting
			get_current_screen()->add_help_tab( array(
					'id'		=> $this->plugin_constant . '-issues',
					'title'		=> __( 'Troubleshooting' ),
					'content'	=> __( '<p>List of errors, possible reasons and solutions</p><dl>
						<dt>E#</dt><dd></dd>
					</ol>' )
			) );
			*/

		}

		return $contextual_help;
	}

	/**
	 * admin help panel
	 */
	public function plugin_admin_nginx_help($contextual_help, $screen_id ) {

		/* add our page only if the screenid is correct */
		if ( strpos( $screen_id, $this->plugin_settings_page ) ) {
			$content = __('<h3>Sample config for nginx to utilize the data entries</h3>', 'wc-mfpc');
			$content .= __('<div class="update-nag">This is not meant to be a copy-paste configuration; you most probably have to tailor it to your needs.</div>', 'wc-mfpc');
			$content .= __('<div class="update-nag"><strong>In case you are about to use nginx to fetch memcached entries directly and to use SHA1 hash keys, you will need an nginx version compiled with <a href="http://wiki.nginx.org/HttpSetMiscModule">HttpSetMiscModule</a>. Otherwise set_sha1 function is not available in nginx.</strong></div>', 'wc-mfpc');
			$content .= '<code><pre>' . $this->nginx_example() . '</pre></code>';

			get_current_screen()->add_help_tab( array(
					'id'		=> 'wc-mfpc-nginx-help',
					'title'		=> __( 'nginx example', 'wc-mfpc' ),
					'content'	=> $content,
			) );
		}

		return $contextual_help;
	}

	/**
	 * admin panel, the admin page displayed for plugin settings
	 */
	public function plugin_admin_panel() {
		/**
		 * security, if somehow we're running without WordPress security functions
		 */
		if( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ){
			die( );
		}

		/* woo_commenrce page url */
		if ( class_exists( 'WooCommerce' ) ) {
			$page_wc_checkout=str_replace( home_url(), '', wc_get_page_permalink( 'checkout' ) );
			$page_wc_myaccount=str_replace( home_url(), '', wc_get_page_permalink( 'myaccount' ) );
			$page_wc_cart=str_replace( home_url(), '', wc_get_page_permalink( 'cart' ) );
			$wcapi='^/wc-api|^/\?wc-api=';
			$this->options['nocache_woocommerce_url'] =  '^'.$page_wc_checkout.'|^'.$page_wc_myaccount.'|^'.$page_wc_cart.'|'.$wcapi;
		} else {
			$this->options['nocache_woocommerce_url'] = '';
		}

		?>

		<div class="wrap">

		<script>
			jQuery(document).ready(function($) {
				jQuery( "#<?php echo $this->plugin_constant ?>-settings" ).tabs();
				jQuery( "#<?php echo $this->plugin_constant ?>-commands" ).tabs();
			});
		</script>

		<?php

		/* display donation form */
		$this->plugin_donation_form();

		/**
		 * if options were saved, display saved message
		 */
		if (isset($_GET[ self::key_save ]) && $_GET[ self::key_save ]=='true' || $this->status == 1) { ?>
			<div class='updated settings-error'><p><strong><?php _e( 'Settings saved.' , 'wc-mfpc') ?></strong></p></div>
		<?php }

		/**
		 * if options were delete, display delete message
		 */
		if (isset($_GET[ self::key_delete ]) && $_GET[ self::key_delete ]=='true' || $this->status == 2) { ?>
			<div class='error'><p><strong><?php _e( 'Plugin options deleted.' , 'wc-mfpc') ?></strong></p></div>
		<?php }

		/**
		 * if options were saved
		 */
		if (isset($_GET[ self::key_flush ]) && $_GET[ self::key_flush ]=='true' || $this->status == 3) { ?>
			<div class='updated settings-error'><p><strong><?php _e( "Cache flushed." , 'wc-mfpc'); ?></strong></p></div>
		<?php }

		/**
		 * if options were saved, display saved message
		 */
		if ( ( isset($_GET[ self::key_precache ]) && $_GET[ self::key_precache ]=='true' ) || $this->status == 4) { ?>
		<div class='updated settings-error'><p><strong><?php _e( 'Precache process was started, it is now running in the background, please be patient, it may take a very long time to finish.' , 'wc-mfpc') ?></strong></p></div>
		<?php }

		/**
		 * the admin panel itself
		 */
		?>

		<h2><?php echo $this->plugin_name ; _e( ' settings', 'wc-mfpc') ; ?></h2>

		<div class="updated">
			<p><strong><?php _e ( 'Driver: ' , 'wc-mfpc'); echo $this->options['cache_type']; ?></strong></p>
			<?php
			/* only display backend status if memcache-like extension is running */
			if ( strstr ( $this->options['cache_type'], 'memcache') ) {
				?><p><?php
				_e( '<strong>Backend status:</strong><br />', 'wc-mfpc');

				/* we need to go through all servers */
				$servers = $this->backend->status();
				error_log(__CLASS__ . ':' .json_encode($servers));
				if ( is_array( $servers ) && !empty ( $servers ) ) {
					foreach ( $servers as $server_string => $status ) {
						echo $server_string ." => ";

						if ( $status == 0 )
							_e ( '<span class="error-msg">down</span><br />', 'wc-mfpc');
						elseif ( ( $this->options['cache_type'] == 'memcache' && $status > 0 )  || $status == 1 )
							_e ( '<span class="ok-msg">up & running</span><br />', 'wc-mfpc');
						else
							_e ( '<span class="error-msg">unknown, please try re-saving settings!</span><br />', 'wc-mfpc');
					}
				}

				?></p><?php
			} ?>
		</div>
		<form autocomplete="off" method="post" action="#" id="<?php echo $this->plugin_constant ?>-settings" class="plugin-admin">

			<?php wp_nonce_field( 'wc-mfpc'); ?>

			<?php $switcher_tabs = $this->plugin_admin_panel_get_tabs(); ?>
			<ul class="tabs">
				<?php foreach($switcher_tabs AS $tab_section => $tab_label): ?>
				<li><a href="#<?= $this->plugin_constant ?>-<?= $tab_section ?>" class="wp-switch-editor"><?= $tab_label ?></a></li>
				<?php endforeach; ?>
			</ul>

			<fieldset id="<?php echo $this->plugin_constant ?>-type">
			<legend><?php _e( 'Set cache type', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<label for="cache_type"><?php _e('Select backend', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<select name="cache_type" id="cache_type">
						<?php $this->print_select_options ( $this->select_cache_type , $this->options['cache_type'], $this->valid_cache_type ) ?>
					</select>
					<span class="description"><?php _e('Select backend storage driver', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="expire"><?php _e('Expiration time for posts', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="expire" id="expire" value="<?php echo $this->options['expire']; ?>" />
					<span class="description"><?php _e('Sets validity time of post entry in seconds, including custom post types and pages.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="browsercache"><?php _e('Browser cache expiration time of posts', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="browsercache" id="browsercache" value="<?php echo $this->options['browsercache']; ?>" />
					<span class="description"><?php _e('Sets validity time of posts/pages/singles for the browser cache.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="expire_taxonomy"><?php _e('Expiration time for taxonomy', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="expire_taxonomy" id="expire_taxonomy" value="<?php echo $this->options['expire_taxonomy']; ?>" />
					<span class="description"><?php _e('Sets validity time of taxonomy entry in seconds, including custom taxonomy.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="browsercache_taxonomy"><?php _e('Browser cache expiration time of taxonomy', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="browsercache_taxonomy" id="browsercache_taxonomy" value="<?php echo $this->options['browsercache_taxonomy']; ?>" />
					<span class="description"><?php _e('Sets validity time of taxonomy for the browser cache.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="expire_home"><?php _e('Expiration time for home', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="expire_home" id="expire_home" value="<?php echo $this->options['expire_home']; ?>" />
					<span class="description"><?php _e('Sets validity time of home on server side.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="browsercache_home"><?php _e('Browser cache expiration time of home', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="number" name="browsercache_home" id="browsercache_home" value="<?php echo $this->options['browsercache_home']; ?>" />
					<span class="description"><?php _e('Sets validity time of home for the browser cache.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="charset"><?php _e('Charset', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="charset" id="charset" value="<?php echo $this->options['charset']; ?>" />
					<span class="description"><?php _e('Charset of HTML and XML (pages and feeds) data.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="invalidation_method"><?php _e('Cache invalidation method', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<select name="invalidation_method" id="invalidation_method">
						<?php $this->print_select_options ( $this->select_invalidation_method , $this->options['invalidation_method'] ) ?>
					</select>
					<div class="description"><?php _e('Select cache invalidation method.', 'wc-mfpc'); ?>
						<ol>
							<?php
							$invalidation_method_description = array(
								'clears everything in storage, <strong>including values set by other applications</strong>',
								'clear only the modified posts entry, everything else remains in cache',
								'unvalidates post and the taxonomy related to the post',
							);
							foreach ($this->select_invalidation_method AS $current_key => $current_invalidation_method) {
								printf('<li><em>%1$s</em> - %2$s</li>', $current_invalidation_method, $invalidation_method_description[$current_key]);
							} ?>
						</ol>
					</div>
				</dd>

				<dt>
					<label for="comments_invalidate"><?php _e('Invalidate on comment actions', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="comments_invalidate" id="comments_invalidate" value="1" <?php checked($this->options['comments_invalidate'],true); ?> />
					<span class="description"><?php _e('Trigger cache invalidation when a comments is posted, edited, trashed. ', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="prefix_data"><?php _e('Data prefix', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="prefix_data" id="prefix_data" value="<?php echo $this->options['prefix_data']; ?>" />
					<span class="description"><?php _e('Prefix for HTML content keys, can be used in nginx.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="prefix_meta"><?php _e('Meta prefix', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="prefix_meta" id="prefix_meta" value="<?php echo $this->options['prefix_meta']; ?>" />
					<span class="description"><?php _e('Prefix for meta content keys, used only with PHP processing.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="key"><?php _e('Key scheme', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="key" id="key" value="<?php echo $this->options['key']; ?>" />
					<span class="description"><?php _e('Key layout; <strong>use the guide below to change it</strong>.<br /><strong>WARNING</strong>: changing this will result the previous cache to becomes invalid!<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.', 'wc-mfpc'); ?><?php ?></span>
					<dl class="description"><?php
					foreach ( $this->list_uri_vars as $uri => $desc ) {
						echo '<dt>'. $uri .'</dt><dd>'. $desc .'</dd>';
					}
					?></dl>
				</dd>

				<dt>
					<label for="hashkey"><?php _e('SHA1 hash key', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="hashkey" id="hashkey" value="1" <?php checked($this->options['hashkey'],true); ?> />
					<span class="description"><?php _e('Occasionally URL can be too long to be used as key for the backend storage, especially with memcached. Turn on this feature to use SHA1 hash of the URL as key instead. Please be aware that you have to add ( or uncomment ) a line and a <strong>module</strong> in nginx if you want nginx to fetch the data directly; for details, please see the nginx example tab.', 'wc-mfpc'); ?>
				</dd>



			</dl>
			</fieldset>

			<fieldset id="<?php echo $this->plugin_constant; ?>-debug">
			<legend><?php _e( 'Debug & in-depth settings', 'wc-mfpc'); ?></legend>
			<h3><?php _e('Notes', 'wc-mfpc');?></h3>
			<p><?php _e('The former method of debug logging flag has been removed. In case you need debug log from WC-MFPC please set both the <a href="http://codex.wordpress.org/WP_DEBUG">WP_DEBUG</a> and the WC_MFPC__DEBUG_MODE constants `true` in wp-config.php.<br /> This will enable NOTICE level messages apart from the WARNING level ones which are always displayed.', 'wc-mfpc'); ?></p>

			<dl>
				<dt>
					<label for="pingback_header"><?php _e('Enable X-Pingback header preservation', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="pingback_header" id="pingback_header" value="1" <?php checked($this->options['pingback_header'],true); ?> />
					<span class="description"><?php _e('Preserve X-Pingback URL in response header.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="response_header"><?php _e("Add X-Cache-Engine header", 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="response_header" id="response_header" value="1" <?php checked($this->options['response_header'],true); ?> />
					<span class="description"><?php _e('Add X-Cache-Engine HTTP header to HTTP responses.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="generate_time"><?php _e("Add HTML debug comment", 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="generate_time" id="generate_time" value="1" <?php checked($this->options['generate_time'],true); ?> />
					<span class="description"><?php _e('Adds comment string including plugin name, cache engine and page generation time to every generated entry before closing <body> tag.', 'wc-mfpc'); ?></span>
				</dd>

			</dl>

			</fieldset>

			<fieldset id="<?php echo $this->plugin_constant ?>-exceptions">
			<legend><?php _e( 'Set cache additions/excepions', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<label for="cache_loggedin"><?php _e('Enable cache for logged in users', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="cache_loggedin" id="cache_loggedin" value="1" <?php checked($this->options['cache_loggedin'],true); ?> />
					<span class="description"><?php _e('Cache pages even if user is logged in.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<?php _e("Excludes", 'wc-mfpc'); ?></label>
				<dd>
					<table style="width:100%">
						<thead>
							<tr>
								<th style="width:13%; text-align:left"><label for="nocache_home"><?php _e("Exclude home", 'wc-mfpc'); ?></label></th>
								<th style="width:13%; text-align:left"><label for="nocache_feed"><?php _e("Exclude feeds", 'wc-mfpc'); ?></label></th>
								<th style="width:13%; text-align:left"><label for="nocache_archive"><?php _e("Exclude archives", 'wc-mfpc'); ?></label></th>
								<th style="width:13%; text-align:left"><label for="nocache_page"><?php _e("Exclude pages", 'wc-mfpc'); ?></label></th>
								<th style="width:13%; text-align:left"><label for="nocache_single"><?php _e("Exclude singulars", 'wc-mfpc'); ?></label></th>
								<th style="width:17%; text-align:left"><label for="nocache_dyn"><?php _e("Dynamic requests", 'wc-mfpc'); ?></label></th>
								<th style="width:18%; text-align:left"><label for="nocache_woocommerce"><?php _e("WooCommerce", 'wc-mfpc'); ?></label></th>
							</tr>
						</thead>
						<tbody>
								<tr>
									<td>
										<input type="checkbox" name="nocache_home" id="nocache_home" value="1" <?php checked($this->options['nocache_home'],true); ?> />
										<span class="description"><?php _e('Never cache home.', 'wc-mfpc'); ?>
									</td>
									<td>
										<input type="checkbox" name="nocache_feed" id="nocache_feed" value="1" <?php checked($this->options['nocache_feed'],true); ?> />
										<span class="description"><?php _e('Never cache feeds.', 'wc-mfpc'); ?>
									</td>
									<td>
										<input type="checkbox" name="nocache_archive" id="nocache_archive" value="1" <?php checked($this->options['nocache_archive'],true); ?> />
										<span class="description"><?php _e('Never cache archives.', 'wc-mfpc'); ?>
									</td>
									<td>
										<input type="checkbox" name="nocache_page" id="nocache_page" value="1" <?php checked($this->options['nocache_page'],true); ?> />
										<span class="description"><?php _e('Never cache pages.', 'wc-mfpc'); ?>
									</td>
									<td>
										<input type="checkbox" name="nocache_single" id="nocache_single" value="1" <?php checked($this->options['nocache_single'],true); ?> />
										<span class="description"><?php _e('Never cache singulars.', 'wc-mfpc'); ?>
									</td>
									<td>
										<input type="checkbox" name="nocache_dyn" id="nocache_dyn" value="1" <?php checked($this->options['nocache_dyn'],true); ?> />
					<span class="description"><?php _e('Exclude every URL with "?" in it.', 'wc-mfpc'); ?></span>
									</td>
									<td>
										<input type="hidden" name="nocache_woocommerce_url" id="nocache_woocommerce_url" value="<?php if(isset( $this->options['nocache_woocommerce_url'] ) ) echo $this->options['nocache_woocommerce_url']; ?>" />
										<input type="checkbox" name="nocache_woocommerce" id="nocache_woocommerce" value="1" <?php checked($this->options['nocache_woocommerce'],true); ?> />
					<span class="description"><?php _e('Exclude dynamic WooCommerce page.', 'wc-mfpc');?>
					<?php if(isset( $this->options['nocache_woocommerce_url'] ) ) echo "<br />Url:".$this->options['nocache_woocommerce_url']; ?></span>
									</td>
								</tr>
						</tbody>
					</table>

				<dt>
					<label for="nocache_cookies"><?php _e("Exclude based on cookies", 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="nocache_cookies" id="nocache_cookies" value="<?php if(isset( $this->options['nocache_cookies'] ) ) echo $this->options['nocache_cookies']; ?>" />
					<span class="description"><?php _e('Exclude content based on cookies names starting with this from caching. Separate multiple cookies names with commas.<br />If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="nocache_url"><?php _e("Don't cache following URL paths - use with caution!", 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<textarea name="nocache_url" id="nocache_url" rows="3" cols="100" class="large-text code"><?php
						if( isset( $this->options['nocache_url'] ) ) {
							echo $this->options['nocache_url'];
						}
					?></textarea>
					<span class="description"><?php _e('Regular expressions use you must! e.g. <em>pattern1|pattern2|etc</em>', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="nocache_comment"><?php _e("Exclude from cache based on content", 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input name="nocache_comment" id="nocache_comment" type="text" value="<?php if(isset( $this->options['nocache_comment'] ) ) echo $this->options['nocache_comment']; ?>" />
					<span class="description"><?php _e('Enter a regex pattern that will trigger excluding content from caching. Eg. <!--nocache-->. Regular expressions use you must! e.g. <em>pattern1|pattern2|etc</em><br />
					<strong>WARNING:</strong> be careful where you display this, because it will apply to any content, including archives, collection pages, singles, anything. If empty, this setting will be ignored.', 'wc-mfpc'); ?></span>
				</dd>

			</dl>
			</fieldset>

			<fieldset id="<?php echo $this->plugin_constant ?>-servers">
			<legend><?php _e('Backend server settings', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<label for="hosts"><?php _e('Hosts', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" name="hosts" id="hosts" value="<?php echo $this->options['hosts']; ?>" />
					<span class="description">
					<?php _e('List of backends, with the following syntax: <br />- in case of TCP based connections, list the servers as host1:port1,host2:port2,... . Do not add trailing , and always separate host and port with : .<br />- for a unix socket enter: unix://[socket_path]', 'wc-mfpc'); ?></span>
				</dd>

				<h3><?php _e('Authentication ( only for SASL enabled Memcached)')?></h3>
				<?php
					if ( ! ini_get('memcached.use_sasl') && ( !empty( $this->options['authuser'] ) || !empty( $this->options['authpass'] ) ) ) { ?>
						<div class="error"><p><strong><?php _e( 'WARNING: you\'ve entered username and/or password for memcached authentication ( or your browser\'s autocomplete did ) which will not work unless you enable memcached sasl in the PHP settings: add `memcached.use_sasl=1` to php.ini' , 'wc-mfpc') ?></strong></p></div>
				<?php } ?>
				<dt>
					<label for="authuser"><?php _e('Authentication: username', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="text" autocomplete="off" name="authuser" id="authuser" value="<?php echo $this->options['authuser']; ?>" />
					<span class="description">
					<?php _e('Username for authentication with backends', 'wc-mfpc'); ?></span>
				</dd>

				<dt>
					<label for="authpass"><?php _e('Authentication: password', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="password" autocomplete="off" name="authpass" id="authpass" value="<?php echo $this->options['authpass']; ?>" />
					<span class="description">
					<?php _e('Password for authentication with for backends - WARNING, the password will be stored in an unsecure format!', 'wc-mfpc'); ?></span>
				</dd>

				<h3><?php _e('Memcached specific settings')?></h3>
				<dt>
					<label for="memcached_binary"><?php _e('Enable memcached binary mode', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<input type="checkbox" name="memcached_binary" id="memcached_binary" value="1" <?php checked($this->options['memcached_binary'],true); ?> />
					<span class="description"><?php _e('Some memcached proxies and implementations only support the ASCII protocol.', 'wc-mfpc'); ?></span>
				</dd>


			</dl>
			</fieldset>

			<fieldset id="<?php echo $this->plugin_constant ?>-precache">
			<legend><?php _e('Precache settings & log from previous pre-cache generation', 'wc-mfpc'); ?></legend>

				<dt>
					<label for="precache_schedule"><?php _e('Precache schedule', 'wc-mfpc'); ?></label>
				</dt>
				<dd>
					<select name="precache_schedule" id="precache_schedule">
						<?php $this->print_select_options ( $this->select_schedules, $this->options['precache_schedule'] ) ?>
					</select>
					<span class="description"><?php _e('Schedule autorun for precache with WP-Cron', 'wc-mfpc'); ?></span>
				</dd>

				<?php

				$gentime = static::_get_option( self::precache_timestamp, $this->network );
				$log = static::_get_option( self::precache_log, $this->network );

				if ( @file_exists ( $this->precache_logfile ) ) {
					$logtime = filemtime ( $this->precache_logfile );

					/* update precache log in DB if needed */
					if ( $logtime > $gentime ) {
						$log = file ( $this->precache_logfile );
						static::_update_option( self::precache_log , $log, $this->network );
						static::_update_option( self::precache_timestamp , $logtime, $this->network );
					}

				}

				if ( empty ( $log ) ) {
					_e('No precache log was found!', 'wc-mfpc');
				}
				else { ?>
					<p><strong><?php _e( 'Time of run: ') ?><?php echo date('r', $gentime ); ?></strong></p>
					<div  style="overflow: auto; max-height: 20em;"><table style="width:100%; border: 1px solid #ccc;">
						<thead><tr>
								<?php $head = explode( "	", array_shift( $log ));
								foreach ( $head as $column ) { ?>
									<th><?php echo $column; ?></th>
								<?php } ?>
						</tr></thead>
						<?php
						foreach ( $log as $line ) { ?>
							<tr>
								<?php $line = explode ( "	", $line );
								foreach ( $line as $column ) { ?>
									<td><?php echo $column; ?></td>
								<?php } ?>
							</tr>
						<?php } ?>
				</table></div>
			<?php } ?>
			</fieldset>

			<?php do_action('wc_mfpc_admin_panel_tabs_extra_content', 'wc-mfpc'); ?>

			<p class="clear">
				<input class="button-primary" type="submit" name="<?php echo $this->button_save ?>" id="<?php echo $this->button_save ?>" value="<?php _e('Save Changes', 'wc-mfpc') ?>" />
			</p>

		</form>

		<form method="post" action="#" id="<?php echo $this->plugin_constant ?>-commands" class="plugin-admin" style="padding-top:2em;">

			<?php wp_nonce_field( 'wc-mfpc'); ?>

			<ul class="tabs">
				<li><a href="#<?php echo $this->plugin_constant ?>-precache" class="wp-switch-editor"><?php _e( 'Precache', 'wc-mfpc'); ?></a></li>
				<li><a href="#<?php echo $this->plugin_constant ?>-flush" class="wp-switch-editor"><?php _e( 'Empty cache', 'wc-mfpc'); ?></a></li>
				<li><a href="#<?php echo $this->plugin_constant ?>-reset" class="wp-switch-editor"><?php _e( 'Reset settings', 'wc-mfpc'); ?></a></li>
			</ul>

			<fieldset id="<?php echo $this->plugin_constant ?>-precache">
			<legend><?php _e( 'Precache', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<?php if ( ( isset( $_GET[ self::key_precache_disabled ] ) && $_GET[ self::key_precache_disabled ] =='true' ) || $this->status == 5 || $this->shell_function == false ) { ?>
						<strong><?php _e( "Precache functionality is disabled due to unavailable system call function. <br />Since precaching may take a very long time, it's done through a background CLI process in order not to run out of max execution time of PHP. Please enable one of the following functions if you whish to use precaching: " , 'wc-mfpc') ?><?php echo join( ',' , $this->shell_possibilities ); ?></strong>
					<?php }
					else { ?>
						<input class="button-secondary" type="submit" name="<?php echo $this->button_precache ?>" id="<?php echo $this->button_precache ?>" value="<?php _e('Pre-cache', 'wc-mfpc') ?>" />
					<?php } ?>
				</dt>
				<dd>
					<span class="description"><?php _e('Start a background process that visits all permalinks of all blogs it can found thus forces WordPress to generate cached version of all the pages.<br />The plugin tries to visit links of taxonomy terms without the taxonomy name as well. This may generate 404 hits, please be prepared for these in your logfiles if you plan to pre-cache.', 'wc-mfpc'); ?></span>
				</dd>
			</dl>
			</fieldset>
			<fieldset id="<?php echo $this->plugin_constant ?>-flush">
			<legend><?php _e( 'Precache', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<input class="button-warning" type="submit" name="<?php echo $this->button_flush ?>" id="<?php echo $this->button_flush ?>" value="<?php _e('Clear cache', 'wc-mfpc') ?>" />
				</dt>
				<dd>
					<span class="description"><?php _e ( "Clear all entries in the storage, including the ones that were set by other processes.", 'wc-mfpc'); ?> </span>
				</dd>
			</dl>
			</fieldset>
			<fieldset id="<?php echo $this->plugin_constant ?>-reset">
			<legend><?php _e( 'Precache', 'wc-mfpc'); ?></legend>
			<dl>
				<dt>
					<input class="button-warning" type="submit" name="<?php echo $this->button_delete ?>" id="<?php echo $this->button_delete ?>" value="<?php _e('Reset options', 'wc-mfpc') ?>" />
				</dt>
				<dd>
					<span class="description"><?php _e ( "Reset settings to defaults.", 'wc-mfpc'); ?> </span>
				</dd>
			</dl>
			</fieldset>
		</form>
		</div>
		<?php
	}

	private function plugin_admin_panel_get_tabs() {
		$default_tabs = array(
			'type' => __( 'Cache type', 'wc-mfpc'),
			'debug' => __( 'Debug & in-depth', 'wc-mfpc'),
			'exceptions' => __( 'Cache exceptions', 'wc-mfpc'),
			'servers' => __( 'Backend settings', 'wc-mfpc'),
			'precache' => __( 'Precache & precache log', 'wc-mfpc')
		);

		return apply_filters('wc_mfpc_admin_panel_tabs', $default_tabs);
	}

	/**
	 * extending options_save
	 *
	 */
	public function plugin_extend_options_save( $activating ) {

		/* schedule cron if posted */
		$schedule = wp_get_schedule( self::precache_id );
		if ( $this->options['precache_schedule'] != 'null' ) {
			/* clear all other schedules before adding a new in order to replace */
			wp_clear_scheduled_hook ( self::precache_id );
			static::debug ( $this->plugin_constant, __( 'Scheduling WP-CRON event', 'wc-mfpc') );
			$this->scheduled = wp_schedule_event( time(), $this->options['precache_schedule'] , self::precache_id );
		}
		elseif ( ( !isset($this->options['precache_schedule']) || $this->options['precache_schedule'] == 'null' ) && !empty( $schedule ) ) {
			static::debug ( $this->plugin_constant, __('Clearing WP-CRON scheduled hook ' , 'wc-mfpc') );
			wp_clear_scheduled_hook ( self::precache_id );
		}

		/* flush the cache when new options are saved, not needed on activation */
		if ( !$activating )
			$this->backend->clear(null, true);

		/* create the to-be-included configuration for advanced-cache.php */
		$this->update_global_config();

		/* create advanced cache file, needed only once or on activation, because there could be lefover advanced-cache.php from different plugins */
		if (  !$activating )
			$this->deploy_advanced_cache();

	}

	/**
	 * read hook; needs to be implemented
	 */
	public function plugin_extend_options_read( &$options ) {
		/*if ( strstr( $this->options['nocache_url']), '^wp-'  )wp_login_url()
		$this->options['nocache_url'] = */


		/* read the global options, network compatibility */
		$this->global_config = get_site_option( $this->global_option );

		/* check if current site present in global config */
		if ( !empty ( $this->global_config[ $this->global_config_key ] ) )
			$this->global_saved = true;

		$this->global_config[ $this->global_config_key ] = $options;
	}

	/**
	 * options delete hook; needs to be implemented
	 */
	public function plugin_extend_options_delete(  ) {
		delete_site_option ( $this->global_option );
	}

	/**
	 * need to do migrations from previous versions of the plugin
	 *
	 */
	public function plugin_options_migrate( &$options ) {

		if ( version_compare ( $options['version'] , $this->plugin_version, '<' ) ) {
			/* cleanup possible leftover files from previous versions */
			$check = array ( 'advanced-cache.php', 'nginx-sample.conf', 'wc-mfpc.admin.css', 'wc-mfpc-common.php' );
			foreach ( $check as $fname ) {
				$fname = $this->plugin_dir . $fname;
				if ( file_exists ( $fname ) )
					unlink ( $fname );
			}

			/* look for previous config leftovers */
			$try = get_site_option( 'wc-mfpc');
			/* network option key changed, remove & migrate the leftovers if there's any */
			if ( !empty ( $try ) && $this->network ) {
				/* clean it up, we don't use it anymore */
				delete_site_option ( 'wc-mfpc');

				if ( empty ( $options ) && array_key_exists ( $this->global_config_key, $try ) ) {
					$options = $try [ $this->global_config_key ];
				}
				elseif ( empty ( $options ) && array_key_exists ( 'host', $try ) ) {
					$options = $try;
				}
			 }

			/* updating from version <= 0.4.x */
			if ( !empty ( $options['host'] ) ) {
				$options['hosts'] = $options['host'] . ':' . $options['port'];
			}
			/* migrating from version 0.6.x */
			elseif ( is_array ( $options ) && array_key_exists ( $this->global_config_key , $options ) ) {
				$options = $options[ $this->global_config_key ];
			}

			/* renamed options */
			if ( isset ( $options['syslog'] ) )
				$options['log'] = $options['syslog'];
			if ( isset ( $options['debug'] ) )
				$options['response_header'] = $options['debug'];
		}
	}

	/**
	 * advanced-cache.php creator function
	 *
	 */
	private function deploy_advanced_cache( ) {

		if (!touch($this->acache)) {
			error_log('Generating advanced-cache.php failed: '.$this->acache.' is not writable');
			return false;
		}

		/* if no active site left no need for advanced cache :( */
		if ( empty ( $this->global_config ) ) {
			error_log('Generating advanced-cache.php failed: Global config is empty');
			return false;
		}


		/* add the required includes and generate the needed code */
		$string[] = "<?php";
		$string[] = self::global_config_var . ' = ' . var_export ( $this->global_config, true ) . ';' ;
		//$string[] = "include_once ('" . $this->acache_backend . "');";
		$string[] = "include_once ('" . $this->acache_worker . "');";

		/* write the file and start caching from this point */
		return file_put_contents( $this->acache, join( "\n" , $string ) );

	}

	/**
	 * function to generate working example from the nginx sample file
	 *
	 * @return string nginx config file
	 *
	 */
	private function nginx_example () {
		/* read the sample file */
		$nginx = file_get_contents ( $this->nginx_sample );

		if ( isset($this->options['hashkey']) && $this->options['hashkey'] == true )
			$mckeys = '    set_sha1 $memcached_sha1_key $memcached_raw_key;
    set $memcached_key DATAPREFIX$memcached_sha1_key;';
		else
			$mckeys = '    set $memcached_key DATAPREFIX$memcached_raw_key;';

		$nginx = str_replace ( 'HASHEDORNOT' , $mckeys , $nginx );

		/* replace the data prefix with the configured one */
		$to_replace = array ( 'DATAPREFIX' , 'KEYFORMAT',  'SERVERROOT', 'SERVERLOG' );
		$replace_with = array ( $this->options['prefix_data'],  $this->options['key'] , ABSPATH, $_SERVER['SERVER_NAME'] );
		$nginx = str_replace ( $to_replace , $replace_with , $nginx );


		/* set upstream servers from configured servers, best to get from the actual backend */
		$servers = $this->backend->get_servers();
		$nginx_servers = '';
		if ( is_array ( $servers )) {
			foreach ( array_keys( $servers ) as $server ) {
				$nginx_servers .= "		server ". $server .";\n";
			}
		}
		else {
			$nginx_servers .= "		server ". $servers .";\n";
		}
		$nginx = str_replace ( 'MEMCACHED_SERVERS' , $nginx_servers , $nginx );

		$loggedincookies = join('|', $this->backend->cookies );
		/* this part is not used when the cache is turned on for logged in users */
		$loggedin = '
    if ($http_cookie ~* "'. $loggedincookies .'" ) {
        set $memcached_request 0;
    }';

		/* add logged in cache, if valid */
		if ( ! $this->options['cache_loggedin'])
			$nginx = str_replace ( 'LOGGEDIN_EXCEPTION' , $loggedin , $nginx );
		else
			$nginx = str_replace ( 'LOGGEDIN_EXCEPTION' , '' , $nginx );

		/* nginx can skip caching for visitors with certain cookies specified in the options */
		if( $this->options['nocache_cookies'] ) {
			$cookies = str_replace( ",","|", $this->options['nocache_cookies'] );
			$cookies = str_replace( " ","", $cookies );
			$cookie_exception = '# avoid cache for cookies specified
    if ($http_cookie ~* ' . $cookies . ' ) {
        set $memcached_request 0;
    }';
			$nginx = str_replace ( 'COOKIES_EXCEPTION' , $cookie_exception , $nginx );
		} else {
			$nginx = str_replace ( 'COOKIES_EXCEPTION' , '' , $nginx );
		}

		/* add custom response header if specified in the options */
		if( $this->options['response_header'] && strstr ( $this->options['cache_type'], 'memcached') ) {
			$response_header =  'add_header X-Cache-Engine "WC-MFPC with ' . $this->options['cache_type'] .' via nginx";';
			$nginx = str_replace ( 'RESPONSE_HEADER' , $response_header , $nginx );
		}
		else {
			$nginx = str_replace ( 'RESPONSE_HEADER' , '' , $nginx );
		}

		return htmlspecialchars($nginx);
	}

	/**
	 * function to update global configuration
	 *
	 * @param boolean $remove_site Bool to remove or add current config to global
	 *
	 */
	private function update_global_config ( $remove_site = false ) {

		/* remove or add current config to global config */
		if ( $remove_site ) {
			unset ( $this->global_config[ $this->global_config_key ] );
		}
		else {
			$this->global_config[ $this->global_config_key ] = $this->options;
		}

		/* deploy advanced-cache.php */
		$this->deploy_advanced_cache ();

		/* save options to database */
		update_site_option( $this->global_option , $this->global_config );
	}

  /**
   * generate cache entry for every available permalink, might be very-very slow,
   * therefore it starts a background process
   *
   * @param $links
   */
	private function precache ( &$links ) {

		/* double check if we do have any links to pre-cache */
		if ( !empty ( $links ) && !$this->precache_running() )  {

			$out = '<?php
				$links = ' . var_export ( $links , true ) . ';

				echo "permalink\tgeneration time (s)\tsize ( kbyte )\n";
				foreach ( $links as $permalink => $dummy ) {
					$starttime = explode ( " ", microtime() );
					$starttime = $starttime[1] + $starttime[0];

						$page = file_get_contents( $permalink );
						$size = round ( ( strlen ( $page ) / 1024 ), 2 );

					$endtime = explode ( " ", microtime() );
					$endtime = round( ( $endtime[1] + $endtime[0] ) - $starttime, 2 );

					echo $permalink . "\t" .  $endtime . "\t" . $size . "\n";
					unset ( $page, $size, $starttime, $endtime );
					sleep( 1 );
				}
				unlink ( "'. $this->precache_phpfile .'" );
			?>';

			file_put_contents ( $this->precache_phpfile, $out  );
			/* call the precache worker file in the background */
			$shellfunction = $this->shell_function;
			$shellfunction( 'php '. $this->precache_phpfile .' >'. $this->precache_logfile .' 2>&1 &' );
		}

	}

  /**
   * check is precache is still ongoing
   *
   * @return bool
   */
	private function precache_running () {
		$return = false;

		/* if the precache file exists, it did not finish running as it should delete itself on finish */
		if ( file_exists ( $this->precache_phpfile )) {
			$return = true;
		}
		/*
		 [TODO] cross-platform process check; this is *nix only
		else {
			$shellfunction = $this->shell_function;
			$running = $shellfunction( "ps aux | grep \"". $this->precache_phpfile ."\" | grep -v grep | awk '{print $2}'" );
			if ( is_int( $running ) && $running != 0 ) {
				$return = true;
			}
		}
		*/

		return $return;
	}

	/**
	 * run full-site precache
	 */
	public function precache_coldrun () {

		/* container for links to precache, well be accessed by reference */
		$links = array();

		/* when plugin is  network wide active, we need to pre-cache for all link of all blogs */
		if ( $this->network ) {
			/* list all blogs */
			global $wpdb;
			$pfix = empty ( $wpdb->base_prefix ) ? 'wp_' : $wpdb->base_prefix;
			$blog_list = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM ". $pfix ."blogs ORDER BY blog_id", '' ) );

			foreach ($blog_list as $blog) {
				if ( $blog->archived != 1 && $blog->spam != 1 && $blog->deleted != 1) {
					/* get permalinks for this blog */
					$this->precache_list_permalinks ( $links, $blog->blog_id );
				}
			}
		}
		else {
			/* no network, better */
			$this->precache_list_permalinks ( $links, false );
		}

		/* double check if we do have any links to pre-cache */
		if ( !empty ( $links ) )  {
			$this->precache ( $links );
		}
	}

	/**
	 * gets all post-like entry permalinks for a site, returns values in passed-by-reference array
	 *
	 */
	private function precache_list_permalinks ( &$links, $site = false ) {
		/* $post will be populated when running throught the posts */
		global $post;
		include_once ( ABSPATH . "wp-load.php" );

		/* if a site id was provided, save current blog and change to the other site */
		if ( $site !== false ) {
			$current_blog = get_current_blog_id();
			switch_to_blog( $site );

			$url = $this->_site_url( $site );
			//$url = get_blog_option ( $site, 'siteurl' );
			if ( substr( $url, -1) !== '/' )
				$url = $url . '/';

			$links[ $url ] = true;
		}

		/* get all published posts */
		$args = array (
			'post_type' => 'any',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		);
		$posts = new WP_Query( $args );

		/* get all the posts, one by one  */
		while ( $posts->have_posts() ) {
			$posts->the_post();

			/* get the permalink for currently selected post */
			switch ($post->post_type) {
				case 'revision':
				case 'nav_menu_item':
					break;
				case 'page':
					$permalink = get_page_link( $post->ID );
					break;
				/*
				 * case 'post':
					$permalink = get_permalink( $post->ID );
					break;
				*/
				case 'attachment':
					$permalink = get_attachment_link( $post->ID );
					break;
				default:
					$permalink = get_permalink( $post->ID );
				break;
			}

			/* in case the bloglinks are relative links add the base url, site specific */
			$baseurl = empty( $url ) ? static::_site_url() : $url;
			if ( !strstr( $permalink, $baseurl ) ) {
				$permalink = $baseurl . $permalink;
			}

			/* collect permalinks */
			$links[ $permalink ] = true;

		}

		$this->backend->taxonomy_links ( $links );

		/* just in case, reset $post */
		wp_reset_postdata();

		/* switch back to original site if we navigated away */
		if ( $site !== false ) {
			switch_to_blog( $current_blog );
		}
	}

	public function getBackend() {
		  return $this->backend;
	}

}
