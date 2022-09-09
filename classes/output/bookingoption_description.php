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

defined('MOODLE_INTERNAL') || die();

use mod_booking\booking_option;
use mod_booking\booking_utils;
use renderer_base;
use renderable;
use templatable;

const DESCRIPTION_WEBSITE = 1;
const DESCRIPTION_CALENDAR = 2;
const DESCRIPTION_ICAL = 3;
const DESCRIPTION_MAIL = 4;

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
     * @var null Bookingutilities to instantiate only once
     */
    private $bu = null;


    /**
     * Constructor.
     * @param $booking
     * @param $bookingoption
     * @param null $bookingevent
     * @param int $descriptionparam
     * @param bool $withcustomfields
     */
    public function __construct($booking,
            $option,
            $bookingevent = null,
            $descriptionparam = DESCRIPTION_WEBSITE, // Default.
            $withcustomfields = true,
            $forbookeduser = null) {

        global $CFG;

        $this->bu = new booking_utils();
        $bookingoption = new booking_option($booking->cm->id, $option->id);

        // We need the possibility to render for other users, so the iambookedflag is not enough.
        // But we use it if nothing else is specified.
        if ($forbookeduser === null) {
            $forbookeduser = $bookingoption->iambooked == 1 ? true : false;
        }

        // These fields can be gathered directly from DB.
        $this->title = $bookingoption->option->text;
        $this->location = $bookingoption->option->location;
        $this->address = $bookingoption->option->address;
        $this->institution = $bookingoption->option->institution;

        // There can be more than one modal, therefor we use the id of this record.
        $this->modalcounter = $bookingoption->option->id;

        $this->description = format_text($bookingoption->option->description, FORMAT_HTML);

        // For these fields we do need some conversion.
        // For description we need to know the booking status.
        $this->statusdescription = $bookingoption->get_option_text();

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        $this->dates = $this->bu->return_array_of_sessions($bookingoption, $bookingevent, $descriptionparam,
            $withcustomfields, $forbookeduser);

        $teachers = $bookingoption->get_teachers();
        $teachernames = [];
        foreach ($teachers as $teacher) {
            $teachernames[] = "$teacher->firstname $teacher->lastname";
        }
        $this->teachers = $teachernames;

        $baseurl = $CFG->wwwroot;
        $moodleurl = new \moodle_url($baseurl . '/mod/booking/view.php', array(
            'id' => $booking->cm->id,
            'optionid' => $bookingoption->optionid,
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
                    } else if ($bookingoption->onwaitinglist == 1) {
                        // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                        $this->booknowbutton = get_string('infowaitinglist', 'booking');
                    }
                } else {
                    // Inline we don't want to show it because it would be redundant information.
                    $this->booknowbutton = '';
                }
                break;
            case DESCRIPTION_CALENDAR:
                $encodedlink = booking_utils::booking_encode_moodle_url($moodleurl);
                $this->booknowbutton = "<a href=$encodedlink class='btn btn-primary'>"
                        . get_string('gotobookingoption', 'booking')
                        . "</a>";
                // TODO: We would need an event tracking status changes between notbooked, iambooked and onwaitinglist...
                // TODO: ...in order to update the event table accordingly.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /*if ($bookingoption->onwaitinglist == 1) {
                    // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                    $this->booknowbutton .= '<br><p>' . get_string('infowaitinglist', 'booking') . '</p>';
                }*/
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

        // In events don't have the possibility, as on the website, to use display: none the same way.
        // So we need two helpervariables...
        if (count($this->dates) > 0) {
            $returnarray['showdateslabel'] = 1;
        }
        if (count($this->teachers) > 0) {
            $returnarray['showteachersslabel'] = 1;
        }

        return $returnarray;
    }
}
