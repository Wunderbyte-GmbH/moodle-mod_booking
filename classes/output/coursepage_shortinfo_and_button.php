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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\singleton_service;
use renderer_base;
use renderable;
use stdClass;
use templatable;


/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursepage_shortinfo_and_button implements renderable, templatable {
    /**
     * @var stdClass Booking instance.
     */
    public $booking;

    /**
     * @var numeric Course module id.
     */
    public $cmid;

    /**
     * @var string coursename
     */
    public $coursename;

    /**
     * @var string eventtype
     */
    public $eventtype;

    /**
     * @var string URL pointing to available booking options.
     */
    public $buttonurl;

    /**
     * @var string Short info to show on course page.
     */
    public $shortinfo;

    /**
     * Constructor to prepare the data for courspage booking options list
     *
     * @param object $cm
     */
    public function __construct($cm) {
        global $COURSE, $CFG;

        $this->cmid = $cm->id;
        $this->booking = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);

        $this->coursename = $COURSE->fullname;
        $this->eventtype = $this->booking->settings->eventtype;
        $this->buttonurl = $CFG->wwwroot . '/mod/booking/view.php?id=' . $cm->id . '&whichview=showall';
        $this->shortinfo = $this->booking->settings->coursepageshortinfo;
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {
        return [
                'coursename' => $this->coursename,
                'eventtype' => $this->eventtype,
                'buttonurl' => $this->buttonurl,
                'shortinfo' => $this->shortinfo,
        ];
    }
}
