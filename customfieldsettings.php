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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// No guest autologin.
require_login(0, false);

$pageurl = new moodle_url('/mod/booking/customfieldsettings.php');
$PAGE->set_url($pageurl);
admin_externalpage_setup('modbookingcustomfield', '', null, '', ['pagelayout' => 'report']);
$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('customfieldconfigure', 'booking'));

$mform = new \mod_booking\form\customfield();
if ($mform->is_cancelled()) {
    redirect($pageurl);
} else if ($fromform = $mform->get_data()) {
    redirect($pageurl);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('customfieldconfigure', 'booking'));

$mform->display();

echo $OUTPUT->footer();
