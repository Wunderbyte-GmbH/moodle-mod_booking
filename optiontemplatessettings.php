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
 * @copyright 2017 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('classes/optiontemplatessettings_table.php');

// No guest autologin.
require_login(0, false);

$id = optional_param('id', 0, PARAM_INT);

$pageurl = new moodle_url('/mod/booking/optiontemplatessettings.php');

if ($id > 0) {
    $DB->delete_records('booking_options', array('id' => $id));
    redirect($pageurl, get_string('templatedeleted', 'booking'), 5);
}

$table = new optiontemplatessettings_table('optiontemplatessettings');
$table->set_sql('bo.id id, bo.text name, bo.courseid courseid, c.fullname coursename',
"{booking_options} bo LEFT JOIN {course} c ON c.id = bo.courseid", 'bookingid = 0');

$table->define_baseurl("$CFG->wwwroot/mod/booking/optiontemplatessettings.php");

$PAGE->set_url($pageurl);
admin_externalpage_setup('optiontemplatessettings', '', null, '', array('pagelayout' => 'report'));
$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('optiontemplatessettings', 'booking'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('optiontemplatessettings', 'booking'));

$table->out(25, true);

echo $OUTPUT->footer();
