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
use core_plugin_manager;
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
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param int $descriptionparam
     * @param ?string $rulejson
     * @return string
     */
    public static function render_text(
        string $text,
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        ?string $rulejson = null
    ) {

        global $USER;

        // First, identify all the placeholders.
        preg_match_all('/{(.*?)}/', $text, $matches);
        $placeholders = $matches[1];

        if (empty($userid)) {
            $userid = $USER->id;
        }

        if (!empty($placeholders)) {
            self::return_list_of_placeholders();
        }
        $noreturn = [];
        $return = [];

        $namespaces[] = 'mod_booking\placeholders\placeholders\\';
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
                $namespaces[] = "bookingextension_{$plugin->name}\\placeholders\\";
        }

        foreach ($placeholders as $placeholder) {
            // We might need more complex placeholder for iteration...
            // ... (like {{# sessiondates}} or {{teacher 1}}). Therefore...
            // ... we need to explode the placeholders here.

            // We don't want any numbers, because we need classnames.
            $identifier = preg_replace('/\d/', '', $placeholder);

            // Keep the original identifier for later.
            $classname = $identifier;

            // Now we can execute it.
            $fieldexists = true;

            foreach ($namespaces as $namespace) {
                $class = $namespace . $classname;
                if (class_exists($class)) {
                    break;
                }
            }
            if (class_exists($class)) {
                $value = $class::return_value(
                    $cmid,
                    $optionid,
                    $userid,
                    $installmentnr,
                    $duedate,
                    $price,
                    $text, // Text can be changed in this function, if we need to replace sth.
                    $placeholders, // Placeholders can be changed in this function, if we need to replace sth.
                    $descriptionparam,
                    $rulejson
                );
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
                    $placeholder,
                    $fieldexists
                );
            }

            if (!empty($value)) {
                $searchstring = '{' . $placeholder . '}';
                $text = str_replace($searchstring, $value, $text);
                // Look for enclosing placeholder. Delete them.
                $return[] = $placeholder;
            } else {
                $firstchar = mb_substr($placeholder, 0, 1);
                if ($firstchar == "#" || $firstchar == "/") {
                    continue;
                }
                if ($fieldexists) {
                    $noreturn[] = $placeholder;
                }
            }
        }

        foreach ($placeholders as $index => $placeholder) {
            $firstchar = mb_substr($placeholder, 0, 1);
            $nameafterfirstchar = substr($placeholder, 1);
            $emptyph = in_array($nameafterfirstchar, $noreturn); // Without first char.

            if (($firstchar == "#") && $emptyph) {
                // Case 1: Placeholder is found and it's empty.
                foreach ($placeholders as $index => $ph) {
                    // Check if we find the end of the section.
                    $name = substr($ph, 1);
                    $first = mb_substr($ph, 0, 1);

                    if ($nameafterfirstchar == $name && $first == "/") {
                        $end = $matches[0][$index];
                        break;
                    } else {
                        $end = "";
                    }
                }
                // Delete everything beetween enclosing placeholder.
                if (!empty($end)) {
                    $pattern = '/' . preg_quote('{' . $placeholder . '}', '/') . '.*?' . preg_quote($end, '/') . '/s';
                } else {
                    $pattern = '/\$\{placeholder\}/';
                }
                $text = preg_replace($pattern, '', $text);
            } else if (
                ($firstchar == "#" || $firstchar == "/")
                && in_array($nameafterfirstchar, $return)
            ) {
                // Case 2: Placeholder is not empty, remove the enclosing placeholders.
                $text = str_replace('{' . $placeholder . '}', '', $text);
            }
        }
        return $text;
    }

    /**
     * This builds an returns a list of localized placeholders.
     * They are stored statically and thus available throughout the ttl.
     * @return string
     * @throws coding_exception
     */
    public static function return_list_of_placeholders(): string {

        // If it's already build, we can skip this.
        if (empty(self::$localizedplaceholders)) {
            self::create_list_of_localized_placeholders();
        }

        $placeholders = [];
        foreach (self::$localizedplaceholders as $key => $value) {
            $placeholders[] = "<li data-id='$value'>{" . $value . "} " . $key . "</li>";
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
        $extensionplaceholder = [];
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $extensionplaceholder = core_component::get_component_classes_in_namespace(
                "bookingextension_{$plugin->name}",
                'placeholders'
            );
             $placeholders = array_merge($placeholders, $extensionplaceholder);
        }
        $specialtreatmentclasses = [
            'customfields' => customfields::return_placeholder_text(),
        ];
        foreach ($placeholders as $key => $value) {
            if (!$key::is_applicable()) {
                continue;
            }
            $component = core_component::get_component_from_classname($key);
            $class = substr(strrchr($key, '\\'), 1);
            if (isset($specialtreatmentclasses[$class])) {
                self::$localizedplaceholders[$specialtreatmentclasses[$class]] = $class;
                continue;
            }
            // We use the localized strings as keys and the classnames as values.
            self::$localizedplaceholders[get_string($class, $component)] = $class;
        }
        return self::$localizedplaceholders;
    }
}
