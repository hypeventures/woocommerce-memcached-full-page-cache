<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Alert
 *
 * @package InvincibleBrands\WcMfpc
 */
class Alert
{

    const levels = [
        LOG_ERR     => 1,
        LOG_WARNING => 1,
    ];

    /**
     * Displays any given message as formatted alert.
     *
     * @param string $msg     Error message
     * @param int    $level   "level" of error
     * @param bool   $notice
     *
     * @return void
     */
    public static function alert($msg = '', $level = 0, $notice = false)
    {
        if (empty($msg) || php_sapi_name() === "cli") {

            return;
        }

        $css = 'updated';

        if (isset(self::levels[ $level ])) {

            $css = 'error';

        }

        $msg = '<div class="' . $css . '"><p>' . $msg . '</p></div>';

        if ($notice) {

            add_action('admin_notices', function() use ($msg) { echo $msg; }, 10 );

        } else {

            echo $msg;

        }
    }

}