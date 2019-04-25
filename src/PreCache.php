<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class PreCache
 *
 * @package InvincibleBrands\WcMfpc
 *          
 * @todo Evaluate if pre-caching is worth it at all! Delete this file if it isn't.
 */
class PreCache
{

    /** 
     * run full-site precache
     */
    public function precache_coldrun()
    {
        global $wcMfpcData;

        //  container for links to precache, well be accessed by reference
        $links = [];

        // when plugin is  network wide active, we need to pre-cache for all link of all blogs
        if ($wcMfpcData->network) {

            // list all blogs
            global $wpdb;

            $pfix      = empty ($wpdb->base_prefix) ? 'wp_' : $wpdb->base_prefix;
            $blog_list = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $pfix . "blogs ORDER BY blog_id", ''));

            foreach ($blog_list as $blog) {

                if ($blog->archived != 1 && $blog->spam != 1 && $blog->deleted != 1) {

                    // get permalinks for this blog
                    $this->precache_list_permalinks($links, $blog->blog_id);

                }

            }

        } else {

            // no network, better
            $this->precache_list_permalinks($links, false);

        }

        // double check if we do have any links to pre-cache
        if (! empty ($links)) {

            $this->precache($links);

        }
    }

    /**
     * gets all post-like entry permalinks for a site, returns values in passed-by-reference array
     *
     * @param      $links
     * @param bool $site
     */
    private function precache_list_permalinks(&$links, $site = false)
    {
        // $post will be populated when running throught the posts
        global $post, $wcMfpc;

        include_once(ABSPATH . "wp-load.php");
        // if a site id was provided, save current blog and change to the other site

        if ($site !== false) {

            switch_to_blog($site);
            $url = self::_site_url($site);
            // $url = get_blog_option ( $site, 'siteurl' );
            if (substr($url, -1) !== '/') {

                $url = $url . '/';

            }
            $links[ $url ] = true;

        }

        // get all published posts
        $args  = [
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];
        $posts = new \WP_Query($args);

        // get all the posts, one by one 
        while ($posts->have_posts()) {

            $posts->the_post();

            // get the permalink for currently selected post
            switch ($post->post_type) {

                case 'revision':
                case 'nav_menu_item':
                    break;
                case 'page':
                    $permalink = get_page_link($post->ID);
                    break;
                /*
                case 'post':
                    $permalink = get_permalink( $post->ID );
                    break;
                */
                case 'attachment':
                    $permalink = get_attachment_link($post->ID);
                    break;
                default:
                    $permalink = get_permalink($post->ID);
                    break;

            }

            // in case the bloglinks are relative links add the base url, site specific
            $baseurl = empty($url) ? self::_site_url() : $url;

            if (! strstr($permalink, $baseurl)) {

                $permalink = $baseurl . $permalink;

            }

            // collect permalinks
            $links[ $permalink ] = true;
        }

        $wcMfpc->backend->taxonomy_links($links);
        // just in case, reset $post
        wp_reset_postdata();

        // switch back to original site if we navigated away
        if ($site !== false) {

            switch_to_blog(get_current_blog_id());

        }
    }

    /**
     * read option; will handle network wide or standalone site options
     *
     * @param string $site
     * @param bool   $network
     *
     * @return mixed|string
     */
    public static function _site_url($site = '', $network = false)
    {
        if ($network && ! empty($site)) {
            $url = get_blog_option($site, 'siteurl');
        } else {
            $url = get_bloginfo('url');
        }

        return $url;
    }

    /**
     * generate cache entry for every available permalink, might be very-very slow,
     * therefore it starts a background process
     *
     * @param $links
     *
     * @return void
     */
    private function precache(&$links)
    {
        global $wcMfpcData;

        // double check if we do have any links to pre-cache
        if (! empty ($links) && ! $this->precache_running()) {

            $out = '<?php
                $links = ' . var_export($links, true) . ';

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
                
                unlink ( "' . $wcMfpcData->precache_phpfile . '" );
            ?>';

            file_put_contents($wcMfpcData->precache_phpfile, $out);

            // call the precache worker file in the background
            $shellfunction = $wcMfpcData->shell_function;
            $shellfunction('php ' . $wcMfpcData->precache_phpfile . ' >' . $wcMfpcData->precache_logfile . ' 2>&1 &');

        }
    }

    /**
     * check is precache is still ongoing
     *
     * @return bool
     */
    private function precache_running()
    {
        global $wcMfpcData;

        $return = false;

        // if the precache file exists, it did not finish running as it should delete itself on finish
        if (file_exists($wcMfpcData->precache_phpfile)) {

            $return = true;

        }

        return $return;
    }
    
}