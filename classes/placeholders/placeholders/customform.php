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
use mod_booking\singleton_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customform {

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
     * @param string $rulejson
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
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        string $rulejson = ''
    ) {
        $rulejson = json_decode($rulejson);
        if (
            !empty($rulejson)
            && !empty($rulejson->datafromevent)
        ) {

            // We might have more than one custom form value to return.
            $returnarray = [];

            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $formelements = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

            $class = $rulejson->datafromevent->eventname;
            $event = $class::restore((array)$rulejson->datafromevent, []);
            $eventdata = $event->get_data();

            if (isset($eventdata["other"]->json)) {
                $json = json_decode($eventdata["other"]->json);
            }

            if (isset($json->condition_customform)) {
                foreach ($json->condition_customform as $key => $value) {
                    // So, if we now know that in the form, a select value was transmitted...
                    // ... we understand that what is saved is actually the key. We want the value.
                    // Therefore, we have a look in the formelements.

                    if (strpos($key, 'customform_') !== false) {
                        $identifierarray = explode('_', $key);
                        // Now we get get the options of this custom form select..
                        if ($formelement = $formelements->{$identifierarray[2]} ?? false) {
                            // Make sure first we have a value.
                            $returnvalue = $value;

                            if ($identifierarray[1] == 'select') {
                                // Now we need to iterate over the "value", which holds the option of the select.
                                $lines = explode(PHP_EOL, $formelement->value);
                                foreach ($lines as $line) {
                                    $linearray = explode(' => ', $line);
                                    // Here we have a look if our value matches the key.
                                    if (count($linearray) < 2) {
                                        // We only have values, no key in our options.
                                        // Therefore, automatic keys are generated.
                                        if ($returnvalue = $lines[$value] ?? false) {
                                            break;
                                        }
                                    }

                                    // If there is a key value pair to choose from, we use the key to get the value.
                                    if ($linearray[0] == $value) {
                                        $returnvalue = $linearray[1];
                                        break;
                                    }

                                    $returnvalue = $value;
                                }
                            }
                            $returnarray[] = "$formelement->label: $returnvalue";
                        }
                    }
                }
            }
            $value = implode('<br>', $returnarray);
        } else {
            $value = '';
        }

        return format_text($value);
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
}
