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

    public static $notices = '';

    private static $levels = [
        LOG_ERR     => 1,
        LOG_WARNING => 1,
    ];

    /**
     * Displays any given message as formatted alert.
     *
     * @param string $msg     Error message
     * @param int    $level   "level" of error
     *
     * @return bool
     */
    static public function alert($msg = '', $level = 0)
    {
        if (empty($msg)) {

            return false;
        }

        $css = 'updated';

        if (isset(self::$levels[ $level ])) {

            $css = 'error';

        }

        self::$notices .= '<div class="' . $css . '"><p>' . esc_html($msg) . '</p></div>';

        return add_action('admin_notices', [ self::class, 'renderNotices' ]);
    }

    /**
     * Renders the notices when "admin_notices" hook is called.
     *
     * @return void
     */
    public static function renderNotices()
    {
        echo self::$notices;
    }

}