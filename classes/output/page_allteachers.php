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
 * This class prepares data for displaying all teachers.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\output;

use context_system;
use mod_booking\booking_answers;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying all teachers.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_allteachers implements renderable, templatable {
    /** @var stdClass $listofteachers */
    public $listofteachers = [];

    /**
     * In the constructor, we gather all the data we need and store it in the data property.
     *
     * @param array $teacherids
     *
     */
    public function __construct(array $teacherids) {
        global $DB;

        // We get the user objects of the provided teachers.
        foreach ($teacherids as $teacherid) {
            if ($teacheruser = $DB->get_record('user', ['id' => $teacherid])) {
                $this->listofteachers[] = $teacheruser;
            }
        }
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE, $USER, $CFG;

        $returnarray = [];

        $context = context_system::instance();

        if (has_capability('mod/booking:updatebooking', $context)) {
            // Only add, if true - false won't work in template.
            $returnarray['canedit'] = true;
        }

        if (!isset($PAGE->context)) {
            $PAGE->set_context($context);
        }

        // We transform the data object to an array where we can read key & value.
        foreach ($this->listofteachers as $teacher) {
            // Here we can load custom userprofile fields and add the to the array to render.
            // Right now, we just use a few standard pieces of information.

            $shortdescription = strip_tags(format_text($teacher->description, $teacher->descriptionformat));
            $shortdescription = substr($shortdescription, 0, 300);

            $teacherarr = [
                'teacherid' => $teacher->id,
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
                'orderletter' => substr($teacher->lastname, 0, 1), // First letter of the teacher's last name.
                'description' => $shortdescription,

            ];

            if (strlen(strip_tags($teacher->description ?? '')) > 300) {
                $teacherarr['descriptionlong'] = format_text($teacher->description, $teacher->descriptionformat);
            }

            if ($teacher->picture) {
                $picture = new \user_picture($teacher);
                $picture->size = 70;
                $imageurl = $picture->get_url($PAGE);
                $teacherarr['image'] = $imageurl;
            }

            // Add a link to the report of performed teaching units.
            // But only, if the user has the appropriate capability.
            if ((has_capability('mod/booking:updatebooking', $PAGE->context))) {
                $url = new moodle_url('/mod/booking/teacher_performed_units_report.php', ['teacherid' => $teacher->id]);
                $teacherarr['linktoperformedunitsreport'] = $url->out();
            }

            // If the user has set to hide e-mails, we won't show them.
            // However, a site admin will always see e-mail addresses.
            // If the plugin setting to show all teacher e-mails (teachersshowemails) is turned on...
            // ... then teacher e-mails will always be shown to anyone.
            if (
                !empty($teacher->email) &&
                ($teacher->maildisplay == 1 ||
                    has_capability('mod/booking:updatebooking', $context) ||
                    get_config('booking', 'teachersshowemails') ||
                    (get_config('booking', 'bookedteachersshowemails') &&
                        (booking_answers::number_actively_booked($USER->id, $teacher->id) > 0))
                )
            ) {
                $teacherarr['email'] = $teacher->email;
            }

            if (!empty($CFG->messaging)) {
                if (
                    page_teacher::teacher_messaging_is_possible($teacher->id) ||
                    get_config('booking', 'alwaysenablemessaging')
                ) {
                    $teacherarr['messagingispossible'] = true;
                }
            } else {
                $teacherarr['messagesdeactivated'] = true;
            }

            if (has_capability('mod/booking:editteacherdescription', context_system::instance())) {
                $url = new moodle_url('/user/editadvanced.php', ['id' => $teacher->id]);
                $teacherarr['profileediturl'] = $url->out(false);
            }

            $link = new moodle_url('/mod/booking/teacher.php', ['teacherid' => $teacher->id]);
            $teacherarr['link'] = $link->out(false);

            $messagelink = new moodle_url('/message/index.php', ['id' => $teacher->id]);
            $teacherarr['messagelink'] = $messagelink->out(false);

            $returnarray['teachers'][] = $teacherarr;
        }

        return $returnarray;
    }
}
