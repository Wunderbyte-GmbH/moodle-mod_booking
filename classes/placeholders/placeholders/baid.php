<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Placeholder for the booking answer id (baid) of a single purchase.
 *
 * The baid uniquely identifies one booking_answers record. Because "book again"
 * allows the same user to purchase the same option more than once, the baid - not
 * a {userid}-{optionid} combination - is the only stable per-purchase identifier
 * (e.g. for an order_id sent to an external shop).
 *
 * render_text() does not carry a booking-answer id, so the value is provided via
 * the static {@see self::$baid}, which the caller sets right before rendering.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class baid extends \mod_booking\placeholders\placeholder_base {
    /**
     * The booking answer id for the current render pass.
     * The caller sets this immediately before calling placeholders_info::render_text().
     * @var int
     */
    public static int $baid = 0;

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE
    ) {

        // The baid is purchase specific and must never be cached per option/user.
        // When it is not set (0), we deliberately return an empty string so the
        // {baid} token stays visible in the output and the problem is loud, rather
        // than silently emitting a colliding "0".
        return self::$baid > 0 ? (string)self::$baid : '';
    }

    /**
     * Function determine if placeholder class should be called at all.
     *
     * @return bool
     *
     */
    public static function is_applicable(): bool {
        return true;
    }

    /**
     * Function determine if placeholder class works for pollurl.
     *
     * @return bool
     *
     */
    public static function for_pollurl(): bool {
        return true;
    }
}
