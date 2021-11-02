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
 * Add dates to option.
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_option;
use mod_booking\calendar;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once('optiondatesadd_form.php');

global $DB, $PAGE, $OUTPUT, $USER;

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$delete = optional_param('delete', '', PARAM_INT);
$duplicate = optional_param('duplicate', '', PARAM_INT);
$edit = optional_param('edit', '', PARAM_INT);
$url = new moodle_url('/mod/booking/optiondates.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}
// Check if optionid is valid.
$optionid = $DB->get_field('booking_options', 'id',
        array('id' => $optionid, 'bookingid' => $cm->instance), MUST_EXIST);

require_capability('mod/booking:updatebooking', $context);

// Get booking, booking option and booking utils, so we can work with booking utils.
$booking = new mod_booking\booking($cm->id);
$bookingoption = new booking_option($cm->id, $optionid);
$bu = new \mod_booking\booking_utils($booking, $bookingoption);

if ($delete != '') {
    $changes = [];
    // If there is an associated calendar event, delete it first.
    if ($optiondate = $DB->get_record('booking_optiondates', ['id' => $delete])) {
        $DB->delete_records('event', ['id' => $optiondate->eventid]);

        // Also, clean all associated user records
        $records = $DB->get_records('booking_userevents', array('optiondateid' => $delete));

        foreach ($records as $record) {
            $DB->delete_records('event', array('id' => $record->eventid));
            $DB->delete_records('booking_userevents', array('id' => $record->id));
        }

        // Also store the changes so they can be sent in an update mail.
        $changes[] = ['info' => get_string('changeinfosessiondeleted', 'booking'),
                      'fieldname' => 'coursestarttime',
                      'oldvalue' => $optiondate->coursestarttime];
        $changes[] = ['fieldname' => 'courseendtime',
                      'oldvalue' => $optiondate->courseendtime];
    }

    // Now we can delete the session.
    $DB->delete_records("booking_optiondates", array('optionid' => $optionid, 'id' => $delete));

    // If there are no sessions left, we switch from multisession to simple option.
    if (!$DB->get_records('booking_optiondates', ['optionid' => $optionid])) {
        $bu = new \mod_booking\booking_utils();
        $bu->booking_show_option_userevents($optionid);
    }

    booking_updatestartenddate($optionid);

    // Delete associated custom fields.
    optiondate_deletecustomfields($delete);

    // If there have been significant changes, we have to resend an e-mail (containing an updated ical)...
    // ...and the information about the changes..
    if (!empty($changes)) {
        // Set no update to true, so the original
        $bu->react_on_changes($cm->id, $context, $optionid, $changes, true);
    }

    redirect($url, get_string('optiondatessuccessfullydelete', 'booking'), 5);
}

if ($duplicate != '') {
    $record = $DB->get_record("booking_optiondates",
            array('optionid' => $optionid, 'id' => $duplicate),
            'bookingid, optionid, eventid, coursestarttime, courseendtime');

    // Create a new calendar entry for the duplicated event.
    if ($calendarevent = $DB->get_record('event', ['id' => $record->eventid])) {
        unset($calendarevent->id);
        $neweventid = $DB->insert_record('event', $calendarevent);
        $record->eventid = $neweventid;
    } else {
        $record->eventid = 0; // No calendar event found to duplicate.
    }

    $edit = $DB->insert_record("booking_optiondates", $record);
    booking_updatestartenddate($optionid);
    optiondate_duplicatecustomfields($duplicate, $edit);

    // Also create new user events (user calendar entries) for all booked users.
    $users = $bookingoption->get_all_users_booked();
    foreach ($users as $user) {
        new calendar($cm->id, $optionid, $user->id, calendar::TYPEOPTIONDATE, $edit, 1);
    }
}

$mform = new optiondatesadd_form($url, array('optiondateid' => $edit));

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($url, '', 0);
    die();
} else if ($data = $mform->get_data()) {
    $optiondate = new stdClass();
    $optiondate->id = $data->optiondateid;
    $optiondate->bookingid = $cm->instance;
    $optiondate->optionid = $optionid;
    $optiondate->eventid = $data->eventid;
    $optiondate->coursestarttime = $data->coursestarttime;
    $date = date("Y-m-d", $data->coursestarttime);
    $optiondate->courseendtime = strtotime($date . " {$data->endhour}:{$data->endminute}");
    $optiondate->daystonotify = $data->daystonotify;

    // There is an optiondate id, so we have to update & check for changes.
    if ($optiondate->id != '') {

        // Retrieve the old record and pass it on.
        $oldoptiondate = $DB->get_record('booking_optiondates', array('id' => $optiondate->id));

        $optiondatechanges = $bu->booking_optiondate_get_changes($oldoptiondate, $optiondate);
        if (!empty($optiondatechanges)) {
            $DB->update_record('booking_optiondates', $optiondate);
        }

        $oldcustomfields = $DB->get_records('booking_customfields', array('optiondateid' => $optiondate->id));
        if ($customfieldchanges = $bu->booking_customfields_get_changes($oldcustomfields, $data)) {
            foreach ($customfieldchanges['updates'] as $record) {
                $DB->update_record('booking_customfields', $record);
            }
            foreach ($customfieldchanges['deletes'] as $record) {
                $DB->delete_records('booking_customfields', ['id' => $record]);
            }
            if (count($customfieldchanges['inserts']) > 0) {
                $DB->insert_records('booking_customfields', $customfieldchanges['inserts']);
            }
        }
        $changes = array_merge($optiondatechanges['changes'], $customfieldchanges['changes']);

        // If there have been significant changes, we have to resend an e-mail (containing an updated ical)...
        // ...and the information about the changes..
        if (!empty($changes)) {
            $bu->react_on_changes($cm->id, $context, $optionid, $changes);
        }
    } else {
        // It's a new session.
        $changes = [];
        if ($optiondateid = $DB->insert_record("booking_optiondates", $optiondate)) {
            // Add info that a session has been added (do this only at coursestarttime, we don't need it twice).
            $changes[] = [  'info' => get_string('changeinfosessionadded', 'booking'),
                            'fieldname' => 'coursestarttime',
                            'newvalue' => $optiondate->coursestarttime];
            $changes[] = [  'fieldname' => 'courseendtime',
                            'newvalue' => $optiondate->courseendtime];
        }

        // Retrieve available custom field data.
        if (!empty($optiondateid)) {
            // Currently there can be up to three custom fields.
            $max = 3;
            for ($i = 1; $i <= $max; $i++) {
                $customfieldnamex = 'customfieldname' . $i;
                $customfieldvaluex = 'customfieldvalue' . $i;
                // Only add custom fields if a name for the field was entered.
                if (!empty($data->{$customfieldnamex})) {
                    $customfield = new stdClass();
                    $customfield->bookingid = $cm->instance;
                    $customfield->optionid = $optionid;
                    $customfield->optiondateid = $optiondateid;
                    $customfield->cfgname = $data->{$customfieldnamex};
                    $customfield->value = $data->{$customfieldvaluex};
                    $DB->insert_record("booking_customfields", $customfield);

                    // Add newly added custom field to changes array.
                    $changes[] = ['info' => get_string('changeinfocfadded', 'booking'),
                                  'optionid' => $optionid,
                                  'optiondateid' => $optiondateid,
                                  'newname' => $customfield->cfgname,
                                  'newvalue' => $customfield->value];
                }
            }

            // If there have been significant changes, we have to resend an e-mail (containing an updated ical)...
            // ...and the information about the changes..
            if (!empty($changes)) {
                $bu->react_on_changes($cm->id, $context, $optionid, $changes);
            }
        }
    }

    booking_updatestartenddate($optionid);
    redirect($url, get_string('optiondatessuccessfullysaved', 'booking'), 5);
} else {
    $PAGE->navbar->add(get_string('optiondates', 'mod_booking'));
    $PAGE->set_title(format_string(get_string('optiondates', 'mod_booking')));
    $PAGE->set_heading(get_string('optiondates', 'mod_booking'));
    $PAGE->set_pagelayout('standard');

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('optiondates', 'mod_booking'), 3, 'helptitle', 'uniqueid');

    $table = new html_table();
    $table->head = array(get_string('optiondatestime', 'mod_booking'), '');

    $times = $DB->get_records('booking_optiondates', array('optionid' => $optionid),
            'coursestarttime ASC');

    $timestable = array();

    foreach ($times as $time) {
        $editing = '';
        if ($edit == $time->id) {
            $button = html_writer::tag('span', get_string('editingoptiondate', 'mod_booking'),
                    array('class' => 'p-x-2'));
            $editing = 'alert alert-success';
        } else {
            $editurl = new moodle_url('optiondates.php',
                    array('id' => $cm->id, 'optionid' => $optionid, 'edit' => $time->id));
            $button = $OUTPUT->single_button($editurl, get_string('edittag', 'mod_booking'), 'get');
        }
        $delete = new moodle_url('optiondates.php',
                array('id' => $id, 'optionid' => $optionid, 'delete' => $time->id));
        $buttondelete = $OUTPUT->single_button($delete, get_string('delete'), 'get');
        $duplicate = new moodle_url('optiondates.php',
                array('id' => $id, 'optionid' => $optionid, 'duplicate' => $time->id));
        $buttonduplicate = $OUTPUT->single_button($duplicate, get_string('duplicate'), 'get');

        $tmpdate = new stdClass();
        $tmpdate->leftdate = userdate($time->coursestarttime,
                get_string('strftimedatetime', 'langconfig'));
        $tmpdate->righttdate = userdate($time->courseendtime,
                get_string('strftimetime', 'langconfig'));

        $timestable[] = array(get_string('leftandrightdate', 'booking', $tmpdate),
            html_writer::tag('span', $button . $buttondelete . $buttonduplicate,
                    array('style' => 'text-align: right; display:table-cell;', 'class' => $editing)));
    }
    $table->data = $timestable;
    echo html_writer::table($table);

    $cancel = new moodle_url('view.php', array('id' => $cm->id));
    $defaultvalues = new stdClass();
    if ($edit != '') {
        $defaultvalues = $DB->get_record('booking_optiondates', array('id' => $edit), '*',
                MUST_EXIST);
        // The id in the form will be course module id, not the optiondate id.
        $defaultvalues->optiondateid = $defaultvalues->id;
        $defaultvalues->optionid = $optionid;
        $defaultvalues->endhour = date('H', $defaultvalues->courseendtime);
        $defaultvalues->endminute = date('i', $defaultvalues->courseendtime);
        $defaultvalues->id = $cm->id;
    }
    $mform->set_data($defaultvalues);
    $mform->display();
}

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('back'), 'get');
echo html_writer::tag('span', $button, array('style' => 'display:table-cell;'));
echo '</div>';
echo $OUTPUT->footer();
