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

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class AdminView
 *
 * @package InvincibleBrands\WcMfpc
 */
class AdminView
{

    /**
     * Renders the "Cache control" box.
     *
     * @param string $status     Cache status string.
     * @param string $display    CSS display property value which determines whether the Button is displayed or not.
     * @param string $type       Type of the item to delete.
     * @param string $identifier Identifier of the Item to delete like Name or ID.
     * @param string $permalink  The URL of the item to delete.
     * @param string $style      String with specific styles.
     *
     * @return void
     */
    public static function renderCacheControl($status = '', $display = '', $type = '', $identifier = '', $permalink = '', $style = '')
    {
        ?>
        <div style="background: #fff; max-width: 250px; box-sizing: border-box; <?php echo $style; ?>">
          <p>
            <b>Cache status:</b>
            <span id="wc-mfpc-cache-status"><?php echo $status; ?></span>
          </p>
          <button id="wc-mfpc-button-clear" class="button button-secondary"
                  style="margin: 0 auto 1rem; width: 100%; height: auto; white-space: normal; display: <?php echo $display; ?>;"
                  data-action="<?php echo Data::cacheControlClearAction; ?>"
                  data-nonce="<?php echo wp_create_nonce(Data::cacheControlClearAction); ?>"
                  data-permalink="<?php echo $permalink; ?>"
          >
            <span class="wc-mfpc-error-msg">
              Clear Cache for <?php echo ucfirst($type) . ': ' . $identifier; ?>
            </span>
          </button>
          <p>Link to Item: <a href="<?php echo $permalink; ?>" target="_blank">Permalink</a></p>
          <p>
            <button id="wc-mfpc-button-refresh" class="button button-secondary"
                    data-action="<?php echo Data::cacheControlRefreshAction; ?>"
                    data-nonce="<?php echo wp_create_nonce(Data::cacheControlRefreshAction); ?>"
                    data-permalink="<?php echo $permalink; ?>"
            >
              <span class="dashicons dashicons-image-rotate" style="font-size: 100%; margin-top: 0.45rem"></span>
              Refresh
            </button>
          </p>
          <i style="font-size: smaller">Provided by InvincibleBrands Development</i>
        </div>
        <?php

        add_action('admin_print_footer_scripts', [ self::class, 'printCacheControlScripts' ]);
    }

    /**
     * Prints the Script which creates the AJAX for "Cache Control".
     *
     * @return void
     */
    public static function printCacheControlScripts()
    {
        ?>
        <script>
          (function ($) {
            let buttonClear      = $("#wc-mfpc-button-clear");
            let buttonRefresh    = $("#wc-mfpc-button-refresh");
            let status           = $("#wc-mfpc-cache-status");
            let stringOk         = '<b class="wc-mfpc-ok-msg">Cached</b>';
            let stringError      = '<b class="wc-mfpc-error-msg">Not cached</b>';
            let cacheControlAjax = function (event) {
              event.preventDefault();

              let action = $(this).attr('data-action');

              $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                  action: action,
                  nonce: $(this).attr('data-nonce'),
                  permalink: $(this).attr('data-permalink'),
                },
                success: function (data, textStatus, XMLHttpRequest) {

                  if (action === "<?php echo Data::cacheControlClearAction; ?>") {
                    buttonClear.hide();
                    status.html(stringError);
                  } else if (action === "<?php echo Data::cacheControlRefreshAction; ?>") {
                    if (data) {
                      buttonClear.show();
                      status.html(stringOk);
                    } else {
                      buttonClear.hide();
                      status.html(stringError);
                    }
                  } else {
                    alert(data);
                  }

                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                  alert(errorThrown);
                }
              });

            };

            buttonClear.on('click', cacheControlAjax);
            buttonRefresh.on('click', cacheControlAjax);

          })(jQuery);
        </script>
        <?php
    }

    /**
     * Renders the settings page with all of its parts as part of the WooCommerce admin menu tree.
     *
     * @return void
     */
    public static function render()
    {
        if (! isset($_GET[ 'section' ]) || $_GET[ 'section' ] !== 'full_page_cache') {

            return;
        }

        /*
         * WooCommerce uses in the input functions the global $post->ID which is empty as we are not editing a Post
         * object. In order to avoid any errors global $post->ID will be set to null. In the unlikely case that $post
         * does indeed contain something, we store its contents in a temp var and reset it to its original value once
         * rendering is completed.
         */
        global $post;

        $postOriginal = $post;
        $post         = new \stdClass();
        $post->ID     = null;

        ?>
        </form>

        <?php self::renderMessages(); ?>

        <h1 style="display: inline-block; margin-right: 0.5rem; margin-top: 1rem;">
          WooCommerce Memcached Full Page Cache
        </h1>
        <a href="https://github.com/hypeventures/woocommerce-memcached-full-page-cache" target="_blank">
          Visit the plugin repository on GitHub
        </a>
        <hr>
        <p>
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-gplv3.svg">
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-wc.svg">
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-wp4.svg">
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-wp5.svg">
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-php7.svg">
          <img src="<?php echo WC_MFPC_PLUGIN_URL; ?>assets/badge-memcached.svg">
        </p>

        <?php self::renderActionButtons('flush'); ?>

        <form autocomplete="off" method="post" action="admin-post.php" id="<?php echo Data::pluginConstant; ?>-settings" class="plugin-admin wc-mfpc-admin">

          <?php wp_nonce_field(Data::buttonSave); ?>
          <?php do_action('wc_mfpc_settings_form_top'); ?>

          <fieldset id="<?php echo Data::pluginConstant ?>-servers">
            <legend>Memcached connection settings</legend>
            <?php self::renderMemcachedConnectionSettings(); ?>
          </fieldset>

          <?php self::renderSubmit(); ?>

          <fieldset id="<?php echo Data::pluginConstant; ?>-type">
              <legend>Cache settings</legend>
              <?php self::renderCacheSettings(); ?>
          </fieldset>

          <?php self::renderSubmit(); ?>

          <fieldset id="<?php echo Data::pluginConstant ?>-exceptions">
              <legend>Exception settings</legend>
              <?php self::renderExceptionSettings(); ?>
          </fieldset>

          <?php self::renderSubmit(); ?>

          <fieldset id="<?php echo Data::pluginConstant; ?>-debug">
            <legend>Header settings</legend>
              <?php self::renderDebugSettings(); ?>
          </fieldset>

          <?php self::renderSubmit(); ?>
          <?php do_action('wc_mfpc_settings_form_bottom'); ?>

        </form>

        <?php self::renderActionButtons('reset'); ?>

        <p style="background: #fff; padding: 0.5rem 1rem; line-height: 2rem;">
          <a href="https://github.com/hypeventures/woocommerce-memcached-full-page-cache/issues" target="_blank">
            Issues? Open an issue on GitHub.
          </a>
          <br>
          <a href="https://github.com/hypeventures/woocommerce-memcached-full-page-cache/blob/master/README.md" target="_blank">
            You want to customize this? Have a look at the example in the README.
          </a>
        </p>
        <form hidden>
        <?php

        $post = $postOriginal;
    }

    /**
     * Renders information for administrators if conditions are met.
     *
     * @return void
     */
    private static function renderMessages()
    {
        /**
         * @var Config $wcMfpcConfig
         * @var Admin  $wcMfpcAdmin
         * @var array  $wc_mfpc_config_array
         */
        global $wcMfpcConfig, $wcMfpcAdmin, $wc_mfpc_config_array;

        /*
         * if options were saved
         */
        if (isset($_GET[ Data::keySave ]) && $_GET[ Data::keySave ] === 'true') {

            Alert::alert('<strong>Settings saved.</strong>');

        }

        /*
         * if options were deleted
         */
        if (isset($_GET[ Data::keyRefresh ]) && $_GET[ Data::keyRefresh ] === 'true') {

            Alert::alert('<strong>Plugin options deleted. </strong>');

        }

        /*
         * if flushed
         */
        if (isset($_GET[ Data::keyFlush ]) && $_GET[ Data::keyFlush ] === 'true') {

            Alert::alert('<strong>Cache flushed.</strong>');

        }

        $settingsLink = ' &raquo; <a href="' . Data::settingsLink. '">WC-MFPC Settings</a>';

        /*
         * look for global settings array
         */
        if (isset($wc_mfpc_config_array[ $_SERVER[ 'HTTP_HOST' ] ])) {

            Alert::alert(sprintf(
                'This site was reached as %s ( according to PHP HTTP_HOST ) and there are no settings present '
                . 'for this domain in the WC-MFPC configuration yet. Please save the %s for this blog.',
                $_SERVER[ 'HTTP_HOST' ], $settingsLink
            ), LOG_WARNING);

        }

        /*
         * look for writable advancedCache file
         */
        if (file_exists(Data::advancedCache) && ! is_writable(Data::advancedCache)) {

            Alert::alert(sprintf(
              'Advanced cache file (%s) is not writeable!<br />Please change the permissions on the file.',
              Data::advancedCache
            ), LOG_WARNING);

        }

        /*
         * Check if advanced-cache.php file exists.
         */
        if (! file_exists(Data::advancedCache)) {

            Alert::alert(sprintf('Advanced cache file is yet to be generated, please save %s', $settingsLink), LOG_WARNING);

        }

        /*
         * check if php memcached extension is active
         */
        if (! extension_loaded('memcached')) {

            Alert::alert('Memcached activated but the PHP extension was not found.<br />Please activate the module!', LOG_WARNING);

        }

        /*
         * If SASL is not used but authentication info was provided.
         */
        if (! ini_get('memcached.use_sasl') && (! empty($wcMfpcConfig->getAuthuser()) || ! empty($wcMfpcConfig->getAuthpass()))) {
            Alert::alert(
              "WARNING: you've entered username and/or password for memcached authentication ( or your browser's" .
              "autocomplete did ) which will not work unless you enable memcached sasl in the PHP settings:" .
              "add `memcached.use_sasl=1` to php.ini",
              LOG_ERR, true
            );
        }

        Alert::alert(self::getServersStatusAlert());
    }

    /**
     * Generates the Alert message string to show Memcached Servers status.
     *
     * @return string
     */
    private static function getServersStatusAlert()
    {
        $servers = WcMfpc::getMemcached()
                         ->getStatusArray();
        $message = '<b>Connection status:</b></p><p>';

        if (empty ($servers) || ! is_array($servers)) {

            return $message . '<b class="wc-mfpc-error-msg">WARNING: Could not establish ANY connection. Please review "Memcached Connection Settings"!</b>';
        }

        foreach ($servers as $server_string => $status) {

            $message .= $server_string . " => ";

            if ($status == 0) {

                $message .= '<span class="wc-mfpc-error-msg">Down</span><br>';

            } elseif ($status == 1) {

                $message .= '<span class="wc-mfpc-ok-msg">Up & running</span><br>';

            } else {

                $message .= '<span class="wc-mfpc-error-msg">Unknown, please try re-saving settings!</span><br>';

            }

        }

        return $message;
    }

    /**
     * Renders the Form with the action buttons for "Clear-Cache" & "Reset-Settings".
     *
     * @param string $button   'flush'|'reset'
     *
     * @return void
     */
    private static function renderActionButtons($button = 'flush')
    {
        ?>
        <form method="post" action="admin-post.php" id="<?php echo Data::pluginConstant ?>-commands" class="plugin-admin wc-mfpc-admin">
          <p>
            <?php

            if ($button === 'flush') {

                self::renderSubmit(
                  'Flush Cache',
                  'secondary',
                  Data::buttonFlush,
                  false, 'trash',
                  'color: #f33; margin: 1rem 1rem 1rem 0;'
                );
                echo '<span class="wc-mfpc-error-msg">Flushes Memcached. All entries in the cache are deleted, <b>including the ones that were set by other processes.</b></span>';

            } else {

                self::renderSubmit(
                  'Reset Settings',
                  'secondary',
                  Data::buttonReset,
                  false,
                  'image-rotate',
                  'color: #f33; margin: 1rem 1rem 1rem 0;'
                );
                echo '<span class="wc-mfpc-error-msg"><b>Resets ALL settings on this page to DEFAULT.</b></span>';

            }
            ?>
          </p>
        </form>
        <?php
    }

    /**
     * Renders the "Memcached Connection Settings" fieldset inputs.
     *
     * @return void
     */
    private static function renderMemcachedConnectionSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_text_input([
            'id'          => 'hosts',
            'label'       => 'Host(s)',
            'class'       => 'short',
            'description' => '<b>host1:port1,host2:port2,...</b> - OR - <b>unix://[socket_path]</b>',
            'value'       => $wcMfpcConfig->getHosts(),
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'memcached_binary',
            'label'       => 'Enable binary mode',
            'description' => 'Some memcached proxies and implementations only support the ASCII protocol.',
            'value'       => $wcMfpcConfig->isMemcachedBinary() ? 'yes' : 'no',
        ]);

        do_action('wc_mfpc_settings_form_memcached_connection');

        /*
         * If memcached does not support or if authentication is disabled, do not show the auth input fields to avoid
         * confuseion.
         */
        if (
            ! version_compare(phpversion('memcached'), '2.0.0', '>=')
            || ((int) ini_get('memcached.use_sasl')) !== 1
        ) {

            return;
        }

        woocommerce_wp_text_input([
            'id'          => 'authuser',
            'label'       => 'Username',
            'class'       => 'short',
            'description' => 'Username for authentication with Memcached <span class="wc-mfpc-error-msg">(Only if SASL is enabled)</span>',
            'value'       => $wcMfpcConfig->getAuthuser(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'authpass',
            'label'       => 'Password',
            'class'       => 'short',
            'description' => 'Username for authentication with Memcached <span class="wc-mfpc-error-msg">(Only if SASL is enabled)</span>',
            'value'       => $wcMfpcConfig->getAuthpass(),
        ]);
    }

    /**
     * Renders the "Cache Settings" fieldset inputs.
     *
     * @return void
     */
    private static function renderCacheSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_text_input([
            'id'          => 'expire',
            'label'       => 'Cache expiration',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of post entry in seconds, including custom post types and pages.',
            'value'       => $wcMfpcConfig->getExpire(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'browsercache',
            'label'       => 'Browser cache expiration',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of posts/pages/singles for the browser cache.',
            'value'       => $wcMfpcConfig->getBrowsercache(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'charset',
            'label'       => 'Charset',
            'class'       => 'short',
            'description' => 'Charset of HTML and XML (pages and feeds) data.',
            'value'       => $wcMfpcConfig->getCharset(),
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'comments_invalidate',
            'label'       => 'Invalidate on comment actions',
            'description' => 'Trigger cache invalidation when a comments is posted, edited, trashed.',
            'value'       => $wcMfpcConfig->isCommentsInvalidate() ? 'yes' : 'no',
        ]);
        woocommerce_wp_text_input([
            'id'          => 'prefix_data',
            'label'       => 'Data prefix',
            'class'       => 'short',
            'description' => 'Prefix for HTML content keys, can be used in nginx.',
            'value'       => $wcMfpcConfig->getPrefixData(),
        ]);
        ?>
        <div class="description-addon">
          <b>WARNING</b>: changing this will result the previous cache to becomes invalid!<br />
          If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value.
        </div>
        <?php
        woocommerce_wp_text_input([
            'id'          => 'prefix_meta',
            'label'       => 'Meta prefix',
            'class'       => 'short',
            'description' => 'Prefix for meta content keys, used only with PHP processing.',
            'value'       => $wcMfpcConfig->getPrefixMeta(),
        ]);
        ?>
        <div class="description-addon">
          <b>WARNING</b>: changing this will result the previous cache to becomes invalid!
        </div>
        <?php

        do_action('wc_mfpc_settings_form_cache');
    }

    /**
     * Renders the "Exception Settings" fieldset inputs.
     *
     * @return void
     */
    private static function renderExceptionSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_checkbox([
            'id'          => 'cache_loggedin',
            'label'       => 'Cache for logged in users',
            'description' => 'Enable to cache pages even if user is logged in.',
            'value'       => $wcMfpcConfig->isCacheLoggedin() ? 'yes' : 'no',
        ]);
        woocommerce_wp_text_input([
            'id'          => 'nocache_cookies',
            'label'       => 'Exclude based on cookies',
            'class'       => 'short',
            'description' => 'Exclude content based on cookies names starting with this from caching. Separate multiple cookies names with commas.',
            'value'       => $wcMfpcConfig->getNocacheCookies(),
        ]);
        ?>
        <div class="description-addon">
          <b>WARNING:</b>
          <i>If you are caching with nginx, you should update your nginx configuration and reload nginx after changing this value. </i>
        </div>
        <?php
        woocommerce_wp_textarea_input([
            'id'          => 'nocache_url',
            'label'       => 'Exclude URLs',
            'class'       => 'short',
            'description' => '<b>WARINING: Use with caution!</b> Use multiple RegEx patterns like e.g. <em>pattern1|pattern2|etc</em>',
            'value'       => $wcMfpcConfig->getNocacheUrl(),
        ]);
        ?>
        <div class="wc-mfpc-error-msg" style="padding-bottom: 1rem;">
          <h3>INFO:</h3>
          Dynamic WooCommerce pages are ignored by default via RegEx on the URL. <b>Pattern:</b> <i><?php echo $wcMfpcConfig->getNocacheWoocommerceUrl(); ?></i><br>
          <small>(This will be updated to your used urls dynamically after saving the config.)</small>
        </div>
        <?php

        do_action('wc_mfpc_settings_form_exception');
    }

    /**
     * Renders the "Header & Debug Settings" fieldset inputs.
     *
     * @return void
     */
    private static function renderDebugSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_checkbox([
            'id'          => 'pingback_header',
            'label'       => 'X-Pingback header preservation',
            'description' => 'Enable to preserve X-Pingback URL in response header.',
            'value'       => $wcMfpcConfig->isPingbackHeader() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'response_header',
            'label'       => 'X-Cache-Engine HTTP header',
            'description' => 'Enable to add X-Cache-Engine HTTP header to HTTP responses.',
            'value'       => $wcMfpcConfig->isResponseHeader() ? 'yes' : 'no',
        ]);

        do_action('wc_mfpc_settings_form_debug');
    }

    /**
     * Renders the custom buttons on the settings page.
     *
     * @param string $text
     * @param string $class
     * @param string $name
     * @param bool   $wrap
     * @param string $icon
     * @param string $style
     *
     * @return void
     */
    private static function renderSubmit($text = 'Save changes', $class = 'primary', $name = Data::buttonSave, $wrap = true, $icon = 'lock', $style = '')
    {
        if ($wrap) {

            echo '<p class="submit">';

        }

        ?>
        <input type="hidden" name="action" value="<?php echo $name; ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce($name); ?>">
        <button type="submit" class="button button-<?php echo $class; ?>  wc-mfpc-button"
                name="<?php echo $name; ?>" style="<?php echo $style; ?>"
        >
          <span class="dashicons dashicons-<?php echo $icon; ?>"></span>
          <?php echo $text; ?>
        </button>
        <?php

        if ($wrap) {

            echo '</p>';

        }
    }

}
