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

use coding_exception;
use core_component;
use html_writer;
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

    /**
     * @var array $placeholders
     */
    public static array $placeholders = [];

    /**
     * @var array $localizedplaceholders
     */
    public static array $localizedplaceholders = [];

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param string $text
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $descriptionparam
     * @return string
     */
    public static function render_text(
        string $text,
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE) {

        global $USER;

        // First, identify all the placeholders.
        preg_match_all('/{(.*?)}/', $text, $matches);
        $placeholders = $matches[1];

        if (empty($userid)) {
            $userid = $USER->id;
        }

        // If there are placeholders at all...
        // ... they will be localized. We need to replace them.
        if (!empty($placeholders)) {

            self::return_list_of_placeholders();
        }

        foreach ($placeholders as $placeholder) {

            // We might need more complexe placeholder for iteration...
            // ... (like {{# sessiondates}} or {{teacher 1}}). Therefore...
            // ... we need to explode the placeholders here.

            // We don't want any numbers, because we need classnames.
            $identifier = preg_replace('/\d/', '', $placeholder);

            // Now we "unlocalize" the classname.
            $classname = self::$localizedplaceholders[$identifier] ?? $identifier;

            // Now we can execute it.
            $class = 'mod_booking\placeholders\placeholders\\' . $classname;
            if (class_exists($class)) {
                $value = $class::return_value(
                    $cmid,
                    $optionid,
                    $userid,
                    $text, // Text can be changed in this function, if we need to replace sth.
                    $placeholders, // Placeholders can be changed in this function, if we need to replace sth.
                    $descriptionparam);

                // In some cases, we might receive an array instead of string.
                if (is_array($value)) {

                    // First we check if we had a number in our original placeholder.
                    $number = str_replace($identifier, '', $placeholder);

                    if (is_numeric($number) && $number > 0) {
                        $number--;
                        $value = $value[$number] ?? '';
                    } else {
                        $value = reset($value);
                    }
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
            }

            if (!empty($value)) {
                $searchstring = '{' . $placeholder . '}';
                $text = str_replace($searchstring, $value, $text);
            }
        }

        return format_text($text);
    }

    /**
     * This builds an returns a list of localized placeholders.
     * They are stored statically and thus available throughout the ttl.
     * @return array
     * @throws coding_exception
     */
    public static function return_list_of_placeholders() {

        // If it's already build, we can skip this.
        if (empty(self::$localizedplaceholders)) {
            self::create_list_of_localized_placeholders();
        }

        $placeholders = [];
        foreach (self::$localizedplaceholders as $key => $value) {
            $placeholders[] = "<li data-id='$value'>{" . $key . "}</li>";
        }

        $returnstring = implode('<br>', $placeholders);

        $returnstring = html_writer::tag('ul', $returnstring, ['class' => 'booking-placeholders']);

        return $returnstring;
    }

    /**
     * Create list of localized placeholders.
     * @return array|void
     * @throws coding_exception
     */
    private static function create_list_of_localized_placeholders() {

        // If it's already build, we can skip this.
        if (!empty(self::$localizedplaceholders)) {
            return self::$localizedplaceholders;
        }

        $placeholders =
            core_component::get_component_classes_in_namespace(
                'mod_booking',
                'placeholders\placeholders'
            );

        $specialtreatmentclasses = [
            'customfields' => customfields::return_placeholder_text(),
        ];

        foreach ($placeholders as $key => $value) {
            $class = substr(strrchr($key, '\\'), 1);

            if (isset($specialtreatmentclasses[$class])) {
                continue;
            }

            // We use the localized strings as keys and the classnames as values.
            self::$localizedplaceholders[get_string($class, 'mod_booking')] = $class;
        }
    }
}
