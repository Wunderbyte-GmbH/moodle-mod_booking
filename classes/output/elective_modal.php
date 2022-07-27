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
 * @copyright 2017 David Bogner {@link http://www.edulabs.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;
use renderable;
use templatable;

use mod_booking\booking_elective;


/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class elective_modal implements renderable, templatable {

    /** @var string $confirmbutton */
    public $confirmbutton;

    /** @var string $modalbuttonclass */
    public $modalbuttonclass;

    /** @var array $arrayofoptions */
    public $arrayofoptions = [];

    /**
     * Constructor
     *
     * @param string $sort sort by course/user/my
     * @param \stdClass $data
     */
    public function __construct($booking, $rawdata, $listorder = '[]') {

        global $USER;

        // If there are no list items, we return null.
        if (!$rawdata) {
            return;
        }

        // First, we retrieve the saved order of the booking options.

        if (!$arrayofoptions = json_decode($listorder)) {
            $arrayofoptions = [];
            $listorder = '[]';
        }

        foreach ($arrayofoptions as $item) {
            $this->arrayofoptions[] = $rawdata[$item];
        }

        $urloptions = array('id' => $booking->cm->id, 'action' => 'multibooking', 'sesskey' => $USER->sesskey, 'list' => $listorder);
        $moodleurl = new \moodle_url('view.php', $urloptions);
        $moodleurl = $moodleurl->out(false);

        // If all credits have to be consumed at once, only enable the "book all selected" button...
        // ... when no more credits are left.
        if ($booking->settings->consumeatonce == 1 && booking_elective::return_credits_left($booking) !== 0) {
            $selectbtnoptions['class'] = 'btn btn-primary disabled';
        } else if (count($arrayofoptions) == 0) {
            // Also, disable the button when there is nothing selected.
            $selectbtnoptions['class'] = 'btn btn-primary disabled';
        } else {
            $selectbtnoptions['class'] = 'btn btn-primary';
        }

        $this->modalbuttonclass = $selectbtnoptions['class'];
        $selectbtnoptions['id'] = "confirmbutton";
        $this->confirmbutton = \html_writer::link($moodleurl, get_string('bookelectivesbtn', 'booking'), $selectbtnoptions);
    }

    public function export_for_template(renderer_base $output) {
        return [
                'modalbuttonclass' => $this->modalbuttonclass,
                'confirmbutton' => $this->confirmbutton,
                'arrayofoptions' => $this->arrayofoptions
        ];
    }
}
