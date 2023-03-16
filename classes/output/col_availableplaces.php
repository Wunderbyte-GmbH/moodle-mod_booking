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
 * This file contains the definition for the renderable classes for column 'action'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_module;
use mod_booking\booking_answers;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'availableplaces'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_availableplaces implements renderable, templatable {

    /** @var booking_answers $bookinganswers instance of class */
    private $bookinganswers = null;

    /** @var stdClass $buyforuser user stdclass if we buy for user */
    private $buyforuser = null;

    /** @var bool $showmanageresponses */
    private $showmanageresponses = null;

    /** @var string $manageresponsesurl */
    private $manageresponsesurl = null;

    /** @var array $bookinginformation */
    private $bookinginformation = [];

    /** @var bool $showmaxanswers */
    public $showmaxanswers = true;

    /**
     * The constructor takes the values from db.
     * @param stdClass $values
     * @param booking_option_settings $settings
     */
    public function __construct($values, booking_option_settings $settings, $buyforuser = null) {
        global $CFG;

        $this->buyforuser = $buyforuser;
        $this->bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

        $cmid = $settings->cmid;
        $optionid = $settings->id;

        $context = context_module::instance($cmid);
        if (has_capability('mod/booking:updatebooking', $context) ||
             has_capability('mod/booking:addeditownoption', $context)) {
            $this->showmanageresponses = true;

            // Add a link to redirect to the booking option.
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', array(
                'id' => $cmid,
                'optionid' => $optionid
            ));
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $this->manageresponsesurl = html_entity_decode($link->out());
        }

        if ($this->buyforuser) {
            $userid = $this->buyforuser->id;
        } else {
            $userid = 0;
        }

        // We got the array of all the booking information.
        $fullbookinginformation = $this->bookinganswers->return_all_booking_information($userid);
        // We need to pop out the first value which is by itself another array containing the information we need.
        $bookinginformation = array_pop($fullbookinginformation);

        // We need this to render a link to manage bookings in the template.
        if (!empty($this->showmanageresponses) && $this->showmanageresponses == true) {
            if (is_array($bookinginformation)) {
                $bookinginformation['showmanageresponses'] = true;
                $bookinginformation['manageresponsesurl'] = $this->manageresponsesurl;
            }
        }

        // PRO feature: Info texts instead of the actual booking numbers.
        // Booking places.
        if (!has_capability('mod/booking:updatebooking', $context) &&
            get_config('booking', 'bookingplacesinfotexts')
            && !empty($bookinginformation['maxanswers'])) {

            $bookinginformation['showbookingplacesinfotext'] = true;

            $bookingplaceslowpercentage = get_config('booking', 'bookingplaceslowpercentage');
            $actualpercentage = ($bookinginformation['freeonlist'] / $bookinginformation['maxanswers']) * 100;

            if ($bookinginformation['freeonlist'] == 0) {
                // No places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplacesfullmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-danger';
            } else if ($actualpercentage <= $bookingplaceslowpercentage) {
                // Only a few places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplaceslowmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-danger';
            } else {
                // Still enough places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplacesenoughmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-success';
            }
        }
        // Waiting list places.
        if (!has_capability('mod/booking:updatebooking', $context) &&
            get_config('booking', 'waitinglistinfotexts')
            && !empty($bookinginformation['maxoverbooking'])) {

            $bookinginformation['showwaitinglistplacesinfotext'] = true;

            $waitinglistlowpercentage = get_config('booking', 'waitinglistlowpercentage');
            $actualwlpercentage = ($bookinginformation['freeonwaitinglist'] /
                $bookinginformation['maxoverbooking']) * 100;

            if ($bookinginformation['freeonwaitinglist'] == 0) {
                // No places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistfullmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-danger';
            } else if ($actualwlpercentage <= $waitinglistlowpercentage) {
                // Only a few places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistlowmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-danger';
            } else {
                // Still enough places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistenoughmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-success';
            }
        }

        $this->bookinginformation = $bookinginformation;
    }

    /**
     * Get booking information.
     * @return array
     */
    public function get_bookinginformation() {
        return $this->bookinginformation;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->bookinginformation;
    }
}
