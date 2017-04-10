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
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/booking/icallib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/filelib.php');
if ($CFG->branch < 31) {
    require_once($CFG->dirroot . '/tag/locallib.php');
}

require_once($CFG->dirroot . '/question/category_class.php');

require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');

function booking_cron() {
    global $DB, $USER, $CFG;

    mtrace('Starting cron for Booking ...');

    $now = time();
    $toprocess = $DB->get_records_sql(
            'SELECT bo.id, bo.coursestarttime, b.daystonotify
            FROM {booking_options} bo
            LEFT JOIN {booking} b ON b.id = bo.bookingid
            WHERE b.daystonotify > 0
            AND bo.coursestarttime > 0
            AND bo.coursestarttime > :now
            AND bo.sent = 0', array ( 'now' => $now));

    foreach ($toprocess as $value) {
        $dateevent = new DateTime();
        $dateevent->setTimestamp($value->coursestarttime);
        $datenow = new DateTime();

        $dateevent->modify('-' . $value->daystonotify . ' day');

        if ($dateevent < $datenow) {

            $save = new stdClass();
            $save->id = $value->id;
            $save->sent = 1;

            booking_send_notification($save->id, get_string('notificationsubject', 'booking'));

            $DB->update_record("booking_options", $save);
        }

        mtrace('Ending cron for Booking ...');

        return true;
    }
}

function booking_get_coursemodule_info($cm) {
    global $CFG, $DB;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $tags = new booking_tags($cm);
    $info = new cached_cm_info();

    $booking = new mod_booking\booking($cm->id);
    $booking->apply_tags();

    $info->name = $booking->booking->name;

    return $info;
}

function booking_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload,
        array $options = array()) {
    global $CFG, $DB;

    // Check the contextlevel is as expected - if your plugin is a block, this becomes
    // CONTEXT_BLOCK, etc.
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'myfilemanager') {
        return false;
    }

    // Make sure the user is logged in and has access to the module (plugins that are not course
    // modules should leave out the 'cm' part).
    require_login($course, true, $cm);

    // Leave this line out if you set the itemid to null in make_pluginfile_url (set $itemid to 0 instead).
    $itemid = array_shift($args); // The first item in the $args array.
                                  // Use the itemid to retrieve any relevant data records and
                                  // perform any security checks to see if the
                                  // user really does have access to the file in question.
                                  // Extract the filename / filepath from the $args array.
    $filename = array_pop($args); // The last item in the $args array.
    if (!$args) {
        // Var $args is empty => the path is '/'.
        $filepath = '/';
    } else {
        // Var $args contains elements of the filepath.
        $filepath = '/' . implode('/', $args) . '/';
    }

    // Retrieve the file from the Files API.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_booking', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false; // The file does not exist.
    }

    // Send the file back to the browser - in this case with a cache lifetime of 1 day and no filtering.
    send_stored_file($file, 0, 0, true, $options);
}

function booking_user_outline($course, $user, $mod, $booking) {
    global $DB;
    if ($answer = $DB->get_record('booking_answers',
            array('bookingid' => $booking->id, 'userid' => $user->id))) {
        $result = new stdClass();
        $result->info = "'" . format_string(booking_get_option_text($booking, $answer->optionid)) .
                 "'";
        $result->time = $answer->timemodified;
        return $result;
    }
    return null;
}

function booking_user_complete($course, $user, $mod, $booking) {
    global $DB;
    if ($answer = $DB->get_record('booking_answers',
            array("bookingid" => $booking->id, "userid" => $user->id))) {
        $result = new stdClass();
        $result->info = "'" . format_string(booking_get_option_text($booking, $answer->optionid)) .
                 "'";
        $result->time = $answer->timemodified;
        echo get_string("answered", "booking") . ": $result->info. " .
                 get_string("updated", '', userdate($result->time));
    } else {
        print_string("notanswered", "booking");
    }
}

function booking_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_GROUPMEMBERSONLY:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_RATE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;

        default:
            return null;
    }
}

function booking_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;

    // Get booking details.
    if (!($booking = $DB->get_record('booking', array('id' => $cm->instance)))) {
        throw new Exception("Can't find booking {$cm->instance}");
    }

    if ($booking->enablecompletion) {
        $user = $DB->get_record('booking_answers',
                array('bookingid' => $booking->id, 'userid' => $userid, 'completed' => '1'));

        if ($user === false) {
            return false;
        } else {
            return true;
        }
    } else {
        return $type;
    }
}

/**
 * Given an object containing all the necessary data this will create a new instance and return the id
 * number of the new instance.
 *
 * @param unknown $booking
 * @return unknown
 */
function booking_add_instance($booking) {
    global $DB, $CFG;

    $booking->timemodified = time();

    if (isset($booking->additionalfields) && count($booking->additionalfields) > 0) {
        $booking->additionalfields = implode(',', $booking->additionalfields);
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    }

    if (empty($booking->timerestrict)) {
        $booking->timeopen = $booking->timeclose = 0;
    }

    // Copy the text fields out.
    $booking->bookedtext = $booking->bookedtext['text'];
    $booking->waitingtext = $booking->waitingtext['text'];
    $booking->notifyemail = $booking->notifyemail['text'];
    $booking->statuschangetext = $booking->statuschangetext['text'];
    $booking->deletedtext = $booking->deletedtext['text'];
    $booking->pollurltext = $booking->pollurltext['text'];
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'];
    $booking->notificationtext = $booking->notificationtext['text'];
    $booking->userleave = $booking->userleave['text'];

    // Insert answer options from mod_form.
    $booking->id = $DB->insert_record("booking", $booking);

    $cmid = $booking->coursemodule;
    $context = context_module::instance($cmid);

    if ($draftitemid = file_get_submitted_draft_itemid('myfilemanager')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanager',
                $booking->id, array('subdirs' => false, 'maxfiles' => 50));
    }
    if ($CFG->branch < 31) {
        tag_set('booking', $booking->id, $booking->tags, 'mod_booking', $context->id);
    } else {
        core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context,
                $booking->tags);
    }

    if (!empty($booking->option)) {
        foreach ($booking->option as $key => $value) {
            $value = trim($value);
            if (isset($value) && $value != '') {
                $option = new stdClass();
                $option->text = $value;
                $option->bookingid = $booking->id;
                if (isset($booking->limit[$key])) {
                    $option->maxanswers = $booking->limit[$key];
                }
                $option->timemodified = time();
                $DB->insert_record("booking_options", $option);
            }
        }
    }

    booking_grade_item_update($booking);

    return $booking->id;
}

/**
 * Given an object containing all the necessary data this will update an existing instance.
 *
 * @param unknown $booking
 * @return boolean
 */
function booking_update_instance($booking) {
    global $DB, $CFG;
    // We have to prepare the bookingclosingtimes as an $arrray, currently they are in $booking as $key (string).
    $booking->id = $booking->instance;
    $booking->timemodified = time();
    $cm = get_coursemodule_from_instance('booking', $booking->id);
    $context = context_module::instance($cm->id);

    if (isset($booking->additionalfields) && count($booking->additionalfields) > 0) {
        $booking->additionalfields = implode(',', $booking->additionalfields);
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    }

    if (empty($booking->assessed)) {
        $booking->assessed = 0;
    }

    if (empty($booking->ratingtime) or empty($booking->assessed)) {
        $booking->assesstimestart = 0;
        $booking->assesstimefinish = 0;
    }

    $arr = array();

    if ($CFG->branch >= 31) {
        core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context,
                $booking->tags);
    } else {
        tag_set('booking', $booking->id, $booking->tags, 'mod_booking', $context->id);
    }

    file_save_draft_area_files($booking->myfilemanager, $context->id, 'mod_booking',
            'myfilemanager', $booking->id, array('subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 50));

    if (empty($booking->timerestrict)) {
        $booking->timeopen = 0;
        $booking->timeclose = 0;
    }

    // Copy the text fields out.
    $booking->bookedtext = $booking->bookedtext['text'];
    $booking->waitingtext = $booking->waitingtext['text'];
    $booking->notifyemail = $booking->notifyemail['text'];
    $booking->statuschangetext = $booking->statuschangetext['text'];
    $booking->deletedtext = $booking->deletedtext['text'];
    $booking->pollurltext = $booking->pollurltext['text'];
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'];
    $booking->notificationtext = $booking->notificationtext['text'];
    $booking->userleave = $booking->userleave['text'];

    // Update, delete or insert answers.
    if (!empty($booking->option)) {
        foreach ($booking->option as $key => $value) {
            $value = trim($value);
            $option = new stdClass();
            $option->text = $value;
            $option->bookingid = $booking->id;
            if (isset($booking->limit[$key])) {
                $option->maxanswers = $booking->limit[$key];
            }
            $option->timemodified = time();
            if (isset($booking->optionid[$key]) && !empty($booking->optionid[$key])) { // existing booking record
                $option->id = $booking->optionid[$key];
                if (isset($value) && $value != '') {
                    $DB->update_record("booking_options", $option);
                } else { // Empty old option - needs to be deleted.
                    $DB->delete_records("booking_options", array("id" => $option->id));
                }
            } else {
                if (isset($value) && $value != '') {
                    $DB->insert_record("booking_options", $option);
                }
            }
        }
    }

    booking_grade_item_update($booking);

    return $DB->update_record('booking', $booking);
}

/**
 * Update the booking option settings when adding and modifying a single booking option
 *
 * @param array $optionvalues
 * @return boolean|number
 */
function booking_update_options($optionvalues) {
    global $DB, $CFG;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $bokingutils = new booking_utils();

    $booking = $DB->get_record('booking', array('id' => $optionvalues->bookingid));

    $option = new stdClass();
    $option->bookingid = $optionvalues->bookingid;
    $option->text = trim($optionvalues->text);
    $option->howmanyusers = $optionvalues->howmanyusers;
    $option->removeafterminutes = $optionvalues->removeafterminutes;

    $option->notificationtext = $optionvalues->notificationtext;
    $option->disablebookingusers = $optionvalues->disablebookingusers;

    $option->sent = 0;

    $option->location = trim($optionvalues->location);
    $option->institution = trim($optionvalues->institution);
    $option->address = trim($optionvalues->address);

    $option->pollurl = $optionvalues->pollurl;
    $option->pollurlteachers = $optionvalues->pollurlteachers;
    if ($optionvalues->limitanswers == 0) {
        $option->limitanswers = 0;
        $option->maxanswers = 0;
        $option->maxoverbooking = 0;
    } else {
        $option->maxanswers = $optionvalues->maxanswers;
        $option->maxoverbooking = $optionvalues->maxoverbooking;
        $option->limitanswers = 1;
    }

    if (isset($optionvalues->restrictanswerperiod)) {
        $option->bookingclosingtime = $optionvalues->bookingclosingtime;
    } else {
        $option->bookingclosingtime = 0;
    }
    $option->courseid = $optionvalues->courseid;
    if (isset($optionvalues->startendtimeknown)) {
        $option->coursestarttime = $optionvalues->coursestarttime;
        $option->courseendtime = $optionvalues->courseendtime;
    } else {
        $option->coursestarttime = 0;
        $option->courseendtime = 0;
    }

    $option->description = $optionvalues->description;
    $option->limitanswers = $optionvalues->limitanswers;
    $option->timemodified = time();
    if (isset($optionvalues->optionid) && !empty($optionvalues->optionid) &&
             $optionvalues->optionid != -1) { // existing booking record
        $option->id = $optionvalues->optionid;
        if (isset($optionvalues->text) && $optionvalues->text != '') {
            $option->calendarid = $DB->get_field('booking_options', 'calendarid',
                    array('id' => $option->id));
            $groupid = $DB->get_field('booking_options', 'groupid', array('id' => $option->id));
            $coursestarttime = $DB->get_field('booking_options', 'coursestarttime',
                    array('id' => $option->id));

            if ($coursestarttime != $optionvalues->coursestarttime) {
                $option->sent = 0;
            } else {
                $option->sent = $DB->get_field('booking_options', 'sent',
                        array('id' => $option->id));
            }

            $option->groupid = $bokingutils->group($booking, $option);

            if ($option->calendarid > 0) {
                // Event exists.
                if (isset($optionvalues->addtocalendar)) {
                    booking_option_add_to_cal($booking, $option, $optionvalues);
                } else {
                    // Delete event if exist
                    $event = calendar_event::load($option->calendarid);
                    $event->delete(true);

                    $option->addtocalendar = 0;
                    $option->calendarid = 0;
                }
            } else {
                $option->addtocalendar = 0;
                $option->calendarid = 0;
                // Insert into calendar.
                if (isset($optionvalues->addtocalendar)) {
                    booking_option_add_to_cal($booking, $option, $optionvalues);
                }
            }

            $DB->update_record("booking_options", $option);

            return $option->id;
        }
    } else if (isset($optionvalues->text) && $optionvalues->text != '') {
        $option->addtocalendar = 0;
        $option->calendarid = 0;
        // Insert into calendar.
        if (isset($optionvalues->addtocalendar)) {
            booking_option_add_to_cal($booking, $option, $optionvalues);
        }

        $option->groupid = $bokingutils->group($booking, $option);

        return $DB->insert_record("booking_options", $option);
    }
}

/**
 * Add the booking option to the calendar
 *
 * @param array $option
 */
function booking_option_add_to_cal($booking, $option, $optionvalues) {
    global $DB;
    $whereis = '';
    if (strlen($option->location) > 0) {
        $whereis = '<p>' . get_string('location', 'booking') . ': ' . $option->location . '</p>';
    }

    $event = new stdClass();
    $event->id = $option->calendarid;
    $event->name = $option->text;
    $event->description = $option->description . $whereis;
    $event->courseid = $option->courseid;
    if ($option->courseid == 0) {
        $event->courseid = $booking->course;
    }
    $event->groupid = 0;
    $event->userid = 0;
    $event->modulename = 'booking';
    $event->instance = $option->bookingid;
    $event->eventtype = 'booking';
    $event->timestart = $option->coursestarttime;
    $event->visible = instance_is_visible('booking', $booking);
    $event->timeduration = $option->courseendtime - $option->coursestarttime;

    if ($DB->record_exists("event", array('id' => $event->id))) {
        $calendarevent = calendar_event::load($event->id);
        $calendarevent->update($event);
        $option->calendarid = $event->id;
        $option->addtocalendar = $optionvalues->addtocalendar;
    } else {
        unset($event->id);
        $tmpevent = calendar_event::create($event);
        $option->calendarid = $tmpevent->id;
    }
}

/**
 * Checks the status of the specified user
 *
 * @param $userid userid of the user
 * @param $optionid booking option to check
 * @param $bookingid booking id
 * @param $cmid course module id
 * @return localised string of user status
 */
function booking_get_user_status($userid, $optionid, $bookingid, $cmid) {
    global $DB;
    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $current = $DB->get_record('booking_answers',
            array('bookingid' => $bookingid, 'userid' => $userid, 'optionid' => $optionid));
    $allresponses = $DB->get_records_select('booking_answers',
            "bookingid = $bookingid AND optionid = $optionid", array(), 'timemodified', 'userid');

    $context = context_module::instance($cmid);
    $sortedresponses = array();
    if (!empty($allresponses)) {
        foreach ($allresponses as $answer) {
            $sortedresponses[] = $answer->userid;
        }
        $useridaskey = array_flip($sortedresponses);

        if ($option->limitanswers) {
            if (!isset($useridaskey[$userid])) {
                $status = get_string('notbooked', 'booking');
            } else if ($useridaskey[$userid] > $option->maxanswers + $option->maxoverbooking) {
                $status = "Problem, please contact the admin";
            } else if (($useridaskey[$userid]) >= $option->maxanswers) {
                $status = get_string('onwaitinglist', 'booking');
            } else if ($useridaskey[$userid] <= $option->maxanswers) {
                $status = get_string('booked', 'booking');
            } else {
                $status = get_string('notbooked', 'booking');
            }
        } else {
            if (isset($useridaskey[$userid])) {
                $status = get_string('booked', 'booking');
            } else {
                $status = get_string('notbooked', 'booking');
            }
        }
        return $status;
    }
    return get_string('notbooked', 'booking');
}

/**
 * Display a message about the maximum nubmer of bookings this user is allowed to make
 *
 * @param object $booking
 * @param object $user
 * @return string
 */
function booking_show_maxperuser($booking, $user) {
    global $USER;

    $warning = '';

    if (!empty($booking->booking->banusernames)) {
        $disabledusernames = explode(',', $booking->booking->banusernames);

        foreach ($disabledusernames as $value) {
            if (strpos($USER->username, trim($value)) !== false) {
                $warning = html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
            }
        }
    }

    if (!$booking->booking->maxperuser) {
        return $warning; // No per-user limits.
    }

    $outdata = new stdClass();
    $outdata->limit = $booking->booking->maxperuser;
    $outdata->count = booking_get_user_booking_count($booking, $user);

    $warning .= html_writer::tag('p', get_string('maxperuserwarning', 'mod_booking', $outdata));
    return $warning;
}

/**
 * Determins the number of bookings that a single user has already made in all booking options
 *
 * @param object $booking
 * @param object $user
 * @return number of bookings made by user
 */
function booking_get_user_booking_count($booking, $user) {
    global $DB;

    $result = $DB->get_records('booking_answers',
            array('bookingid' => $booking->id, 'userid' => $user->id));

    return count($result);
}

/**
 * Extend booking navigation settings
 *
 * @param settings_navigation $settings
 * @param navigation_node $navref
 * @return void
 */
function booking_extend_settings_navigation(settings_navigation $settings, navigation_node $navref) {
    global $PAGE, $DB;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = $cm->context;
    $course = $PAGE->course;
    $optionid = $PAGE->url->get_param('optionid');

    if (!$course) {
        return;
    }

    if (has_capability('mod/booking:updatebooking', $context)) {
        $settingnode = $navref->add(get_string("bookingoptionsmenu", "booking"), null,
                navigation_node::TYPE_CONTAINER);
        $settingnode->add(get_string('addnewbookingoption', 'booking'),
                new moodle_url('editoptions.php', array('id' => $cm->id, 'optionid' => -1)));
        $settingnode->add(get_string('importcsvbookingoption', 'booking'),
                new moodle_url('importoptions.php', array('id' => $cm->id)));
        $settingnode->add(get_string('importexcelbutton', 'booking'),
                new moodle_url('importexcel.php', array('id' => $cm->id)));
        $settingnode->add(get_string('tagtemplates', 'booking'),
                new moodle_url('tagtemplates.php', array('id' => $cm->id)));
        if (!is_null($optionid)) {
            $connectedbooking = $DB->count_records('booking_other', array('optionid' => $optionid));
            $settingnode = $navref->add(get_string("optionmenu", "booking"), null,
                    navigation_node::TYPE_CONTAINER);
            $settingnode->add(get_string('updatebooking', 'booking'),
                    new moodle_url('/mod/booking/editoptions.php',
                            array('id' => $cm->id, 'optionid' => $optionid)));
            $settingnode->add(get_string('duplicatebooking', 'booking'),
                    new moodle_url('/mod/booking/editoptions.php',
                            array('id' => $cm->id, 'optionid' => -1, 'copyoptionid' => $optionid)));
            $settingnode->add(get_string('deletebookingoption', 'booking'),
                    new moodle_url('/mod/booking/report.php',
                            array('id' => $cm->id, 'optionid' => $optionid,
                                'action' => 'deletebookingoption', 'sesskey' => sesskey())));
            $settingnode->add(get_string('optiondates', 'booking'),
                    new moodle_url('/mod/booking/optiondates.php',
                            array('id' => $cm->id, 'optionid' => $optionid)));
            if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id)) &&
                     $connectedbooking > 0) {
                $settingnode->add(get_string('editotherbooking', 'booking'),
                        new moodle_url('/mod/booking/otherbooking.php',
                                array('id' => $cm->id, 'optionid' => $optionid)));
            }
        }
    }
}

/**
 * Check if logged in user is in teachers db.
 *
 * @return true if is assigned as teacher otherwise return false
 */
function booking_check_if_teacher($option) {
    global $DB, $USER;

    $userr = $DB->get_record('booking_teachers',
            array('bookingid' => $option->bookingid, 'userid' => $USER->id,
                'optionid' => $option->id));

    if ($userr === false) {
        return false;
    } else {
        return true;
    }
}

/**
 * Manually enrol the user in the relevant course, if that setting is on and a course has been specified.
 *
 * @param object $option
 * @param object $booking
 * @param int $userid
 */
function booking_enrol_user($option, $booking, $userid) {
    global $DB;

    if (!$option->courseid) {
        return; // No course specified.
    }

    if (!enrol_is_enabled('manual')) {
        return; // Manual enrolment not enabled.
    }

    if (!$enrol = enrol_get_plugin('manual')) {
        return; // No manual enrolment plugin
    }
    if (!$instances = $DB->get_records('enrol',
            array('enrol' => 'manual', 'courseid' => $option->courseid,
                'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }

    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.

    if ($booking->addtogroup == 1) {
        if (!is_null($option->groupid) && ($option->groupid > 0)) {
            groups_add_member($option->groupid, $userid);
        }
    }
}

/**
 * Automatically enrol the user in the relevant course, if that setting is on and a course has been specified.
 *
 * @param object $option
 * @param object $booking
 * @param int $userid
 */
function booking_check_enrol_user($option, $booking, $userid) {
    global $DB;

    if (!$booking->autoenrol) {
        return; // Autoenrol not enabled.
    }
    if (!$option->courseid) {
        return; // No course specified.
    }

    if (!enrol_is_enabled('manual')) {
        return; // Manual enrolment not enabled.
    }

    if (!$enrol = enrol_get_plugin('manual')) {
        return; // No manual enrolment plugin
    }
    if (!$instances = $DB->get_records('enrol',
            array('enrol' => 'manual', 'courseid' => $option->courseid,
                'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }

    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.

    if ($booking->addtogroup == 1) {
        if (!is_null($option->groupid) && ($option->groupid > 0)) {
            groups_add_member($option->groupid, $userid);
        }
    }

}

/**
 * Automatically unenrol the user from the relevant course, if that setting is on and a course has been specified.
 *
 * @param object $option
 * @param object $booking
 * @param int $userid
 */
function booking_check_unenrol_user($option, $booking, $userid) {
    global $DB;

    if (!$booking->autoenrol) {
        return; // Autoenrol not enabled.
    }
    if (!$option->courseid) {
        return; // No course specified.
    }
    if (!enrol_is_enabled('manual')) {
        return; // Manual enrolment not enabled.
    }
    if (!$enrol = enrol_get_plugin('manual')) {
        return; // No manual enrolment plugin
    }
    if (!$instances = $DB->get_records('enrol',
            array('enrol' => 'manual', 'courseid' => $option->courseid,
                'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }

    if ($booking->addtogroup == 1) {
        if (!is_null($option->groupid) && ($option->groupid > 0)) {
            groups_remove_member($option->groupid, $userid);
        }
    }

    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->unenrol_user($instance, $userid); // Unenrol the user.
}

/**
 * This inverts the completion status of the selected users.
 *
 * @param array $selectedusers
 * @param unknown $booking
 * @param number $cmid
 * @param number $optionid
 */
function booking_activitycompletion_teachers($selectedusers, $booking, $cmid, $optionid) {
    global $DB;
    list($course, $cm) = get_course_and_cm_from_cmid($cmid, "booking");

    $completion = new completion_info($course);

    foreach ($selectedusers as $uid) {
        foreach ($uid as $ui) {
            // TODO: Optimization of db query: instead of loop, one get_records query
            $userdata = $DB->get_record('booking_teachers',
                    array('optionid' => $optionid, 'userid' => $ui));

            if ($userdata->completed == '1') {
                $userdata->completed = '0';

                $DB->update_record('booking_teachers', $userdata);

                if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                    $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
                }
            } else {
                $userdata->completed = '1';

                $DB->update_record('booking_teachers', $userdata);

                if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                    $completion->update_state($cm, COMPLETION_COMPLETE, $ui);
                }
            }
        }
    }
}

/**
 * Generate new numbers for users
 *
 * @param unknown $bookingdatabooking
 * @param unknown $cmid
 * @param unknown $optionid
 * @param unknown $allselectedusers
 */
function booking_generatenewnumners($bookingdatabooking, $cmid, $optionid, $allselectedusers) {
    global $DB;

    if (!empty($allselectedusers)) {
        $tmprecnum = $DB->get_record_sql(
                'SELECT numrec FROM {booking_answers} WHERE optionid = ? ORDER BY numrec DESC LIMIT 1',
                array($optionid));

        if ($tmprecnum->numrec == 0) {
            $recnum = 1;
        } else {
            $recnum = $tmprecnum->numrec + 1;
        }

        foreach ($allselectedusers as $ui) {
            // TODO: Optimize DB query: get_records instead of loop
            $userdata = $DB->get_record('booking_answers',
                    array('optionid' => $optionid, 'userid' => $ui));
            $userdata->numrec = $recnum++;
            $DB->update_record('booking_answers', $userdata);
        }
    } else {
        $allusers = $DB->get_records_sql(
                'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY RAND()',
                array($optionid));

        $recnum = 1;

        foreach ($allusers as $user) {
            $user->numrec = $recnum++;
            $DB->update_record('booking_answers', $user);
        }
    }
}

/**
 * Invert activity completion status of selected users
 *
 * @param array $selectedusers array of userids
 * @param stdClass $booking booking instance
 * @param number $cmid course module id
 * @param number $optionid
 */
function booking_activitycompletion($selectedusers, $booking, $cmid, $optionid) {
    global $DB;

    $course = $DB->get_record('course', array('id' => $booking->course));
    $completion = new completion_info($course);

    $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);

    foreach ($selectedusers as $ui) {
        $userdata = $DB->get_record('booking_answers',
                array('optionid' => $optionid, 'userid' => $ui));

        if ($userdata->completed == '1') {
            $userdata->completed = '0';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);

            if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
            }
        } else {
            $userdata->completed = '1';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);

            if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $ui);
            }
        }
    }
}

// GRADING AND RATING.

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @param stdClass $booking
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function booking_get_user_grades($booking, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot . '/rating/lib.php');

    $ratingoptions = new stdClass();
    $ratingoptions->component = 'mod_booking';
    $ratingoptions->ratingarea = 'bookingoption';

    // need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'booking';
    $ratingoptions->moduleid = $booking->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $booking->assessed;
    $ratingoptions->scaleid = $booking->scale;
    $ratingoptions->itemtable = 'booking_answers';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Update activity grades
 *
 * @category grade
 * @param stdClass $booking
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function booking_update_grades($booking, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if (!$booking->assessed) {
        booking_grade_item_update($booking);
    } else if ($grades = booking_get_user_grades($booking, $userid)) {
        booking_grade_item_update($booking, $grades);
    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        booking_grade_item_update($booking, $grade);
    } else {
        booking_grade_item_update($booking);
    }
}

/**
 * Create/update grade item
 *
 * @category grade
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param stdClass $booking Booking object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function booking_grade_item_update($booking, $grades = null) {
    global $CFG;
    if (!function_exists('grade_update')) { // workaround for buggy PHP versions
        require_once($CFG->libdir . '/gradelib.php');
    }

    $params = array('itemname' => $booking->name, 'idnumber' => $booking->cmidnumber);

    if (!$booking->assessed or $booking->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;
    } else if ($booking->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $booking->scale;
        $params['grademin'] = 0;
    } else if ($booking->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$booking->scale;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/booking', $booking->course, 'mod', 'booking', $booking->id, 0, $grades,
            $params);
}

/**
 * Delete grade item
 *
 * @category grade
 * @return grade_item
 */
function booking_grade_item_delete($booking) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/booking', $booking->course, 'mod', 'booking', $booking->id, 0, null,
            array('deleted' => 1));
}

/**
 * This function returns if a scale is being used by the booking instance
 *
 * @global object
 * @param int $scaleid negative number
 * @return bool
 */
function booking_scale_used($bookingid, $scaleid) {
    global $DB;
    $return = false;
    $rec = $DB->get_record("booking", array("id" => $bookingid, "scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of forum This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any booking instance
 */
function booking_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('booking', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function booking_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_booking' || $ratingarea != 'bookingoption') {
        // Know nothing about component/ratingarea: return null for default perms.
        return null;
    }
    return array('view' => has_capability('mod/booking:viewrating', $context),
        'viewany' => has_capability('mod/booking:viewanyrating', $context),
        'viewall' => has_capability('mod/booking:viewallratings', $context),
        'rate' => has_capability('mod/booking:rate', $context));
}

/**
 * Validates a submitted rating. OBSOLETE?
 *
 * @param array $params submitted data context => object the context in which the rated items exists [required] component => The component for this
 *            module - should always be mod_forum [required] ratingarea => object the context in which the rated items exists [required] itemid => int
 *            the ID of the object being rated [required] scaleid => int the scale from which the user can select a rating. Used for bounds checking.
 *            [required] rating => int the submitted rating [required] rateduserid => int the id of the user whose items have been rated. NOT the user
 *            who submitted the ratings. 0 to update all. [required] aggregation => int the aggregation method to apply when calculating grades ie
 *            RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function booking_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_booking.
    if ($params['component'] != 'mod_booking') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in booking).
    if ($params['ratingarea'] != 'bookingoption') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts.
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records.
    $answer = $DB->get_record('booking_answers',
            array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $booking = $DB->get_record('booking', array('id' => $answer->bookingid), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $booking->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('booking', $booking->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the booking.
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($booking->scale != $params['scaleid']) {
        // The scale being submitted doesnt match the one in the database.
        throw new rating_exception('invalidscaleid');
    }

    // Check the item we're rating was created in the assessable time window.
    if (!empty($booking->assesstimestart) && !empty($booking->assesstimefinish)) {
        if ($answer->timecreated < $booking->assesstimestart ||
                 $answer->timecreated > $booking->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    // Check that the submitted rating is valid for the scale.
    if ($params['rating'] < 0 && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // Upper limit.
    if ($booking->scale < 0) {
        // It is a custom scale.
        $scalerecord = $DB->get_record('scale', array('id' => -$booking->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $booking->scale) {
        // If its numeric and submitted rating is above maximum.
        throw new rating_exception('invalidnum');
    }
    return true;
}

/**
 * Rate users.
 *
 * @param stdClass $ratings
 * @param array $params
 */
function booking_rate($ratings, $params) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/rating/lib.php');

    $contextid = $params->contextid;
    $component = 'mod_booking';
    $ratingarea = 'bookingoption';
    $scaleid = $params->scaleid;
    $returnurl = $params->returnurl;

    $result = new stdClass();

    list($context, $course, $cm) = get_context_info_array($params->contextid);
    require_login($course, false, $cm);

    $contextid = null; // Now we have a context object, throw away the id from the user.

    $rm = new rating_manager();

    // Check the module rating permissions.
    // Doing this check here rather than within rating_manager::get_ratings() so we can choose how to handle the error.
    $pluginpermissionsarray = $rm->get_plugin_permissions_array($context->id, 'mod_booking',
            $ratingarea);

    if (!$pluginpermissionsarray['rate']) {
        print_error('ratepermissiondenied', 'rating');
    } else {
        foreach ($ratings as $rating) {
            $checks = array('context' => $context, 'component' => $component,
                'ratingarea' => $ratingarea, 'itemid' => $rating->itemid, 'scaleid' => $scaleid,
                'rating' => $rating->rating, 'rateduserid' => $rating->rateduserid);
            if (!$rm->check_rating_is_valid($checks)) {
                echo $OUTPUT->header();
                echo get_string('ratinginvalid', 'rating');
                echo $OUTPUT->footer();
                die();
            }

            if ($rating->rating != RATING_UNSET_RATING) {
                $ratingoptions = new stdClass();
                $ratingoptions->context = $context;
                $ratingoptions->component = $component;
                $ratingoptions->ratingarea = $ratingarea;
                $ratingoptions->itemid = $rating->itemid;
                $ratingoptions->scaleid = $scaleid;
                $ratingoptions->userid = $USER->id;

                $newrating = new rating($ratingoptions);
                $newrating->update_rating($rating->rating);
            } else { // Delete the rating if the user set to "Rate..."
                $options = new stdClass();
                $options->contextid = $context->id;
                $options->component = $component;
                $options->ratingarea = $ratingarea;
                $options->userid = $USER->id;
                $options->itemid = $rating->itemid;

                $rm->delete_ratings($options);
            }
        }
    }
    if (!empty($cm) && $context->contextlevel == CONTEXT_MODULE) {
        // Tell the module that its grades have changed.
        $modinstance = $DB->get_record($cm->modname, array('id' => $cm->instance), '*', MUST_EXIST);
        $modinstance->cmidnumber = $cm->id; // MDL-12961.
        $functionname = $cm->modname . '_update_grades';
        require_once($CFG->dirroot . "/mod/{$cm->modname}/lib.php");
        foreach ($ratings as $rating) {
            if (function_exists($functionname)) {
                $functionname($modinstance, $rating->rateduserid);
            }
        }
    }
}
// END RATING AND GRADES.

// Send reminder email.
function booking_sendreminderemail($selectedusers, $booking, $cmid, $optionid) {
    booking_send_notification($optionid, get_string('notificationsubject', 'booking'),
            $selectedusers);
}

// Send mail to all teachers - pollurlteachers.
function booking_sendpollurlteachers($booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnval = true;

    $teachers = $DB->get_records("booking_teachers",
            array("optionid" => $optionid, 'bookingid' => $booking->booking->id));

    foreach ($teachers as $tuser) {
        $userdata = $DB->get_record('user', array('id' => $tuser->userid));

        $params = booking_generate_email_params($booking->booking, $booking->option, $userdata,
                $cmid);

        $pollurlmessage = booking_get_email_body($booking->booking, 'pollurlteacherstext',
                'pollurlteacherstextmessage', $params);
        $booking->booking->pollurlteacherstext = $pollurlmessage;
        $pollurlmessage = booking_get_email_body($booking->booking, 'pollurlteacherstext',
                'pollurlteacherstextmessage', $params);

        $eventdata = new stdClass();
        $eventdata->modulename = 'booking';
        $eventdata->userfrom = $USER;
        $eventdata->userto = $userdata;
        $eventdata->subject = get_string('pollurlteacherstextsubject', 'booking', $params);
        $eventdata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $pollurlmessage));
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $pollurlmessage;
        $eventdata->smallmessage = '';
        $eventdata->component = 'mod_booking';
        $eventdata->name = 'bookingconfirmation';

        $returnval = message_send($eventdata);
    }
    return $returnval;
}

// Send mail to all users - pollurl.
function booking_sendpollurl($attemptidsarray, $booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnval = true;

    $sender = $DB->get_record('user', array('username' => $booking->booking->bookingmanager));

    foreach ($attemptidsarray as $suser) {
        $tuser = $DB->get_record('user', array('id' => $suser));

        $params = booking_generate_email_params($booking->booking, $booking->option, $tuser, $cmid);

        $pollurlmessage = booking_get_email_body($booking->booking, 'pollurltext',
                'pollurltextmessage', $params);
        $booking->booking->pollurltext = $pollurlmessage;
        $pollurlmessage = booking_get_email_body($booking->booking, 'pollurltext',
                'pollurltextmessage', $params);

        $eventdata = new stdClass();
        $eventdata->modulename = 'booking';
        $eventdata->userfrom = $USER;
        $eventdata->userto = $tuser;
        $eventdata->subject = get_string('pollurltextsubject', 'booking', $params);
        $eventdata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $pollurlmessage));
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $pollurlmessage;
        $eventdata->smallmessage = '';
        $eventdata->component = 'mod_booking';
        $eventdata->name = 'bookingconfirmation';

        $returnval = message_send($eventdata);
    }

    $dataobject = new stdClass();
    $dataobject->id = $booking->option->id;
    $dataobject->pollsend = 1;

    $DB->update_record('booking_options', $dataobject);

    return $returnval;
}

// Send custom message
function booking_sendcustommessage($optionid, $subject, $message, $uids) {
    global $DB, $USER;

    $returnval = true;

    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $booking = $DB->get_record('booking', array('id' => $option->bookingid));

    $cm = get_coursemodule_from_instance('booking', $booking->id);
    foreach ($uids as $record) {
        $ruser = $DB->get_record('user', array('id' => $record));

        $eventdata = new stdClass();
        $eventdata->modulename = 'booking';
        $eventdata->userfrom = $USER;
        $eventdata->userto = $ruser;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->messagehtml = '';
        $eventdata->messagetext = $message;
        $eventdata->smallmessage = '';
        $eventdata->component = 'mod_booking';
        $eventdata->name = 'bookingconfirmation';

        $returnval = message_send($eventdata);
    }

    return $returnval;
}

function booking_send_notification($optionid, $subject, $tousers = array()) {
    global $DB, $USER, $CFG;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $returnval = true;

    // TODO: Remove these queries, they are not really necessary.
    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $booking = $DB->get_record('booking', array('id' => $option->bookingid));

    $cm = get_coursemodule_from_instance('booking', $booking->id);

    $bookingdata = new \mod_booking\booking_option($cm->id, $option->id);
    $bookingdata->apply_tags();

    if (!empty($tousers)) {
        foreach ($tousers as $value) {
            $tmpuser = new stdClass();
            $tmpuser->id = $value;
            $allusers[$value] = $tmpuser;
        }
    } else {
        if (isset($bookingdata->usersonlist)) {
            $allusers = $bookingdata->usersonlist;
        } else {
            $allusers = array();
        }
    }
    if (!empty($allusers)) {
        $userids = array_keys($allusers);
        list($usersql, $userparams) = $DB->get_in_or_equal($userids);
        $sql = "id $usersql";
        $users = $DB->get_records_select("user", $sql, $userparams);
        if (!empty($users)) {
            foreach ($users as $ruser) {
                $params = booking_generate_email_params($bookingdata->booking, $bookingdata->option,
                        $ruser, $cm->id);
                $pollurlmessage = booking_get_email_body($bookingdata->booking, 'notifyemail',
                        'notifyemaildefaultmessage', $params);
                $eventdata = new stdClass();
                $eventdata->modulename = 'booking';
                $eventdata->userfrom = $USER;
                $eventdata->userto = $ruser;
                $eventdata->subject = $subject;
                $eventdata->fullmessage = strip_tags(
                        preg_replace('#<br\s*?/?>#i', "\n", $pollurlmessage));
                $eventdata->fullmessageformat = FORMAT_HTML;
                $eventdata->fullmessagehtml = $pollurlmessage;
                $eventdata->smallmessage = '';
                $eventdata->component = 'mod_booking';
                $eventdata->name = 'bookingconfirmation';
                $returnval = message_send($eventdata);
            }
        }
        return $returnval;
    } else {
        return false;
    }
}

/**
 * Given an ID of an instance of this module, will permanently delete the instance
 * and data.
 *
 * @param unknown $id
 * @return boolean
 */
function booking_delete_instance($id) {
    global $DB;

    if (!$booking = $DB->get_record("booking", array("id" => "$id"))) {
        return false;
    }

    $result = true;

    if (!$DB->delete_records("booking_answers", array("bookingid" => "$booking->id"))) {
        $result = false;
    }

    if (!$DB->delete_records("booking_options", array("bookingid" => "$booking->id"))) {
        $result = false;
    }

    if (!$DB->delete_records("booking", array("id" => "$booking->id"))) {
        $result = false;
    }

    return $result;
}

/**
 * Returns the users with data in one booking (users with records in booking_answers, students)
 * @param int bookingid booking id of booking instance
 *
 * @return array of students
 */
function booking_get_participants($bookingid) {
    global $CFG, $DB;
    // Get students
    $students = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.id
            FROM {user} u,
            {booking_answers} a
            WHERE a.bookingid = '$bookingid' and
            u.id = a.userid");
    // Return students array (it contains an array of unique users)
    return ($students);
}

function booking_get_option_text($booking, $id) {
    global $DB, $USER;
    // Returns text string which is the answer that matches the id
    if ($result = $DB->get_records_sql(
            "SELECT bo.text FROM {booking_options} bo
            LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id
            WHERE bo.bookingid = :bookingid
            AND ba.userid = :userid;", array("bookingid" => $booking->id, "userid" => $USER->id))) {
        $tmptxt = array();
        foreach ($result as $value) {
            $tmptxt[] = $value->text;
        }
        return implode(', ', $tmptxt);
    } else {
        return get_string("notanswered", "booking");
    }
}

function booking_get_view_actions() {
    return array('view', 'view all', 'report');
}

function booking_get_post_actions() {
    return array('choose', 'choose again');
}

/**
 * Implementation of the function for printing the form elements that control whether the course reset functionality affects the booking.
 *
 * @param $mform form passed by reference
 */
function booking_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'bookingheader', get_string('modulenameplural', 'booking'));
    $mform->addElement('advcheckbox', 'reset_booking', get_string('removeresponses', 'booking'));
}

/**
 * Course reset form defaults.
 */
function booking_reset_course_form_defaults($course) {
    return array('reset_booking' => 1);
}

/**
 * Actual implementation of the rest coures functionality, delete all the booking responses for course $data->courseid.
 *
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function booking_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'booking');
    $status = array();

    if (!empty($data->reset_booking)) {
        $bookingssql = "SELECT ch.id
        FROM {$CFG->prefix}booking ch
        WHERE ch.course={$data->courseid}";

        $DB->delete_records_select('booking_answers', "bookingid IN ($bookingssql)");
        $status[] = array('component' => $componentstr,
            'item' => get_string('removeresponses', 'booking'), 'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        shift_course_mod_dates('booking', array('timeopen', 'timeclose'), $data->timeshift,
                $data->courseid);
        $status[] = array('component' => $componentstr, 'item' => get_string('datechanged'),
            'error' => false);
    }
    return $status;
}

/**
 * Event that sends confirmation notification after user successfully booked TODO this should be rewritten for moodle 2.6 onwards
 *
 * @param object $eventdata data of user and users booking details
 * @return bool
 */
function booking_send_confirm_message($eventdata) {
    global $DB, $CFG, $USER;
    $cmid = $eventdata->cmid;
    $optionid = $eventdata->optionid;
    $user = $eventdata->user;

    // Used to store the ical attachment (if required).
    $attachname = '';
    $attachment = '';

    $user = $DB->get_record('user', array('id' => $user->id));
    $bookingmanager = $DB->get_record('user',
            array('username' => $eventdata->booking->bookingmanager));
    $data = booking_generate_email_params($eventdata->booking,
            $eventdata->booking->option[$optionid], $user, $cmid);

    $cansend = true;

    if ($data->status == get_string('booked', 'booking')) {
        $subject = get_string('confirmationsubject', 'booking', $data);
        $subjectmanager = get_string('confirmationsubjectbookingmanager', 'booking', $data);
        $message = booking_get_email_body($eventdata->booking, 'bookedtext', 'confirmationmessage',
                $data);

        // Generate ical attachment to go with the message.
        $ical = new booking_ical($eventdata->booking, $eventdata->booking->option[$optionid], $user,
                $bookingmanager);
        if ($attachment = $ical->get_attachment()) {
            $attachname = $ical->get_name();
        }
    } else if ($data->status == get_string('onwaitinglist', 'booking')) {
        $subject = get_string('confirmationsubjectwaitinglist', 'booking', $data);
        $subjectmanager = get_string('confirmationsubjectwaitinglistmanager', 'booking', $data);
        $message = booking_get_email_body($eventdata->booking, 'waitingtext',
                'confirmationmessagewaitinglist', $data);
    } else {
        $subject = "test";
        $subjectmanager = "tester";
        $message = "message";

        $cansend = false;
    }
    $messagehtml = text_to_html($message, false, false, true);
    $errormessage = get_string('error:failedtosendconfirmation', 'booking', $data);
    $errormessagehtml = text_to_html($errormessage, false, false, true);
    $user->mailformat = 1; // Always send HTML version as well

    $messagedata = new stdClass();
    $messagedata->userfrom = $bookingmanager;
    if ($eventdata->booking->sendmailtobooker) {
        $messagedata->userto = $DB->get_record('user', array('id' => $USER->id));
    } else {
        $messagedata->userto = $DB->get_record('user', array('id' => $user->id));
    }
    $messagedata->subject = $subject;
    $messagedata->messagetext = $message;
    $messagedata->messagehtml = $messagehtml;
    $messagedata->attachment = $attachment;
    $messagedata->attachname = $attachname;

    if ($cansend) {
        $sendtask = new mod_booking\task\send_confirmation_mails();
        $sendtask->set_custom_data($messagedata);
        \core\task\manager::queue_adhoc_task($sendtask);
    }

    if ($eventdata->booking->copymail) {
        $messagedata->userto = $bookingmanager;
        $messagedata->subject = $subjectmanager;

        if ($cansend) {
            $sendtask = new mod_booking\task\send_confirmation_mails();
            $sendtask->set_custom_data($messagedata);
            \core\task\manager::queue_adhoc_task($sendtask);
        }
    }
    return true;
}

/**
 *
 * @param number $seconds
 */
function booking_pretty_duration($seconds) {
    $measures = array('days' => 24 * 60 * 60, 'hours' => 60 * 60, 'minutes' => 60);
    $durationparts = array();
    foreach ($measures as $label => $amount) {
        if ($seconds >= $amount) {
            $howmany = floor($seconds / $amount);
            $durationparts[] = get_string($label, 'mod_booking', $howmany);
            $seconds -= $howmany * $amount;
        }
    }
    return implode(' ', $durationparts);
}

/**
 * Prepares the data to be sent with confirmation mail
 *
 * @param stdClass $booking
 * @param stdClass $option
 * @param stdClass $user
 * @param int $cmid
 * @return stdClass data to be sent via mail
 */
function booking_generate_email_params(stdClass $booking, stdClass $option, stdClass $user, $cmid) {
    global $CFG;

    $params = new stdClass();

    $timeformat = get_string('strftimetime');
    $dateformat = get_string('strftimedate');

    $duration = '';
    if ($option->coursestarttime && $option->courseendtime) {
        $seconds = $option->courseendtime - $option->coursestarttime;
        $duration = booking_pretty_duration($seconds);
    }
    $courselink = '';
    if ($option->courseid) {
        $courselink = new moodle_url('/course/view.php', array('id' => $option->courseid));
        $courselink = html_writer::link($courselink, $courselink->out());
    }
    $bookinglink = new moodle_url('/mod/booking/view.php', array('id' => $cmid));
    $bookinglink = $bookinglink->out();

    $params->qr_id = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
             rawurlencode($user->id) . '&choe=UTF-8" title="Link to Google.com" />';
    $params->qr_username = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
             rawurlencode($user->username) . '&choe=UTF-8" title="Link to Google.com" />';

    $params->status = booking_get_user_status($user->id, $option->id, $booking->id, $cmid);
    $params->participant = fullname($user);
    $params->title = s($option->text);
    $params->duration = $booking->duration;
    $params->starttime = $option->coursestarttime ? userdate($option->coursestarttime, $timeformat) : '';
    $params->endtime = $option->courseendtime ? userdate($option->courseendtime, $timeformat) : '';
    $params->startdate = $option->coursestarttime ? userdate($option->coursestarttime, $dateformat) : '';
    $params->enddate = $option->courseendtime ? userdate($option->courseendtime, $dateformat) : '';
    $params->courselink = $courselink;
    $params->bookinglink = $bookinglink;
    $params->location = $option->location;
    $params->institution = $option->institution;
    $params->address = $option->address;
    $params->eventtype = $booking->eventtype;
    $params->pollstartdate = $option->coursestarttime ? userdate((int) $option->coursestarttime,
            get_string('pollstrftimedate', 'booking')) : '';
    if (empty($option->pollurl)) {
        $params->pollurl = $booking->pollurl;
    } else {
        $params->pollurl = $option->pollurl;
    }
    if (empty($option->pollurlteachers)) {
        $params->pollurlteachers = $booking->pollurlteachers;
    } else {
        $params->pollurlteachers = $option->pollurlteachers;
    }

    $val = '';

    if (!is_null($option->times)) {
        $times = explode(',', $option->times);
        foreach ($times as $time) {
            $slot = explode('-', $time);
            $tmpdate = new stdClass();
            $tmpdate->leftdate = userdate($slot[0], get_string('leftdate', 'booking'));
            $tmpdate->righttdate = userdate($slot[1], get_string('righttdate', 'booking'));

            $val .= get_string('leftandrightdate', 'booking', $tmpdate) . '<br>';
        }
    }

    $params->times = $val;

    return $params;
}

/**
 * Generate the email body based on the activity settings and the booking parameters
 *
 * @param object $booking the booking activity object
 * @param string $fieldname the name of the field that contains the custom text
 * @param string $defaultname the name of the default string
 * @param object $params the booking details
 * @return string
 */
function booking_get_email_body($booking, $fieldname, $defaultname, $params) {
    if (empty($booking->$fieldname)) {
        return get_string($defaultname, 'booking', $params);
    }

    $text = $booking->$fieldname;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

/**
 * Checks if user on waitinglist gets normal place if a user is deleted
 *
 * @param $optionid id of booking option
 * @param $booking booking object
 * @param $cancelleduserid user id that was deleted form booking option
 * @param $cmid course module id
 * @return mixed false if no user gets from waitinglist to booked list or userid of user now on booked list
 */
function booking_check_statuschange($optionid, $booking, $cancelleduserid, $cmid) {
    global $DB;
    if (booking_get_user_status($cancelleduserid, $optionid, $booking->id, $cmid) !== get_string('booked', 'booking')) {
        return false;
    }
    // backward compatibility hack TODO: remove
    if (!isset($booking->option[$optionid])) {
        $option = $DB->get_record('booking_options',
                array('bookingid' => $booking->id, 'id' => $optionid));
    } else {
        $option = $booking->option[$optionid];
    }
    if ($option->maxanswers == 0) {
        return false; // No limit on bookings => no waiting list to manage
    }
    $allresponses = $DB->get_records('booking_answers',
            array('bookingid' => $booking->id, 'optionid' => $optionid), 'timemodified', 'userid');
    $context = context_module::instance($cmid);
    $firstuseronwaitinglist = $option->maxanswers + 1;
    $i = 1;
    $sortedresponses = array();
    foreach ($allresponses as $answer) {
        if (has_capability('mod/booking:choose', $context, $answer->userid)) {
            $sortedresponses[$i++] = $answer->userid;
        }
    }
    if (count($sortedresponses) <= $option->maxanswers) {
        return false;
    } else if (isset($sortedresponses[$firstuseronwaitinglist])) {
        return $sortedresponses[$firstuseronwaitinglist];
    } else {
        return false;
    }
}

/**
 * Checks if required user profile fields are filled out
 *
 * @param $userid to be checked
 * @return false if no redirect necessery true if necessary
 */
function booking_check_user_profile_fields($userid) {
    global $DB;
    $redirect = false;
    if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
        foreach ($categories as $category) {
            if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id",
                    array(), 'sortorder ASC')) {
                // check first if *any* fields will be displayed and if there are required fields
                $requiredfields = array();
                $redirect = false;
                foreach ($fields as $field) {
                    if ($field->visible != 0 && $field->required == 1) {
                        if (!$userdata = $DB->get_field('user_info_data', 'data',
                                array("userid" => $userid, "fieldid" => $field->id))) {
                            $redirect = true;
                        }
                    }
                }
            }
        }
    }
    return $redirect;
}

/**
 * Deletes a booking option and the associated user answers
 *
 * @param $bookingid the booking instance
 * @param $optionid the booking option
 * @return false if not successful, true on success
 */
function booking_delete_booking_option($booking, $optionid) {
    global $DB;

    $event = new stdClass();

    if (!$option = $DB->get_record("booking_options", array("id" => $optionid))) {
        return false;
    }

    $result = true;

    $params = array('bookingid' => $booking->id, 'optionid' => $optionid);
    $userids = $DB->get_fieldset_select('booking_answers', 'userid',
            'bookingid = :bookingid AND optionid = :optionid', $params);
    foreach ($userids as $userid) {
        booking_check_unenrol_user($option, $booking, $userid); // Unenrol any users enroled via this option.
    }
    if (!$DB->delete_records("booking_answers",
            array("bookingid" => $booking->id, "optionid" => $optionid))) {
        $result = false;
    }

    // Delete calendar entry, if any.
    $event->id = $DB->get_field('booking_options', 'calendarid', array('id' => $optionid));
    if ($event->id > 0) {
        // Delete event if exist.
        $event = calendar_event::load($event->id);
        $event->delete(true);
    }

    if (!$DB->delete_records("booking_options", array("id" => $optionid))) {
        $result = false;
    }

    return $result;
}

function booking_profile_definition(&$mform) {
    global $CFG, $DB;

    // If user is "admin" fields are displayed regardless.
    $update = has_capability('moodle/user:update', context_system::instance());

    if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
        foreach ($categories as $category) {
            if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id",
                    array(), 'sortorder ASC')) {

                // Check first if *any* fields will be displayed.
                $display = false;
                foreach ($fields as $field) {
                    if ($field->visible != PROFILE_VISIBLE_NONE) {
                        $display = true;
                    }
                }

                // Display the header and the fields.
                if ($display or $update) {
                    $mform->addElement('header', 'category_' . $category->id,
                            format_string($category->name));
                    foreach ($fields as $field) {
                        require_once($CFG->dirroot . '/user/profile/field/' . $field->datatype .
                                 '/field.class.php');
                        $newfield = 'profile_field_' . $field->datatype;
                        $formfield = new $newfield($field->id);
                        $formfield->edit_field($mform);
                    }
                }
            }
        }
    }
}

/**
 * Returns all other caps used in module
 */
function booking_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

function booking_update_subscriptions_button($id, $optionid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "0";
    } else {
        $string = get_string('turneditingon');
        $edit = "1";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/booking/teachers.php\">" .
             "<input type=\"hidden\" name=\"id\" value=\"$id\" />" .
             "<input type=\"hidden\" name=\"optionid\" value=\"$optionid\" />" .
             "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />" .
             "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $optionid
 */
function booking_optionid_subscribe($userid, $optionid) {
    global $DB;

    if ($DB->record_exists("booking_teachers", array("userid" => $userid, "optionid" => $optionid))) {
        return true;
    }

    $option = $DB->get_record("booking_options", array("id" => $optionid));

    $sub = new stdClass();
    $sub->userid = $userid;
    $sub->optionid = $optionid;
    $sub->bookingid = $option->bookingid;

    return $DB->insert_record("booking_teachers", $sub);
}

/**
 * Removes teacher from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $optionid
 */
function booking_optionid_unsubscribe($userid, $optionid) {
    global $DB;
    return ($DB->delete_records('booking_teachers',
            array('userid' => $userid, 'optionid' => $optionid)));
}

function booking_show_subcategories($catid, $courseid) {
    global $DB;
    $categories = $DB->get_records('booking_category', array('cid' => $catid));
    if (count((array) $categories) > 0) {
        echo '<ul>';
        foreach ($categories as $category) {
            $editlink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$category->id\">" .
                     get_string('editcategory', 'booking') . '</a>';
            $deletelink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$category->id&delete=1\">" .
                     get_string('deletecategory', 'booking') . '</a>';
            echo "<li>$category->name - $editlink - $deletelink</li>";
            booking_show_subcategories($category->id, $courseid);
        }
        echo '</ul>';
    }
}


/**
 * Abstract class used by booking subscriber selection controls
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the booking this selector is being used for
     *
     * @var int
     */
    protected $optionid = null;

    /**
     * The context of the booking this selector is being used for
     *
     * @var object
     */
    protected $context = null;

    /**
     * The id of the current group
     *
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $options['accesscontext'] = $options['context'];
        parent::__construct($name, $options);
        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
        if (isset($options['currentgroup'])) {
            $this->currentgroup = $options['currentgroup'];
        }
        if (isset($options['optionid'])) {
            $this->optionid = $options['optionid'];
        }
    }

    /**
     * Returns an array of options to seralise and store for searches
     *
     * @return array
     */
    protected function get_options() {
        global $CFG;
        $options = parent::get_options();
        $options['file'] = substr(__FILE__, strlen($CFG->dirroot . '/'));
        $options['context'] = $this->context;
        $options['currentgroup'] = $this->currentgroup;
        $options['optionid'] = $this->optionid;
        return $options;
    }
}


/**
 * User selector control for removing subscribed users
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_existing_subscriber_selector extends booking_subscriber_selector_base {

    /**
     * Finds all subscribed users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        $params['optionid'] = $this->optionid;

        // only active enrolled or everybody on the frontpage

        list($esql, $eparams) = get_enrolled_sql($this->context, '', 0, true);
        $fields = $this->required_fields_sql('u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $params = array_merge($params, $eparams, $sortparams);

        $subscribers = $DB->get_records_sql(
                "SELECT $fields
                FROM {user} u
                JOIN ($esql) je ON je.id = u.id
                JOIN {booking_teachers} s ON s.userid = u.id
                WHERE $wherecondition AND s.optionid = :optionid
                ORDER BY $sort", $params);

        return array(get_string("existingsubscribers", 'booking') => $subscribers);
    }
}


/**
 * A user selector control for potential subscribers to the selected booking
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_potential_subscriber_selector extends booking_subscriber_selector_base {

    /**
     * If set to true EVERYONE in this course is force subscribed to this booking
     *
     * @var bool
     */
    protected $forcesubscribed = false;

    /**
     * Can be used to store existing subscribers so that they can be removed from the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed = true;
        }
    }

    /**
     * Returns an arary of options for this control
     *
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed === true) {
            $options['forcesubscribed'] = 1;
        }
        return $options;
    }

    /**
     * Finds all potential users Potential subscribers are all enroled users who are not already subscribed.
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $whereconditions = array();
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        if ($wherecondition) {
            $whereconditions[] = $wherecondition;
        }

        if (!$this->forcesubscribed) {
            $existingids = array();
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    $existingids[$user->id] = 1;
                }
            }
            if ($existingids) {
                list($usertest, $userparams) = $DB->get_in_or_equal(array_keys($existingids),
                        SQL_PARAMS_NAMED, 'existing', false);
                $whereconditions[] = 'u.id ' . $usertest;
                $params = array_merge($params, $userparams);
            }
        }

        if ($whereconditions) {
            $wherecondition = 'WHERE ' . implode(' AND ', $whereconditions);
        }

        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields = 'SELECT ' . $this->required_fields_sql('u');
        $countfields = 'SELECT COUNT(u.id)';

        $sql = " FROM {user} u
        JOIN ($esql) je ON je.id = u.id
        $wherecondition";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        // Check to see if there are too many to show sensibly.
        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        // If not, show them.
        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($params, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($this->forcesubscribed) {
            return array(get_string("existingsubscribers", 'booking') => $availableusers);
        } else {
            return array(get_string("potentialsubscribers", 'booking') => $availableusers);
        }
    }

    /**
     * Sets the existing subscribers
     *
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this booking as force subscribed or not
     */
    public function set_force_subscribed($setting = true) {
        $this->forcesubscribed = true;
    }
}

/**
 * Returns list of user objects that are subscribed to this booking
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param booking $booking the booking
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the booking context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function booking_subscribed_teachers($course, $optionid, $id, $groupid = 0, $context = null,
        $fields = null) {
    global $CFG, $DB;

    if (empty($context)) {
        $cm = get_coursemodule_from_id('booking', $id);
        $context = context_module::instance($cm->id);
    }

    $extrauserfields = get_extra_user_fields($context);
    $allnames = user_picture::fields('u', $extrauserfields);
    if (empty($fields)) {
        $fields = "u.id,
                u.username,
                $allnames,
                u.maildisplay,
                u.mailformat,
                u.maildigest,
                u.imagealt,
                u.email,
                u.emailstop,
                u.city,
                u.country,
                u.lastaccess,
                u.lastlogin,
                u.picture,
                u.timezone,
                u.theme,
                u.lang,
                u.trackforums,
                u.mnethostid";
    }

    // Only active enrolled users or everybody on the frontpage.
    list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
    $params['optionid'] = $optionid;
    $results = $DB->get_records_sql(
            "SELECT $fields
                    FROM {user} u
                    JOIN ($esql) je ON je.id = u.id
                    JOIN {booking_teachers} s ON s.userid = u.id
                    WHERE s.optionid = :optionid
                    ORDER BY u.email ASC", $params);

    // Guest user should never be subscribed to a forum.
    unset($results[$CFG->siteguest]);

    return $results;
}

/**
 * get moodle major version
 *
 * @return string moodle version
 */
function booking_get_moodle_version_major() {
    global $CFG;
    $versionarray = explode('.', $CFG->version);
    return $versionarray[0];
}
