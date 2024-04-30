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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();
// Required to avoid errors after duplicatoion of bo_info's constants had been removed from bo_subinfo class.
require_once($CFG->dirroot . '/mod/booking/classes/bo_availability/bo_info.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface bo_subcondition {

    /**
     * True, if it's a customizable / JSON-compatible condition.
     * False, if it's a hardcoded condition (not stored with JSON).
     * @return bool
     */
    public function is_json_compatible(): bool;

    /**
     * True, if it should show up in mform, else false.
     * @return bool
     */
    public function is_shown_in_mform(): bool;

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     *
     * If implementations require a course or modinfo, they should use
     * the get methods in $info.
     *
     * The $not option is potentially confusing. This option always indicates
     * the 'real' value of NOT. For example, a condition inside a 'NOT AND'
     * group will get this called with $not = true, but if you put another
     * 'NOT OR' group inside the first group, then a condition inside that will
     * be called with $not = false. We need to use the real values, rather than
     * the more natural use of the current value at this point inside the tree,
     * so that the information displayed to users makes sense.
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $subbookingid
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $subbookingid, int $userid, bool $not);

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $subbookingid
     * @param int $userid userid of the user we want the description for.
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $subbookingid, $userid, $full, $not);


    /**
     * Adds the right form elements to add this condition.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @param int $subbookingid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid, $subbookingid);

    /**
     * Some conditions (like price & bookit) provide a button.
     * This function returns the html and the data to render the button.
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $subbookingid
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(booking_option_settings $settings,
        int $subbookingid, int $userid=0, bool $full=false, bool $not=false, bool $fullwidth=true): array;
}
