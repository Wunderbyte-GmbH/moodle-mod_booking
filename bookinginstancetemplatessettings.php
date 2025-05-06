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
 * Global settings
 *
 * @package mod_booking
 * @copyright 2019 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_booking\bookinginstancetemplatessettings_table;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$templateid = optional_param('templateid', 0, PARAM_INT);
$action = optional_param('action', 0, PARAM_ALPHANUM);
[$course, $cm] = get_course_and_cm_from_cmid($id);
// No guest autologin.
require_course_login($course, false);
$pageurl = new moodle_url('/mod/booking/bookinginstancetemplatessettings.php', ['id' => $id, 'templateid' => $templateid]);

if (($action === 'delete') && ($templateid > 0)) {
    $DB->delete_records('booking_instancetemplate', ['id' => $templateid]);
    redirect($pageurl, get_string('templatedeleted', 'booking'), 5);
}

$table = new bookinginstancetemplatessettings_table('bookinginstancetemplatessettings', $id);
$table->set_sql('id, name', "{booking_instancetemplate}", '1=1');

$table->define_baseurl($pageurl);

$PAGE->set_url($pageurl);
$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('bookinginstancetemplatessettings', 'mod_booking')
);
$PAGE->navbar->add(get_string('bookinginstancetemplatessettings', 'mod_booking'), $pageurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bookinginstancetemplatessettings', 'mod_booking'));

$table->out(25, true);

echo $OUTPUT->footer();
