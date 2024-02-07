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

namespace mod_booking\placeholders;

use mod_booking\placeholders\placeholders\customfields;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholders_info {

    public static array $placeholders = [];

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param string $text
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @return string
     */
    public static function render_text(
        string $text,
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0) {

        global $USER, $CFG;

        // First, identify all the placeholders.
        preg_match_all('/{(.*?)}/', $text, $matches);
        $placeholders = $matches[1];

        if (empty($userid)) {
            $userid = $USER->id;
        }

        foreach ($placeholders as $placeholder) {

            // We might need more complexe placeholder for iteration...
            // ... (like {{# sessiondates}} or {{teacher 1}}). Therefore...
            // ... we need to explode the placeholders here.

            $parts = explode (' ', $placeholder);

            if (count($parts) === 1) {
                $class = 'mod_booking\placeholders\placeholders\\' . $placeholder;
                if (class_exists($class)) {
                    $value = $class::return_value(
                        $cmid,
                        $optionid,
                        $userid,
                        $text,
                        $placeholders);

                    // In some cases, we might reseve an array instead of string.
                    if (is_array($value)) {
                        $value = reset($value);
                    }

                    $searchstring = '{' . $placeholder . '}';
                    $text = str_replace($searchstring, $value, $text);
                } else if (!empty($optionid)) {
                    // The customfields class takes care of booking custom fields...
                    // ... and custom user profile fields.
                    $value = customfields::return_value(
                        $cmid,
                        $optionid,
                        $userid,
                        $text,
                        $placeholders,
                        $placeholder);

                    if (!empty($value)) {
                        $searchstring = '{' . $placeholder . '}';
                        $text = str_replace($searchstring, $value, $text);
                    }
                }
            } else if (count($parts) === 2) {
                $class = 'mod_booking\placeholders\placeholders\\' . $parts[0];
                if (class_exists($class) && is_numeric($parts[1])) {
                    $values = $class::return_value(
                        $cmid,
                        $optionid,
                        $userid,
                        $text,
                        $placeholders);

                    $counter = $parts[1];
                    $counter--;
                    $value = $values[$counter] ?? '';

                    $searchstring = '{' . $placeholder . '}';
                    $text = str_replace($searchstring, $value, $text);
                }
            }
        }

        return $text;
    }
}
