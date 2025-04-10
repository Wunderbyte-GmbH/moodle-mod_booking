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
 * Shortcodes for mod_booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 4.1
 * @copyright 2023 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache_helper;
use context_system;
use Exception;
use html_writer;
use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\intrange;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\customfield\booking_handler;
use mod_booking\local\modechecker;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\bulkoperations_table;
use mod_booking\utils\wb_payment;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Deals with local_shortcodes regarding booking.
 */
class shortcodes_handler {
    /**
     * [Description for validatecondition]
     *
     * @param mixed $shortcode
     * @param mixed $args
     * @param mixed $requirespro
     *
     * @return [type]
     *
     */
    public static function validatecondition($shortcode, $args, $requirespro) {
        $answerarray = [
            'error' => 0,
            'message' => "",
        ];
        $shortcodesettingcheck = self::shotcodes_active($shortcode, $answerarray);
        $proversioncheck = self::license_is_activated($shortcode, $answerarray);
        $requiresargs = self::requires_args($shortcode, $answerarray, $args);

        if ($shortcodesettingcheck['error'] == 1) {
            return $shortcodesettingcheck['message'];
        }
        if ($proversioncheck['error'] == 1 && $requirespro) {
            return $proversioncheck['message'];
        }

        if ($requiresargs['error'] == 1) {
            return $requiresargs['message'];
        }

        return "";
    }
    /**
     * Check whether shortcodes are enabled.
     *
     * @param string $shortcode
     * @return array
     */
    private static function shotcodes_active($shortcode, $answerarray) {
        $answerarray = [
            'error' => 0,
            'message' => '',
        ];

        if (!get_config('booking', 'shortcodesoff')) {
            return $answerarray;
        }

        $answerarray['error'] = 1;
        $answerarray['message'] = "<div class='alert alert-warning'>" .
            get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        return $answerarray;
    }
    private static function license_is_activated($shortcode, $answerarray) {

    }
    /**
     * [Description for requires_args]
     *
     * @param mixed $shortcode
     * @param mixed $answerarray
     * @param mixed $args
     *
     * @return [type]
     *
     */
    private static function requires_args($shortcode, $answerarray, $args) {
        switch ($shortcode) {
            case 'courselist':
                if (empty($args['cmid'])) {
                    $answerarray['error'] = 1;
                    $answerarray['message'] = get_string('definecmidforshortcode', 'mod_booking');
                }
                break;
            default:
                break;
        }
        return $answerarray;
    }
}
