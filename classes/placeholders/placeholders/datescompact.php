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
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

use mod_booking\option\dates_handler;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * Like {dates}, but without bullet points: one line per day (separated by <br>),
 * several dates on the same day are combined into a single line, e.g.
 * "20 August 2026, 10:00-12:00, 13:00-15:00 and 17:00-18:00".
 *
 * @package mod_booking
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class datescompact extends \mod_booking\placeholders\placeholder_base {
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

        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        if (!empty($userid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            if (empty($cmid)) {
                $cmid = $settings->cmid;
            }

            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $cachekey = "$classname-$optionid-$userid";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                return placeholders_info::$placeholders[$cachekey];
            }

            if ($settings->is_selflearningcourse()) {
                // Self-learning courses have no dates and no official start or end.
                $value = '';
            } else {
                $sessions = dates_handler::return_dates_with_strings($settings);
                $value = self::render_compact_dates($sessions);
            }

            // Save the value to profit from singleton.
            placeholders_info::$placeholders[$cachekey] = $value;
        } else {
            $value = get_string('sthwentwrongwithplaceholder', 'mod_booking', $classname);
        }

        return $value;
    }

    /**
     * Combine the given sessions into one line per day, days separated by <br>.
     *
     * Sessions on the same day are combined into a single line: the date is shown
     * once, followed by the time ranges - separated by commas, the last one joined
     * with a localized "and". Sessions spanning more than one day keep the full
     * date string known from the {dates} placeholder.
     *
     * @param array $sessions array of date objects as returned by dates_handler::return_dates_with_strings
     * @return string
     */
    public static function render_compact_dates(array $sessions): string {

        // Ordered list of lines, same-day sessions share one line (indexed by their date string).
        $lines = [];
        $dayindexes = [];

        foreach ($sessions as $session) {
            if (empty($session->endtime) || $session->startdate !== $session->enddate) {
                // Sessions spanning more than one day are not combined.
                $lines[] = (object)['raw' => $session->datestring];
                continue;
            }

            $timerange = $session->starttime === $session->endtime
                ? $session->starttime
                : $session->starttime . '-' . $session->endtime;

            $day = $session->startdate;
            if (isset($dayindexes[$day])) {
                $lines[$dayindexes[$day]]->times[] = $timerange;
            } else {
                $dayindexes[$day] = count($lines);
                $lines[] = (object)['date' => $day, 'times' => [$timerange]];
            }
        }

        $and = get_string('and', 'mod_booking');

        $renderedlines = [];
        foreach ($lines as $line) {
            if (isset($line->raw)) {
                $renderedlines[] = $line->raw;
                continue;
            }
            $times = $line->times;
            $lasttime = array_pop($times);
            $timestring = empty($times)
                ? $lasttime
                : implode(', ', $times) . ' ' . $and . ' ' . $lasttime;
            $renderedlines[] = $line->date . ', ' . $timestring;
        }

        return implode('<br>', $renderedlines);
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
     * This placeholder is supported in the sign-in sheet HTML template.
     *
     * @return bool
     *
     */
    public static function for_signinsheet(): bool {
        return true;
    }
}
