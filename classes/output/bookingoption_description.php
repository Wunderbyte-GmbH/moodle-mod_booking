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

use renderer_base;
use renderable;
use templatable;

const BOOKINGLINKPARAM_NONE = 0;
const BOOKINGLINKPARAM_BOOK = 1;
const BOOKINGLINKPARAM_USER = 2;
const BOOKINGLINKPARAM_ICAL = 3;


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

    /** @var string $description depending on booking status */
    public $description = null;

    /** @var string $location as saved in db */
    public $location = null;

    /** @var string $addresse as saved in db */
    public $addresse = null;

    /** @var string $institution as saved in db */
    public $institution = null;

    /** @var string $duration as saved in db in minutes */
    public $duration = null;

    /** @var array $dates as saved in db in minutes */
    public $dates = [];


    /**
     * In the constructur we prepare the following
     * Constructor
     *
     * @param \stdClass $data
     */
    public function __construct($booking, $bookingoption, $bookingevent = null, $bookinglinkparam = BOOKINGLINKPARAM_NONE) {

        global $DB, $CFG;
        $fulldescription = '';

        // These fields can be gathered directly from DB.
        $this->title = $bookingoption->text;
        $this->location = $bookingoption->location;
        $this->addresse = $bookingoption->address;
        $this->institution = $bookingoption->institution;
        $this->duration = $bookingoption->duration;

        // For these fields we do need some conversion.
        // For Description we need to know the booking status

        $this->description = "";

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.
        $this->dates = [];













        // Create the description for a booking option date (session) event.
        if ($optiondate) {
            $timestart = userdate($optiondate->coursestarttime, get_string('strftimedatetime'));
            $timefinish = userdate($optiondate->courseendtime, get_string('strftimedatetime'));
            $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

            $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

            // Add rendered custom fields.
            $customfieldshtml = get_rendered_customfields($optiondate->id);
            if (!empty($customfieldshtml)) {
                $fulldescription .= $customfieldshtml;
            }
        } else {
            // Create the description for a booking option event without sessions.
            $timestart = userdate($option->coursestarttime, get_string('strftimedatetime'));
            $timefinish = userdate($option->courseendtime, get_string('strftimedatetime'));
            $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

            $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

            $customfields = $DB->get_records('booking_customfields', array('optionid' => $option->id));
            $customfieldcfg = \mod_booking\booking_option::get_customfield_settings();

            if ($customfields && !empty($customfieldcfg)) {
                foreach ($customfields as $field) {
                    if (!empty($field->value)) {
                        $cfgvalue = $customfieldcfg[$field->cfgname]['value'];
                        if ($customfieldcfg[$field->cfgname]['type'] == 'multiselect') {
                            $tmpdata = implode(", ", explode("\n", $field->value));
                            $fulldescription .= "<p> <b>$cfgvalue: </b>$tmpdata</p>";
                        } else {
                            $fulldescription .= "<p> <b>$cfgvalue: </b>$field->value</p>";
                        }
                    }
                }
            }
        }

        // Add location, institution and address.
        if (strlen($option->location) > 0) {
            $fulldescription .= '<p><i>' . get_string('location', 'booking') . '</i>: ' . $option->location . '</p>';
        }
        if (strlen($option->institution) > 0) {
            $fulldescription .= '<p><i>' . get_string('institution', 'booking') . '</i>: ' . $option->institution. '</p>';
        }
        if (strlen($option->address) > 0) {
            $fulldescription .= '<p><i>' . get_string('address', 'booking') . '</i>: ' . $option->address. '</p>';
        }

        // Attach the correct link.
        $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
        switch ($bookinglinkparam) {
            case BOOKINGLINKPARAM_BOOK:
                $fulldescription .= "<p>" . get_string("bookingoptioncalendarentry", 'booking', $linkurl) . "</p>";
                break;
            case BOOKINGLINKPARAM_USER:
                $fulldescription .= "<p>" . get_string("usercalendarentry", 'booking', $linkurl) . "</p>";
                break;
            case BOOKINGLINKPARAM_ICAL:
                $fulldescription .= "<br><p>" . get_string("linkgotobookingoption", 'booking', $linkurl) . "</p>";
                // Convert to plain text for ICAL.
                $fulldescription = rtrim(strip_tags(preg_replace( "/<br>|<\/p>/", "\\n", $fulldescription)));
                break;
        }

        return $fulldescription;

    }

    public function export_for_template(renderer_base $output) {
        return array(
                'username' => $this->username,
                'userpictureurl' => $this->userpictureurl->out(),
                'userprofileurl' => $this->userprofileurl->out(),
                'sendmessageurl' => $this->sendmessageurl->out(),
        );
    }
}