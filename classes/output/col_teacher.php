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
 * This file contains the definition for the renderable classes for column 'teacher'.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'teacher'.
 *
 * @package     mod_booking
 * @copyright   2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_teacher implements renderable, templatable {
    /** @var array $teachers array of teachers */
    public $teachers = [];

    /**
     * Constructor
     *
     * @param int $optionid
     * @param booking_option_settings $settings
     * @param bool $loadprofileimage true if a profile image for the teacher should be shown, false by default
     */
    public function __construct(int $optionid, booking_option_settings $settings, bool $loadprofileimage = false) {
        global $PAGE;

        $addlink = get_config('booking', 'teacherslinkonteacher');

        foreach ($settings->teachers as $teacher) {
            // Set URL for each teacher.
            if (!empty($addlink)) {
                $teacherurl = new moodle_url('/mod/booking/teacher.php', ['teacherid' => $teacher->userid]);
                $teacher->teacherurl = $teacherurl->out(false);
            }

            if ($loadprofileimage) {
                $teacheruser = \core_user::get_user($teacher->userid);
                if (!empty($teacheruser->picture)) {
                    $picture = new \user_picture($teacheruser);
                    $picture->size = 150;
                    $imageurl = $picture->get_url($PAGE);
                    $teacher->image = $imageurl;
                } else {
                    $teacher->image = false;
                }
            }

            $teacher->description = format_text($teacher->description, $teacher->descriptionformat);

            $this->teachers[] = (array)$teacher;
        }
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return [
            'teachers' => $this->teachers,
        ];
    }
}
