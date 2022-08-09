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
 * Base class for conditional availability information (for module or section).
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_exception;
use MoodleQuickForm;

/**
 * class for conditional availability information of a booking option
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bo_info {

    /** @var bool Visibility flag (eye icon) */
    protected $visible;

    /** @var string Availability data as JSON string */
    protected $availability;

    /** @var int Optionid for a given option */
    protected $optionid;

    /** @var int userid for a given user */
    protected $userid;

    /**
     * Constructs with item details.
     *
     */
    public function __construct(booking_option_settings $settings) {

        global $USER;

        $this->optionid = $settings->id;
        $this->userid = $USER->id;

    }

    /**
     * Determines whether this particular item is currently available
     * according to the availability criteria.
     *
     * - This does not include the 'visible' setting (i.e. this might return
     *   true even if visible is false); visible is handled independently.
     * - This does not take account of the viewhiddenactivities capability.
     *   That should apply later.
     *
     * Depending on options selected, a description of the restrictions which
     * mean the student can't view it (in HTML format) may be stored in
     * $information. If there is nothing in $information and this function
     * returns false, then the activity should not be displayed at all.
     *
     * This function displays debugging() messages if the availability
     * information is invalid.
     *
     * @param int optionid
     * @param int $userid If set, specifies a different user ID to check availability for
     * @return array [isavailable, description]
     */
    public function is_available(int $optionid = null, int $userid = 0):array {

        global $USER, $CFG;

        if (!$optionid) {
            $optionid = $this->optionid;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $conditions = self::get_conditions();

        if (!empty($settings->availability)) {

            $availabilityobject = json_decode($settings->availability);

            // If the json is not valid, we throw an error.
            if (!$availabilityobject
                || empty($availabilityobject->conditions)) {
                throw new moodle_exception('availabilityjsonerror', 'mod_booking');
            }

            $conditions = array_merge($conditions, $availabilityobject->conditions);
        }

        // Resolve optional parameters.
        if (!$userid) {
            $userid = $USER->id;
        }

        $resultsarray = [];

        // Run through all the individual conditions to make sure they are fullfilled.
        foreach ($conditions as $condition) {

            // First, we have the hardcoded conditions already as instances.
            if ($condition->id < 0) {
                list($isavailable, $description) = $condition->get_description(true, $settings, $userid);
                $resultsarray[$condition->id] = ['id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description];
            } else {
                // Else we need to instantiate the condition first.

                $classname = $condition->name;

                if (class_exists($classname)) {
                    $instance = new $classname();
                    // We now set the id from the json for this instance.
                    // We might actually use a hardcoded condition with a negative id...
                    // ... also as customized condition with positive id.
                    $instance->id = $condition->id;
                } else {
                    // Should never happen, but just go on in case of.
                    continue;
                }
                // Then pass the availability-parameters.
                list($isavailable, $description) = $instance->get_description(true, $settings, $userid);
                $resultsarray[$condition->id] = ['id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description];

                // Now we might need to override the result of a previous condition which has been resolved as false before.
            }

            // We check each condition for it's availability.
            // phpcs:ignore moodle.Commenting.InlineComment.InvalidEndChar
            // Echo $condition;
        }

        $results = array_filter($resultsarray, function ($item) {
            if ($item['isavailable'] == false) {
                return true;
            }
            return false;
        });

        if (count($results) === 0) {
            $isavailable = true;
            $description = '';
        } else {
            $id = 0;
            $isavailable = false;
            foreach ($results as $result) {
                // If no Id has been defined or if id is higher, we take the descpription to return.
                if ($id === 0 || $result['id'] > $id) {
                    $description = $result['description'];
                    $id = $result['id'];
                }
            }
        }

        return [$id, $isavailable, $description];

    }

    /**
     * Obtains a string describing all availability restrictions (even if
     * they do not apply any more). Used to display information for staff
     * editing the website.
     *
     * The modinfo parameter must be specified when it is called from inside
     * get_fast_modinfo, to avoid infinite recursion.
     *
     * This function displays debugging() messages if the availability
     * information is invalid.
     *
     * @param \course_modinfo $modinfo Usually leave as null for default
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_full_information(\course_modinfo $modinfo = null) {
        // Do nothing if there are no availability restrictions.
        if (is_null($this->availability)) {
            return '';
        }
    }

    /**
     * Obtains an array with the id, the availability and the description of the actually blocking condition.
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description($full = false, booking_option_settings $settings, $userid = null):array {

        return $this->is_available($settings->id, $userid, false);
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform) {

        global $DB;

        $mform->addElement('header', 'bookingoptionprice',
                get_string('bookingoptionprice', 'booking'));

        $conditions = self::get_conditions(null, true);

        foreach ($conditions as $condition) {

            $condition->add_condition_to_mform($mform);

        }
    }

    /**
     * Returns all installed hardcoded conditions. Only includes those that need customization if $all is true.
     * If $onlycustomisable is true, we only return those which can be customized.
     *
     * @param boolean $all
     * @return array
     */
    public static function get_conditions($all = false, $onlycustomizable = false):array {

        global $CFG;

        $conditions = [];
        // First, we get all the available conditions from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/bo_availability/conditions/*.php';
        $filelist = glob($path);

        $conditions = [];

        // We just want to filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\bo_availability\conditions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();

                if ($onlycustomizable && $instance->iscustomizable) {
                    $conditions[] = $instance;
                } else if ($all || !empty($instance->id)) {
                    $conditions[] = $instance;
                }
            }
        }

        return $conditions;
    }
}
