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
require_once('../../config.php');
require_once("$CFG->libdir/tablelib.php");
require_once($CFG->libdir . '/adminlib.php');

// No guest autologin.
require_login(0, false);

$pageurl = new moodle_url('/mod/booking/remoteapicall.php');
$PAGE->set_url($pageurl);

admin_externalpage_setup('modbookingremotapicall', '', null, '', array('pagelayout' => 'report'));

$download = optional_param('download', '', PARAM_ALPHA);

$table = new \mod_booking\remoteapicall_table('modbookingremotapicall');
$table->is_downloading($download, 'remoteapicall', get_string('remoteapicall', 'booking'));

if (!$table->is_downloading()) {
    $PAGE->set_title(format_string($SITE->shortname) . ': ' . get_string('remoteapicall', 'booking'));
    echo $OUTPUT->header();
    echo html_writer::link(new moodle_url('/mod/booking/remoteapicalladdedit.php'),
                        get_string('addnewremoteapicall', 'booking'));
}

// Work out the sql for the table.
$table->set_sql('mbr.id, mc.fullname coursename, mbr.url', "{booking_remoteapi} mbr left join {course} mc on mc.id = mbr.course", '1=1');
$columns = ['coursename', 'url', 'id'];
$headers = [get_string('course'), get_string('url'), ''];

$table->define_columns($columns);
$table->define_headers($headers);

$table->define_baseurl("$CFG->wwwroot/mod/booking/remoteapicall.php");

$table->out(25, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}