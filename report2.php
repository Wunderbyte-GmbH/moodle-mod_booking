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
 * New report.php for booked users, users on waiting list, deleted users etc.
 *
 * @package   mod_booking
 * @author Bernhard Fischer
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

require_login(0, false);

use mod_booking\output\booked_users;

/* Context param defines the id, we use:
    s|system => system context => ID can be empty or 0.
    c|course => Moodle course context => ID has to be course id
    i|instance => booking instance context => ID has to be cmid of booking instance
    o|option => booking option context => ID has to be optionid of booking option
*/
$contextparam = required_param('context', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);

echo $OUTPUT->header();

switch ($contextparam) {
    case 's':
    case 'system':
        $id = 0;
        break;
    case 'c':
    case 'course':
        $courseid = $id;
        break;
    case 'i':
    case 'instance':
        $cmid = $id;
        break;
    case 'o':
    case 'option':
        $optionid = $id;

        // We call the template render to display how many users are currently reserved.
        $data = new booked_users(
            $optionid,
            true,
            true,
            true,
            true,
            true
        );
        $renderer = $PAGE->get_renderer('mod_booking');
        echo $renderer->render_booked_users($data);

        break;
}

echo $OUTPUT->footer();
