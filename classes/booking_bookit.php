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

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\output\prepagemodal;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for handling the booking process.
 * In the most simple case, this class provides a button for a user to book a booking option.
 * But this class handles the process, together with bo_conditions, prices and further functionalities...
 * ... as an integrative process.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_bookit {

    /** @var booking_option_settings $settings */
    public $settings = null;

    /**
     * Renders the book it button for a given user and returns the rendered html as string.
     *
     * @param int $userid
     * @param booking_option_settings $settings
     * @return string
     */
    public static function render_bookit_button(int $userid = 0, booking_option_settings $settings) {

        global $PAGE;

        // Get blocking conditions, including sites etc.
        $results = bo_info::get_condition_results($settings->id, $userid);
        // Decide, wether to show the direct booking button or a modal.

        $sites = [];
        $showinmodalbutton = true;
        foreach ($results as $result) {
            if ($result['insertpage']) {
                $sites[] = [
                    'id' => $result['id'],
                    'classname' => $result['classname']
                ];
            }
            // The button can come from another blocking condition.
            if ($result['button'] == BO_BUTTON_MYBUTTON) {
                $buttoncondition = $result['classname'];
            }

            // The no button marker can override all the other conditions.
            // It is only relevant for the modal, not the rest.
            if ($result['button'] == BO_BUTTON_NOBUTTON) {
                $showinmodalbutton = false;
            }
        }

        // Big decession: can we render the button right away, or do we need to introduce a modal?
        if (count($sites) > 0) {

            // We render the button only from the highest relevant blocking condition.
            $output = $PAGE->get_renderer('mod_booking');
            $data = new prepagemodal(
                $settings->id, // We pass on the optionid.
                count($sites), // The total number of pre booking pages.
                $buttoncondition,  // This is the button we need to render twice.;
                $showinmodalbutton, // This marker just suppresses the in modal button.
            );

            $modalhtml = $output->render_prepagemodal($data);
            return $modalhtml;
        } else {

            // We can only call the renderer here.
            // Else, we would risk to render more than one
            $buttonhtml = $buttoncondition::render_button($settings->id, null, false);
            return $buttonhtml;
        }
    }

}
