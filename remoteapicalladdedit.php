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
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', '', PARAM_INT);

// No guest autologin.
require_login(0, false);

$pageurl = new moodle_url('/mod/booking/remoteapicalladdedit.php');
$allapis = new moodle_url('/mod/booking/remoteapicall.php');
$PAGE->set_url($pageurl);
admin_externalpage_setup('modbookingremotapicall', '', null, '', array('pagelayout' => 'report'));
$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('remoteapicall', 'booking'));
$PAGE->navbar->add(get_string('addeditremoteapicall', 'booking'), $pageurl);

$mform = new \mod_booking\form\remoteapicalladdedit_form();
if ($mform->is_cancelled()) {
    redirect($allapis);
} else if ($fromform = $mform->get_data()) {
    $remoteapi = new stdClass();
    if ($id != '') {
        $remoteapi->id = $fromform->id;
    }
    $remoteapi->course = $fromform->course;
    $remoteapi->url = $fromform->url;

    $message = '';
    $wait = 0;

    try {
        if ($id != '') {
            $DB->update_record("booking_remoteapi", $remoteapi);
        } else {
            $DB->insert_record("booking_remoteapi", $remoteapi);
        }
    } catch (\Throwable $th) {
        $message = get_string('onlyoneurl', 'booking');
        $wait = 5;
    }

    redirect($allapis, $message, $wait);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('addeditremoteapicall', 'booking'));

    $defaultvalues = new stdClass();
    if ($id != '') {
        $defaultvalues = $DB->get_record('booking_remoteapi', array('id' => $id));
    }

    // Processed if form is submitted but data not validated & form should be redisplayed OR first display of form.
    $mform->set_data($defaultvalues);
    $mform->display();

    echo $OUTPUT->footer();
}