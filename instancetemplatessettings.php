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
 * Instance templates manager.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// No guest autologin.
require_login(0, false);

use mod_booking\table\instancetemplatessettings_table;

admin_externalpage_setup('modbookinginstancetemplatessettings', '', [],
    new moodle_url('/mod/booking/instancetemplatessettings.php'));

$instancetodelete = optional_param('delete', 0, PARAM_INT);

$context = context_system::instance();
$PAGE->set_context($context);

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

$pageurl = new moodle_url('/mod/booking/instancetemplatessettings.php');

if (!empty($instancetodelete) && $instancetodelete > 0) {
    $DB->delete_records('booking_instancetemplate', ['id' => $instancetodelete]);
    redirect($pageurl, get_string('templatedeleted', 'mod_booking'), 5);
}

$table = new instancetemplatessettings_table('instancetemplatessettings');
$table->set_sql("id, name, template", "{booking_instancetemplate}", "1=1");

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
