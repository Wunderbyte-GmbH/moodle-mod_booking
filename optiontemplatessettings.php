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
use mod_booking\table\optiontemplatessettings_table;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$optionid = optional_param('optionid', 0, PARAM_INT);
$action = optional_param('action', 0, PARAM_ALPHANUM);
list($course, $cm) = get_course_and_cm_from_cmid($id);

// No guest autologin.
require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$pageurl = new moodle_url('/mod/booking/optiontemplatessettings.php',  ['id' => $id, 'optionid' => $optionid]);

if (($action === 'delete') && ($optionid > 0)) {
    $DB->delete_records('booking_options', ['id' => $optionid]);
    $pageurl->remove_params('optionid');
    redirect($pageurl, get_string('templatedeleted', 'booking'), 5);
}

$table = new optiontemplatessettings_table('optiontemplatessettings', $id);
$fields = 'bo.id AS optionid, bo.text AS name, bo.bookingid AS bookingid';
$table->set_sql($fields,
    "{booking_options} bo", 'bo.bookingid = 0');

$table->define_baseurl($pageurl);

$PAGE->set_url($pageurl);
$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('optiontemplatessettings', 'booking'));
$PAGE->navbar->add(get_string('optiontemplatessettings', 'booking'), $pageurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('optiontemplatessettings', 'booking'));

$table->out(25, true);

echo $OUTPUT->footer();
