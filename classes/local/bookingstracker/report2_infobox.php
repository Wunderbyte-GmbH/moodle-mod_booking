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
 * Data for the info line of the bookings tracker option scope (report2.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use context_module;
use mod_booking\output\optiondates_with_entities;
use mod_booking\output\renderer;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;
use moodle_url;

/**
 * Builds the template data for the compact info line below the title in the
 * option scope of the bookings tracker (template mod_booking/report/infobox):
 * description, teachers, responsible contacts, associated course and the
 * optiondates. Kept separate from report2.php so it is unit-testable.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report2_infobox {
    /**
     * Template data for the info line of the given option.
     *
     * @param int $optionid
     * @return array data for template mod_booking/report/infobox
     */
    public static function export_for_option(int $optionid): array {
        global $DB, $PAGE, $USER;

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($optionsettings->cmid);

        $infoboxdata = [
            'optionid' => $optionid,
            'teachers' => [],
            'contacts' => [],
        ];
        if (!empty(trim($optionsettings->description ?? ''))) {
            $description = placeholders_info::render_text(
                $optionsettings->description,
                $optionsettings->cmid,
                $optionsettings->id,
                $USER->id
            );
            $infoboxdata['description'] = format_text(
                $description,
                $optionsettings->descriptionformat ?? FORMAT_HTML,
                ['context' => $context]
            );
        }
        $teachers = [];
        foreach ($optionsettings->teachers as $teacher) {
            $teacheruser = singleton_service::get_instance_of_user((int) $teacher->userid);
            if (!empty($teacheruser)) {
                $teachers[] = $teacheruser;
            }
        }
        $lastindex = count($teachers) - 1;
        foreach ($teachers as $index => $teacheruser) {
            $infoboxdata['teachers'][] = [
                'name' => fullname($teacheruser),
                'profileurl' => (new moodle_url('/user/profile.php', ['id' => $teacheruser->id]))->out(false),
                'notlast' => $index != $lastindex,
            ];
        }
        $infoboxdata['teachersexist'] = !empty($infoboxdata['teachers']);
        $contacts = array_values(array_filter($optionsettings->responsiblecontactuser));
        $lastindex = count($contacts) - 1;
        foreach ($contacts as $index => $contactuser) {
            $infoboxdata['contacts'][] = [
                'name' => fullname($contactuser),
                'profileurl' => (new moodle_url('/user/profile.php', ['id' => $contactuser->id]))->out(false),
                'notlast' => $index != $lastindex,
            ];
        }
        $infoboxdata['contactsexist'] = !empty($infoboxdata['contacts']);
        // Associated course (like on the old report.php), linked with its full name.
        if (!empty($optionsettings->courseid)) {
            $associatedcoursename = $DB->get_field('course', 'fullname', ['id' => $optionsettings->courseid]);
            if ($associatedcoursename !== false) {
                $infoboxdata['associatedcourse'] = [
                    'url' => (new moodle_url('/course/view.php', ['id' => $optionsettings->courseid]))->out(false),
                    'name' => format_string($associatedcoursename),
                ];
            }
        }
        // No optiondates are shown for self-learning courses.
        if (empty($optionsettings->selflearningcourse)) {
            $optiondateswithentities = new optiondates_with_entities($optionsettings);
            $sessions = array_values($optiondateswithentities->sessions);
            if (count($sessions) == 1) {
                // A single date is shown directly in the info line.
                $infoboxdata['singledate'] = $sessions[0]['datestring'] ?? '';
            } else if (count($sessions) > 1) {
                // Multiple dates are collapsed behind a "Show dates" link.
                /** @var renderer $renderer */
                $renderer = $PAGE->get_renderer('mod_booking');
                $infoboxdata['optiondates'] = $renderer->render_optiondates_with_entities($optiondateswithentities);
            }
        }

        return $infoboxdata;
    }

    /**
     * Whether the info line has any content worth rendering.
     *
     * @param array $infoboxdata as returned by export_for_option()
     * @return bool
     */
    public static function has_content(array $infoboxdata): bool {
        return !empty($infoboxdata['teachersexist'])
            || !empty($infoboxdata['contactsexist'])
            || !empty($infoboxdata['singledate'])
            || !empty($infoboxdata['optiondates'])
            || !empty($infoboxdata['description'])
            || !empty($infoboxdata['associatedcourse']);
    }
}
