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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking option instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_description implements renderable, templatable {

    /** @var string $title the title (column text) as it is saved in db */
    public $title = null;

    /** @var int $modalcounter the title (column text) as it is saved in db */
    public $modalcounter = null;

    /** @var string $description from DB */
    public $description = null;

    /** @var string $statusdescription depending on booking status */
    public $statusdescription = null;

    /** @var string $location as saved in db */
    public $location = null;

    /** @var string $address as saved in db */
    public $address = null;

    /** @var string $institution as saved in db */
    public $institution = null;

    /** @var string $duration as saved in db in minutes */
    public $duration = null;

    /** @var string $booknowbutton as saved in db in minutes */
    public $booknowbutton = null;

    /** @var array $dates as saved in db in minutes */
    public $dates = [];

    /** @var array $teachers by names */
    public $teachers = [];


    /**
     * Constructor.
     * @param $booking
     * @param int $optionid
     * @param null $bookingevent
     * @param int $descriptionparam
     * @param bool $withcustomfields
     */
    public function __construct($booking,
            int $optionid,
            $bookingevent = null,
            int $descriptionparam = DESCRIPTION_WEBSITE, // Default.
            bool $withcustomfields = true,
            bool $forbookeduser = null) {

        global $CFG;

        $this->cmid = $booking->cm->id;

        // TODO: Cache booking options, so they don't get instantiated twice.
        // Performance: Last param is set to true so users won't be retrieved from DB.
        // $bookingoption = new booking_option($booking->cm->id, $optionid, [], 0, 0, true);

        // Booking answers class uses caching.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

        /* We need the possibility to render for other users,
        so the user status of the current USER is not enough.
        But we use it if nothing else is specified. */
        if ($forbookeduser === null) {
            if ($bookinganswers->user_status() == STATUSPARAM_BOOKED) {
                $forbookeduser = true;
            } else {
                $forbookeduser = false;
            }
        }

        // These fields can be gathered directly from DB.
        $this->title = $settings->text;
        $this->location = $settings->location;
        $this->address = $settings->address;
        $this->institution = $settings->institution;

        // There can be more than one modal, therefor we use the id of this record.
        $this->modalcounter = $settings->id;

        // Duration from booking option settings.
        $this->duration = $settings->duration;

        // Description from booking option settings formatted as HTML.
        $this->description = format_text($settings->description, FORMAT_HTML);

        // Todo: Reintegrate!!
        $bookingoption = singleton_service::get_instance_of_booking_option($this->cmid, $optionid);
        // End.

        // For these fields we do need some conversion.
        // For Description we need to know the booking status.
        // Todo: Reintegrate!!
        $this->statusdescription = $bookingoption->get_option_text($bookinganswers);
        // END.

        return null;

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        // Todo: Reintegrate!!
        //$this->dates = $bookingoption->return_array_of_sessions($bookingevent,
        //    $descriptionparam, $withcustomfields, $forbookeduser);
        $this->dates = [];

        // End.

        $teachers = $settings->teachers;

        $teachernames = [];
        foreach ($teachers as $teacher) {
            $teachernames[] = "$teacher->firstname $teacher->lastname";
        }
        $this->teachers = $teachernames;

        $baseurl = $CFG->wwwroot;
        $moodleurl = new \moodle_url($baseurl . '/mod/booking/view.php', array(
            'id' => $booking->cm->id,
            'optionid' => $settings->id,
            'action' => 'showonlyone',
            'whichview' => 'showonlyone'
        ));

        switch ($descriptionparam) {

            case DESCRIPTION_WEBSITE:
                // Only show "already booked" or "on waiting list" text in modal.
                if ($booking->settings->showdescriptionmode == 0) {
                    if ($forbookeduser) {
                        // If it is for booked user, we show a short info text that the option is already booked.
                        $this->booknowbutton = get_string('infoalreadybooked', 'booking');
                    } else if ($bookinganswers->user_status() == 1) {
                        // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                        // Currently this is only working for the current USER.
                        $this->booknowbutton = get_string('infowaitinglist', 'booking');
                    }
                } else {
                    // Inline we don't want to show it because it would be redundant information.
                    $this->booknowbutton = '';
                }
                break;

            case DESCRIPTION_CALENDAR:
                $encodedlink = booking::encode_moodle_url($moodleurl);
                $this->booknowbutton = "<a href=$encodedlink class='btn btn-primary'>"
                        . get_string('gotobookingoption', 'booking')
                        . "</a>";
                // TODO: We would need an event tracking status changes between notbooked, iambooked and onwaitinglist...
                // TODO: ...in order to update the event table accordingly.
                break;

            case DESCRIPTION_ICAL:
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': '
                    .  $moodleurl->out(false);
                break;

            case DESCRIPTION_MAIL:
                // The link should be clickable in mails (placeholder {bookingdetails}).
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': ' .
                    '<a href = "' . $moodleurl . '" target = "_blank">' .
                        $moodleurl->out(false) .
                    '</a>';
                break;
        }
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarray = array(
                'title' => $this->title,
                'modalcounter' => $this->modalcounter,
                'description' => $this->description,
                'statusdescription' => $this->statusdescription,
                'location' => $this->location,
                'address' => $this->address,
                'institution' => $this->institution,
                'duration' => $this->duration,
                'dates' => $this->dates,
                'booknowbutton' => $this->booknowbutton,
                'teachers' => $this->teachers
        );

        // In events we don't have the possibility, as on the website, to use display: none the same way.
        // So we need two helper variables.
        if (count($this->dates) > 0) {
            $returnarray['showdateslabel'] = 1;
        }
        if (count($this->teachers) > 0) {
            $returnarray['showteachersslabel'] = 1;
        }

        return $returnarray;
    }
}
