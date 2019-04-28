<?php

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
     * @var array
     */
    private $select_invalidation_method =  [
        'Flush Cache',
        'Only modified Post',
        'Modified Post & related Taxonomies',
    ];

    /**
     * @var array
     */
    private $list_uri_vars = [
        '$scheme'           => 'The HTTP scheme (i.e. http, https).',
        '$host'             => 'Host in the header of request or name of the server processing the request if the Host header is not available.',
        '$request_uri'      => 'The *original* request URI as received from the client including the args',
        '$remote_user'      => 'Name of user, authenticated by the Auth Basic Module',
        '$cookie_PHPSESSID' => 'PHP Session Cookie ID, if set ( empty if not )',
        '$accept_lang'      => 'First HTTP Accept Lang set in the HTTP request',
    ];

    /**
     * admin panel, the admin page displayed for plugin settings
     */
    public function render()
    {
        /*
         * security, if somehow we're running without WordPress security functions
         */
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {

            wp_redirect(admin_url());
            exit;

        }

        /*
         * WooCommerce uses in the input functions the global $post->ID which is empty as we are not editing a Post
         * object. In order to avoid any errors global $post->ID will be set to null. In the unlikely case that $post
         * does indeed contain something, we store its contents a temp var and reset it to its original value once
         * rendering is completed.
         */
        global $post;

        $postOriginal = $post;
        $post         = new \stdClass();
        $post->ID     = null;

        ?>
        <div class="wrap">
          <h1>WooCommerce Memcached Full Page Cache</h1>

          <?php $this->renderMessages()->renderActionButtons('flush'); ?>

          <form autocomplete="off" method="post" action="#" id="<?php echo Data::plugin_constant ?>-settings" class="plugin-admin">

            <?php wp_nonce_field('wc-mfpc'); ?>

            <fieldset id="<?php echo Data::plugin_constant ?>-servers">
              <legend>Memcached connection settings</legend>
              <?php $this->renderMemcachedConnectionSettings(); ?>
            </fieldset>

            <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

            <fieldset id="<?php echo Data::plugin_constant; ?>-type">
                <legend>Cache settings</legend>
                <?php $this->renderCacheSettings(); ?>
            </fieldset>

            <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

            <fieldset id="<?php echo Data::plugin_constant ?>-exceptions">
                <legend>Exception settings</legend>
                <?php $this->renderExceptionSettings(); ?>
            </fieldset>

            <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

            <fieldset id="<?php echo Data::plugin_constant; ?>-debug">
              <legend>Header / Debug settings</legend>
                <?php $this->renderDebugSettings(); ?>
            </fieldset>

            <?php submit_button('&#x1F5AB; Save Changes', 'primary', Data::button_save); ?>

          </form>

          <?php $this->renderActionButtons('reset'); ?>

        </div>
        <?php

        $post = $postOriginal;
    }

    /**
     * Renders information for administrators if conditions are met.
     *
     * @return AdminView
     */
    private function renderMessages()
    {
        /**
         * @var Data   $wcMfpcData
         * @var Config $wcMfpcConfig
         * @var Admin  $wcMfpcAdmin
         */
        global $wcMfpcData, $wcMfpcConfig, $wcMfpcAdmin;

        /*
         * if options were saved
         */
        if (isset($_GET[ Data::key_save ]) && $_GET[ Data::key_save ] == 'true' || $wcMfpcAdmin->getStatus() == 1) {

            Alert::alert('<strong>Settings saved.</strong>');

        }

        /*
         * if options were deleted
         */
        if (isset($_GET[ Data::key_delete ]) && $_GET[ Data::key_delete ] == 'true' || $wcMfpcAdmin->getStatus() == 2) {

            Alert::alert('<strong>Plugin options deleted. </strong>');

        }

        /*
         * if flushed
         */
        if (isset($_GET[ Data::key_flush ]) && $_GET[ Data::key_flush ] == 'true' || $wcMfpcAdmin->getStatus() == 3) {

            Alert::alert('<strong>Cache flushed.</strong>');

        }

        $settings_link = ' &raquo; <a href="' . $wcMfpcData->settings_link . '">WC-MFPC Settings</a>';

        /*
         * look for global settings array
         */
        if (! $wcMfpcAdmin->isGlobalSaved()) {

            Alert::alert(sprintf(
                'This site was reached as %s ( according to PHP HTTP_HOST ) and there are no settings present for this domain in the WC-MFPC configuration yet. Please save the %s for the domain or fix the webserver configuration!',
                $_SERVER[ 'HTTP_HOST' ], $settings_link
            ), LOG_WARNING);

        }

        /*
         * look for writable acache file
         */
        if (file_exists($wcMfpcData->acache) && ! is_writable($wcMfpcData->acache)) {

            Alert::alert(sprintf('Advanced cache file (%s) is not writeable!<br />Please change the permissions on the file.', $wcMfpcData->acache), LOG_WARNING);

        }

        /*
         * look for acache file
         */
        if (! file_exists($wcMfpcData->acache)) {

            Alert::alert(sprintf('Advanced cache file is yet to be generated, please save %s', $settings_link), LOG_WARNING);

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

        Alert::alert($this->getServersStatusAlert());

        return $this;
    }

    /**
     * Generates the Alert message string to show Memcached Servers status.
     *
     * @return string
     */
    private function getServersStatusAlert()
    {
        global $wcMfpc;

        $servers = $wcMfpc->backend->status();
        $message = '<b>Connection status:</b></p><p>';

        if (empty ($servers) || ! is_array($servers)) {

            return $message . '<b class="error-msg">WARNING: Could not establish ANY connection. Please review "Memcached Connection Settings"!</b>';
        }

        foreach ($servers as $server_string => $status) {

            $message .= $server_string . " => ";

            if ($status == 0) {

                $message .= '<span class="error-msg">Down</span><br>';

            } elseif ($status == 1) {

                $message .= '<span class="ok-msg">Up & running</span><br>';

            } else {

                $message .= '<span class="error-msg">Unknown, please try re-saving settings!</span><br>';

            }

        }

        return $message;
    }

    /**
     * Renders the Form with the action buttons for "Clear-Cache" & "Reset-Settings".
     *
     * @param string $button   'flush'|'reset'
     *
     * @return AdminView
     */
    private function renderActionButtons($button = 'flush')
    {
        if (empty($button) || ! in_array($button, [ 'flush', 'reset' ])) {

            echo '<p class="error-msg"><b>NO or UNKNOWN action button defined to render.</b></p>';

            return $this;
        }
        ?>
        <form method="post" action="#" id="<?php echo Data::plugin_constant ?>-commands" class="plugin-admin">
          <p>
            <?php
            wp_nonce_field('wc-mfpc');

            if ($button === 'flush') {

                submit_button('&#x1f5d1; Flush Cache', 'secondary', Data::button_flush, false, [
                    'style' => 'color: #f33; font-weight: normal; margin: 1rem 1rem 1rem 0;',
                ]);
                echo '<span class="error-msg">Flushes Memcached. All entries in the cache are deleted, <b>including the ones that were set by other processes.</b></span>';

            } else {

                submit_button('&#x21bb; Reset Settings', 'secondary', Data::button_delete, false, [
                    'style' => 'color: #f33; font-weight: normal; margin: 1rem 1rem 1rem 0;',
                ]);
                echo '<span class="error-msg"><b>Resets ALL settings on this page to DEFAULT.</b></span>';

            }
            ?>
          </p>
        </form>
        <?php

        return $this;
    }

    /**
     * Renders the "Memcached Connection Settings" fieldset inputs.
     *
     * @return void
     */
    private function renderMemcachedConnectionSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_text_input([
            'id'          => 'hosts',
            'label'       => 'Host(s)',
            'class'       => 'short',
            'description' => '<b>host1:port1,host2:port2,...</b> - OR - <b>unix://[socket_path]</b>',
            'value'       => $wcMfpcConfig->getHosts(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'authuser',
            'label'       => 'Username',
            'class'       => 'short',
            'description' => 'Username for authentication with Memcached <span class="error-msg">(Only if SASL is enabled)</span>',
            'value'       => $wcMfpcConfig->getAuthuser(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'authpass',
            'label'       => 'Password',
            'class'       => 'short',
            'description' => 'Username for authentication with Memcached <span class="error-msg">(Only if SASL is enabled)</span>',
            'value'       => $wcMfpcConfig->getAuthpass(),
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'memcached_binary',
            'label'       => 'Enable binary mode',
            'description' => 'Some memcached proxies and implementations only support the ASCII protocol.',
            'value'       => $wcMfpcConfig->isMemcachedBinary() ? 'yes' : 'no',
        ]);
    }

    /**
     * Renders the "Cache Settings" fieldset inputs.
     *
     * @return void
     */
    private function renderCacheSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_text_input([
            'id'          => 'expire',
            'label'       => 'Expiration of Posts',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of post entry in seconds, including custom post types and pages.',
            'value'       => $wcMfpcConfig->getExpire(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'browsercache',
            'label'       => 'Browser cache expiration of Posts',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of posts/pages/singles for the browser cache.',
            'value'       => $wcMfpcConfig->getBrowsercache(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'expire_taxonomy',
            'label'       => 'Expiration of Taxonomies',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of taxonomy entry in seconds, including custom taxonomy.',
            'value'       => $wcMfpcConfig->getExpireTaxonomy(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'browsercache_taxonomy',
            'label'       => 'Browser cache expiration of Taxonomies',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of taxonomy for the browser cache.',
            'value'       => $wcMfpcConfig->getBrowsercacheTaxonomy(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'expire_home',
            'label'       => 'Expiration of Home',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of home on server side.',
            'value'       => $wcMfpcConfig->getExpireHome(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'browsercache_home',
            'label'       => 'Browser cache expiration of Home',
            'type'        => 'number',
            'data_type'   => 'decimal',
            'class'       => 'short',
            'description' => 'Sets validity time of home for the browser cache.',
            'value'       => $wcMfpcConfig->getBrowsercacheHome(),
        ]);
        woocommerce_wp_text_input([
            'id'          => 'charset',
            'label'       => 'Charset',
            'class'       => 'short',
            'description' => 'Charset of HTML and XML (pages and feeds) data.',
            'value'       => $wcMfpcConfig->getCharset(),
        ]);
        woocommerce_wp_select([
            'id'          => 'invalidation_method',
            'label'       => 'Cache invalidation method',
            'class'       => 'short',
            'description' => 'Select cache invalidation method.',
            'options'     => $this->select_invalidation_method,
            'value'       => $wcMfpcConfig->getInvalidationMethod(),
        ]);
        ?>
        <ol class="description-addon">
          <li>
            <b><?php echo $this->select_invalidation_method[ 0 ]; ?></b>
            - Clears everything in storage, <span class="error-msg">including values set by other applications.</span>
          </li>
          <li>
            <b><?php echo $this->select_invalidation_method[ 1 ]; ?></b>
            - Clear only the modified posts entry, everything else remains in cache.
          </li>
          <li>
            <b><?php echo $this->select_invalidation_method[ 2 ]; ?></b>
            - Unvalidates post and the taxonomy related to the Post.
          </li>
        </ol>
        <?php
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
        woocommerce_wp_text_input([
            'id'          => 'key',
            'label'       => 'Key scheme',
            'class'       => 'short',
            'description' => 'Key layout: <b>please use the guide below to change it.</b>',
            'value'       => $wcMfpcConfig->getKey(),
        ]);
        ?>
        <div class="description-addon">
          <b>WARNING</b>: changing this will result the previous cache to becomes invalid!<br />
          If you are caching with nginx, you should update your nginx configuration and reload nginx after
          changing this value.
        </div>
        <table class="description-addon" style="margin-top: 0;" cellspacing="0" cellpadding="0">
          <tr><th colspan="2" style="text-align: left;"><h3>Possible variables:</h3></th></tr>
          <?php
          foreach ($this->list_uri_vars as $uri => $desc) {

              echo '<tr><td><b>' . $uri . '</b>:</td><td><i>' . $desc . '</i></td></tr>';

          }
          ?>
        </table>
        <?php
    }

    /**
     * Renders the "Exception Settings" fieldset inputs.
     *
     * @return void
     */
    private function renderExceptionSettings()
    {
        global $wcMfpcConfig;

        woocommerce_wp_checkbox([
            'id'          => 'cache_loggedin',
            'label'       => 'Cache for logged in users',
            'description' => 'Enable to cache pages even if user is logged in.',
            'value'       => $wcMfpcConfig->isCacheLoggedin() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_home',
            'label'       => 'Exclude home',
            'description' => 'Enable to never cache home.',
            'value'       => $wcMfpcConfig->isNocacheHome() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_feed',
            'label'       => 'Exclude feeds',
            'description' => 'Enable to never cache feeds.',
            'value'       => $wcMfpcConfig->isNocacheFeed() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_archive',
            'label'       => 'Exclude archives',
            'description' => 'Enable to never cache archives.',
            'value'       => $wcMfpcConfig->isNocacheArchive() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_page',
            'label'       => 'Exclude pages',
            'description' => 'Enable to never cache pages.',
            'value'       => $wcMfpcConfig->isNocachePage() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_single',
            'label'       => 'Exclude singulars',
            'description' => 'Enable to never cache singulars.',
            'value'       => $wcMfpcConfig->isNocacheSingle() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_dyn',
            'label'       => 'Exclude dynamic requests',
            'description' => 'Enable to exclude every dynamic request URL with GET parameters.',
            'value'       => $wcMfpcConfig->isNocacheDyn() ? 'yes' : 'no',
        ]);
        woocommerce_wp_checkbox([
            'id'          => 'nocache_woocommerce',
            'label'       => 'Exclude dynamic WooCommerce pages',
            'description' => 'Enable to exclude every dynamic WooCommerce page.',
            'value'       => $wcMfpcConfig->isNocacheWoocommerce() ? 'yes' : 'no',
        ]);
        ?>
        <div class="description-addon"><b>Pattern:</b> <i><?php echo $wcMfpcConfig->getNocacheWoocommerceUrl(); ?></i></div>
        <?php
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
    }

    /**
     * Renders the "Header & Debug Settings" fieldset inputs.
     *
     * @return void
     */
    private function renderDebugSettings()
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
        woocommerce_wp_checkbox([
            'id'          => 'generate_time',
            'label'       => 'HTML debug comment',
            'description' => 'Adds comment string including plugin name, cache engine and page generation time to every generated entry before closing <b>body</b> tag.',
            'value'       => $wcMfpcConfig->isGenerateTime() ? 'yes' : 'no',
        ]);
    }

}
