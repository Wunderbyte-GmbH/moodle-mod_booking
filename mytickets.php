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
 * Overview of the entry tickets a user holds.
 *
 * Deliberately separate from the certificate overview: a ticket grants entry, a certificate
 * documents an achievement.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_booking\table\my_tickets_table;

// No guest autologin.
require_login(0, false);

$userid = optional_param('userid', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);

$systemcontext = context_system::instance();

if (empty($userid) || (int) $userid === (int) $USER->id) {
    $user = $USER;
} else {
    // Foreign tickets may only be seen with the ticket report capability.
    require_capability('mod/booking:viewticketreport', $systemcontext);
    $user = core_user::get_user($userid, '*', MUST_EXIST);
}

$pageurl = new moodle_url('/mod/booking/mytickets.php', ['userid' => $user->id]);

$PAGE->set_context(context_user::instance($user->id));
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('standard');
$PAGE->navigation->extend_for_user($user);

$heading = get_string('mytickets', 'mod_booking');
$PAGE->set_title($heading);
$PAGE->set_heading(fullname($user));
$PAGE->navbar->add($heading);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

if (empty(get_config('booking', 'bookingticketon'))) {
    echo $OUTPUT->notification(get_string('myticketsnone', 'mod_booking'), 'info');
    echo $OUTPUT->footer();
    die();
}

if (!$DB->record_exists('booking_tickets', ['userid' => $user->id])) {
    echo $OUTPUT->notification(get_string('myticketsnone', 'mod_booking'), 'info');
} else {
    $table = new my_tickets_table((int) $user->id, $pageurl);
    $table->out($perpage, false);
}

echo $OUTPUT->footer();
