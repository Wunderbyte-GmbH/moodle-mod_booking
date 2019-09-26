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
use mod_booking\booking_option;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/question/category_class.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');

function booking_cron() {
    global $DB;

    mtrace('Starting cron for Booking ...');

    $toprocess = $DB->get_records_sql(
            'SELECT bo.id, bo.coursestarttime, b.daystonotify, b.daystonotify2, bo.sent, bo.sent2
            FROM {booking_options} bo
            LEFT JOIN {booking} b ON b.id = bo.bookingid
            WHERE (b.daystonotify > 0 OR b.daystonotify2 > 0)
            AND bo.coursestarttime > 0  AND bo.coursestarttime > ?
            AND (bo.sent = 0 AND bo.sent2 = 0)', array(time()));

    foreach ($toprocess as $value) {
        $dateevent = new DateTime();
        $dateevent->setTimestamp($value->coursestarttime);
        $datenow = new DateTime();

        $dateevent->modify('-' . $value->daystonotify . ' day');

        if ($value->sent == 0 and $value->daystonotify > 0) {
            if ($dateevent < $datenow) {

                $save = new stdClass();
                $save->id = $value->id;
                $save->sent = 1;

                booking_send_notification($save->id, get_string('notificationsubject', 'booking'));

                $DB->update_record("booking_options", $save);
            }
        }

        $dateevent = new DateTime();
        $dateevent->setTimestamp($value->coursestarttime);

        $dateevent->modify('-' . $value->daystonotify2 . ' day');

        if ($value->sent2 == 0 and $value->daystonotify2 > 0) {
            if ($dateevent < $datenow) {
                $save = new stdClass();
                $save->id = $value->id;
                $save->sent2 = 1;

                booking_send_notification($save->id, get_string('notificationsubject', 'booking'));

                $DB->update_record("booking_options", $save);
            }
        }
    }

    mtrace('Ending cron for Booking ...');

    return true;
}

/**
 * @param stdClass $cm
 * @return cached_cm_info
 */
function booking_get_coursemodule_info($cm) {
    $info = new cached_cm_info();
    $booking = new mod_booking\booking($cm->id);
    $booking->apply_tags();
    $info->name = $booking->settings->name;
    return $info;
}

/**
 *  Callback checking permissions and preparing the file for serving plugin files, see File API.
 *
 * @param $course
 * @param $cm
 * @param $context
 * @param $filearea
 * @param $args
 * @param $forcedownload
 * @param array $options
 * @return bool
 * @throws coding_exception
 * @throws moodle_exception
 * @throws require_login_exception
 */
function booking_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {

    // Check the contextlevel is as expected - if your plugin is a block.
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'myfilemanager' && $filearea !== 'myfilemanageroption' && $filearea !== 'signinlogoheader' && $filearea !== 'signinlogofooter') {
        return false;
    }

    // Make sure the user is logged in and has access to the module.
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

/**
 * Callback for the "Complete" report - prints the activity summary for the given user.
 *
 * @param $course
 * @param $user
 * @param $mod
 * @param $booking
 * @throws coding_exception
 * @throws dml_exception
 */
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
            return true;
        case FEATURE_GROUPINGS:
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
        case FEATURE_COMMENT:
            return true;

        default:
            return null;
    }
}

/**
 * Running addtional permission check on plugin, for example, plugins may have switch to turn on/off comments option,
 * this callback will affect UI display, not like pluginname_comment_validate only throw exceptions.
 *
 * @package mod_booking
 * @category comment
 * @param $commentparam
 * @return array
 * @throws dml_exception
 */
function booking_comment_permissions($commentparam) {
    global $DB, $USER;

    $odata = $DB->get_record('booking_options', array('id' => $commentparam->itemid));
    $bdata = $DB->get_record('booking', array('id' => $odata->bookingid));

    switch ($bdata->comments) {
        case 0:
            return array('post' => false, 'view' => false);
            break;
        case 1:
            return array('post' => true, 'view' => true);
            break;
        case 2:
            $udata = $DB->get_record('booking_answers',
                    array('userid' => $USER->id, 'optionid' => $commentparam->itemid));
            if ($udata) {
                return array('post' => true, 'view' => true);
            } else {
                return array('post' => false, 'view' => true);
            }
            break;
        case 3:
            $udata = $DB->get_record('booking_answers',
                    array('userid' => $USER->id, 'optionid' => $commentparam->itemid));
            if ($udata && $udata->completed == 1) {
                return array('post' => true, 'view' => true);
            } else {
                return array('post' => false, 'view' => true);
            }
            break;
    }
}

/**
 * Validate comment parameter before perform other comments actions
 *
 * @package mod_booking
 * @category comment
 * @param stdClass $comment_param { context => context the context object courseid => int course id cm => stdClass course module object commentarea =>
 *            string comment area itemid => int itemid }
 * @return boolean
 */
function booking_comment_validate($commentparam) {
    global $DB;

    if ($commentparam->commentarea != 'booking_option') {
        throw new comment_exception('invalidcommentarea');
    }
    if (!$record = $DB->get_record('booking_options', array('id' => $commentparam->itemid))) {
        throw new comment_exception('invalidcommentitemid');
    }
    if ($record->id) {
        $booking = $DB->get_record('booking', array('id' => $record->bookingid));
    }
    if (!$booking) {
        throw new comment_exception('invalidid', 'data');
    }
    if (!$course = $DB->get_record('course', array('id' => $booking->course))) {
        throw new comment_exception('coursemisconf');
    }
    if (!$cm = get_coursemodule_from_instance('booking', $booking->id, $course->id)) {
        throw new comment_exception('invalidcoursemodule');
    }
    $context = context_module::instance($cm->id);

    // Validate context id.
    if ($context->id != $commentparam->context->id) {
        throw new comment_exception('invalidcontext');
    }
    return true;
}

/**
 * Calculate completion state.
 *
 * @param $course
 * @param $cm
 * @param $userid
 * @param $type
 * @return bool
 * @throws dml_exception
 */
function booking_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;

    // Get booking details.
    if (!($booking = $DB->get_record('booking', array('id' => $cm->instance)))) {
        throw new Exception("Can't find booking {$cm->instance}");
    }

    if ($booking->enablecompletion > 0) {
        $user = $DB->count_records('booking_answers',
                array('bookingid' => $booking->id, 'userid' => $userid, 'completed' => '1'));

        if ($booking->enablecompletion <= $user) {
            return true;
        }

        return false;
    } else {
        return $type;
    }
}

/**
 * Given an object containing all the necessary data this will create a new instance and return the id number of the new instance.
 *
 * @param object $booking
 * @return number $bookingid
 */
function booking_add_instance($booking) {
    global $DB, $CFG;

    $booking->timemodified = time();

    if (isset($booking->responsesfields) && count($booking->responsesfields) > 0) {
        $booking->responsesfields = implode(',', $booking->responsesfields);
    } else {
        $booking->responsesfields = null;
    }

    if (isset($booking->additionalfields) && count($booking->additionalfields) > 0) {
        $booking->additionalfields = implode(',', $booking->additionalfields);
    } else {
        $booking->additionalfields = null;
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    } else {
        $booking->categoryid = null;
    }

    if (isset($booking->templateid) && $booking->templateid > 0) {
        $booking->templateid = $booking->templateid;
    } else {
        $booking->templateid = 0;
    }

    if (empty($booking->timerestrict)) {
        $booking->timeopen = $booking->timeclose = 0;
    }

    if (isset($booking->showviews) && count($booking->showviews) > 0) {
        $booking->showviews = implode(',', $booking->showviews);
    } else {
        $booking->showviews = '';
    }

    if (isset($booking->reportfields) && count($booking->reportfields) > 0) {
        $booking->reportfields = implode(',', $booking->reportfields);
    } else {
        $booking->reportfields = null;
    }

    if (isset($booking->optionsfields) && count($booking->optionsfields) > 0) {
        $booking->optionsfields = implode(',', $booking->optionsfields);
    } else {
        $booking->optionsfields = null;
    }

    if (isset($booking->signinsheetfields) && count($booking->signinsheetfields) > 0) {
        $booking->signinsheetfields = implode(',', $booking->signinsheetfields);
    } else {
        $booking->signinsheetfields = null;
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
    if (isset($booking->beforebookedtext['text'])) {
        $booking->beforebookedtext = $booking->beforebookedtext['text'];
    }
    if (isset($booking->beforecompletedtext['text'])) {
        $booking->beforecompletedtext = $booking->beforecompletedtext['text'];
    }
    if (isset($booking->aftercompletedtext['text'])) {
        $booking->aftercompletedtext = $booking->aftercompletedtext['text'];
    }

    // Insert answer options from mod_form.
    $booking->id = $DB->insert_record("booking", $booking);

    $cmid = $booking->coursemodule;
    $context = context_module::instance($cmid);

    if ($draftitemid = file_get_submitted_draft_itemid('myfilemanager')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanager',
                $booking->id, array('subdirs' => false, 'maxfiles' => 50));
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogoheader')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'signinlogoheader',
                $booking->id, array('subdirs' => false, 'maxfiles' => 1));
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogofooter')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'signinlogofooter',
                $booking->id, array('subdirs' => false, 'maxfiles' => 1));
    }

    core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context, $booking->tags);

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

    if (isset($booking->showviews) && count($booking->showviews) > 0) {
        $booking->showviews = implode(',', $booking->showviews);
    } else {
        $booking->showviews = '';
    }

    if (isset($booking->responsesfields) && count($booking->responsesfields) > 0) {
        $booking->responsesfields = implode(',', $booking->responsesfields);
    } else {
        $booking->responsesfields = null;
    }

    if (isset($booking->reportfields) && count($booking->reportfields) > 0) {
        $booking->reportfields = implode(',', $booking->reportfields);
    } else {
        $booking->reportfields = null;
    }

    if (isset($booking->signinsheetfields) && count($booking->signinsheetfields) > 0) {
        $booking->signinsheetfields = implode(',', $booking->signinsheetfields);
    } else {
        $booking->signinsheetfields = null;
    }

    if (isset($booking->templateid) && $booking->templateid > 0) {
        $booking->templateid = $booking->templateid;
    } else {
        $booking->templateid = 0;
    }

    if (isset($booking->optionsfields) && count($booking->optionsfields) > 0) {
        $booking->optionsfields = implode(',', $booking->optionsfields);
    } else {
        $booking->optionsfields = null;
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    } else {
        $booking->categoryid = null;
    }

    if (empty($booking->assessed)) {
        $booking->assessed = 0;
    }

    if (empty($booking->ratingtime) or empty($booking->assessed)) {
        $booking->assesstimestart = 0;
        $booking->assesstimefinish = 0;
    }

    $arr = array();
    core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context, $booking->tags);

    file_save_draft_area_files($booking->signinlogoheader, $context->id, 'mod_booking',
            'signinlogoheader', $booking->id, array('subdirs' => false, 'maxfiles' => 1));

    file_save_draft_area_files($booking->signinlogofooter, $context->id, 'mod_booking',
            'signinlogofooter', $booking->id, array('subdirs' => false, 'maxfiles' => 1));

    file_save_draft_area_files($booking->myfilemanager, $context->id, 'mod_booking',
            'myfilemanager', $booking->id, array('subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 50));

    if (empty($booking->timerestrict)) {
        $booking->timeopen = 0;
        $booking->timeclose = 0;
    }

    // Copy the text fields out.
    if (isset($booking->beforebookedtext['text'])) {
        $booking->beforebookedtext = $booking->beforebookedtext['text'];
    }
    if (isset($booking->beforecompletedtext['text'])) {
        $booking->beforecompletedtext = $booking->beforecompletedtext['text'];
    }
    if (isset($booking->aftercompletedtext['text'])) {
        $booking->aftercompletedtext = $booking->aftercompletedtext['text'];
    }
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
            if (isset($booking->optionid[$key]) && !empty($booking->optionid[$key])) { // Existing booking record.
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
 * @param object $optionvalues
 * @param context_module $context
 * @return boolean|number optionid
 */
function booking_update_options($optionvalues, $context) {
    global $DB, $CFG, $USER;
    require_once("$CFG->dirroot/mod/booking/locallib.php");
    require_once("{$CFG->dirroot}/mod/booking/classes/GoogleUrlApi.php");
    $customfields = \mod_booking\booking_option::get_customfield_settings();
    if (!($booking = $DB->get_record('booking', array('id' => $optionvalues->bookingid)))) {
        $booking = new stdClass();
        $booking->id = 0;
    }

    $option = new stdClass();
    $option->bookingid = $optionvalues->bookingid;
    $option->courseid = $optionvalues->courseid;

    if (isset($optionvalues->addastemplate) && $optionvalues->addastemplate == 1) {
        $option->bookingid = 0;
    }

    $option->text = trim($optionvalues->text);
    if (!isset($optionvalues->howmanyusers) || empty ($optionvalues->howmanyusers)) {
        $option->howmanyusers = 0;
    } else {
        $option->howmanyusers = $optionvalues->howmanyusers;
    }
    if (!isset($optionvalues->removeafterminutes) || empty ($optionvalues->removeafterminutes)) {
        $option->removeafterminutes = 0;
    } else {
        $option->removeafterminutes = $optionvalues->removeafterminutes;
    }
    if (!isset($optionvalues->notificationtext) || empty ($optionvalues->notificationtext)) {
        $option->notificationtext = "";
    } else {
        $option->notificationtext = $optionvalues->notificationtext;
    }
    $option->disablebookingusers = $optionvalues->disablebookingusers;

    $option->sent = 0;
    $option->sent2 = 0;

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
    if (isset($optionvalues->startendtimeknown)) {
        $option->coursestarttime = $optionvalues->coursestarttime;
        $option->courseendtime = $optionvalues->courseendtime;
    } else {
        $option->coursestarttime = 0;
        $option->courseendtime = 0;
    }

    if (isset($optionvalues->enrolmentstatus)) {
        $option->enrolmentstatus = $optionvalues->enrolmentstatus;
    } else {
        $option->enrolmentstatus = 0;
    }
    $option->description = $optionvalues->description;
    $option->beforebookedtext = $optionvalues->beforebookedtext;
    $option->beforecompletedtext = $optionvalues->beforecompletedtext;
    $option->aftercompletedtext = $optionvalues->aftercompletedtext;
    $option->limitanswers = $optionvalues->limitanswers;
    $option->duration = $optionvalues->duration;
    $option->timemodified = time();
    if (isset($optionvalues->addtocalendar) && $optionvalues->addtocalendar) {
        $option->addtocalendar = 1;
    } else {
        $option->addtocalendar = 0;
    }
    if (isset($optionvalues->optionid) && !empty($optionvalues->optionid) &&
             $optionvalues->optionid != -1) { // Existing booking option record.
        $option->id = $optionvalues->optionid;
        $option->shorturl = $optionvalues->shorturl;
        if (isset($optionvalues->text) && $optionvalues->text != '') {
            $option->calendarid = $DB->get_field('booking_options', 'calendarid',
                    array('id' => $option->id));
            $coursestarttime = $DB->get_field('booking_options', 'coursestarttime',
                    array('id' => $option->id));

            if ($coursestarttime != $optionvalues->coursestarttime) {
                $option->sent = 0;
                $option->sent2 = 0;
            } else {
                $option->sent = $DB->get_field('booking_options', 'sent',
                        array('id' => $option->id));
                $option->sent2 = $DB->get_field('booking_options', 'sent2',
                        array('id' => $option->id));
            }

            if (isset($booking->addtogroup) && $option->courseid > 0) {
                $bo = new booking_option($context->instanceid, $option->id, array(), 0, 0, false);
                $bo->option->courseid = $option->courseid;
                $option->groupid = $bo->create_group();
                $booked = $bo->get_all_users_booked();
                if (!empty($booked) && $booking->autoenrol) {
                    foreach ($booked as $bookinganswer) {
                        $bo->enrol_user($bookinganswer->userid);
                    }
                }
            }

            if (isset($optionvalues->generatenewurl) && $optionvalues->generatenewurl == 1) {
                // URL shortnere - only if API key is entered.
                $gapik = get_config('booking', 'googleapikey');
                $googer = new GoogleURLAPI($gapik);
                if (!empty($gapik)) {
                    $onlyoneurl = new moodle_url('/mod/booking/view.php',
                            array('id' => $optionvalues->id, 'optionid' => $optionvalues->optionid, 'action' => 'showonlyone',
                                   'whichview' => 'showonlyone'));
                            $onlyoneurl->set_anchor('goenrol');
                    $shorturl = $googer->shorten(htmlspecialchars_decode($onlyoneurl->__toString()));
                    if ($shorturl) {
                        $option->shorturl = $shorturl;
                    }
                }
            }

            // Check if custom field will be updated or newly created.
            if (!empty($customfields)) {
                foreach ($customfields as $fieldcfgname => $field) {
                    if (isset($optionvalues->$fieldcfgname)) {
                        $customfieldid = $DB->get_field('booking_customfields', 'id',
                                array('bookingid' => $booking->id, 'optionid' => $option->id,
                                    'cfgname' => $fieldcfgname));
                        if ($customfieldid) {
                            $customfield = new stdClass();
                            $customfield->id = $customfieldid;
                            $customfield->value = (is_array($optionvalues->$fieldcfgname) ? implode("\n", $optionvalues->$fieldcfgname) : $optionvalues->$fieldcfgname);;
                            $DB->update_record('booking_customfields', $customfield);
                        } else {
                            $customfield = new stdClass();
                            $customfield->value = (is_array($optionvalues->$fieldcfgname) ? implode("\n", $optionvalues->$fieldcfgname) : $optionvalues->$fieldcfgname);
                            $customfield->optionid = $option->id;
                            $customfield->bookingid = $booking->id;
                            $customfield->cfgname = $fieldcfgname;
                            $DB->insert_record('booking_customfields', $customfield);
                        }
                    }
                }
            }

            $DB->update_record("booking_options", $option);

            if (isset($booking->addtogroup) && $option->courseid > 0) {
                $bo = new booking_option($context->instanceid, $option->id, array(), 0, 0, false);
                $bo->option->courseid = $option->courseid;
                $option->groupid = $bo->create_group();
                $booked = $bo->get_all_users_booked();
                if (!empty($booked) && $booking->autoenrol) {
                    foreach ($booked as $bookinganswer) {
                        $bo->enrol_user_coursestart($bookinganswer->userid);
                    }
                }
            }

            $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $option->id,
                            'userid' => $USER->id));
            $event->trigger();

            return $option->id;
        }
    } else if (isset($optionvalues->text) && $optionvalues->text != '') {
        $id = $DB->insert_record("booking_options", $option);

        // Create group in target course if there is a course specified only.
        if ($option->courseid > 0 && $booking->addtogroup) {
            $option->id = $id;
            $bo = new booking_option($context->instanceid, $id, array(), 0, 0, false);
            $option->groupid = $bo->create_group($booking, $option);
            $DB->update_record('booking_options', $option);
        }

        // URL shortnere - only if API key is entered.
        $gapik = get_config('booking', 'googleapikey');
        $option->shorturl = '';
        if (!empty($gapik)) {
            $googer = new GoogleURLAPI($gapik);
            $onlyoneurl = new moodle_url('/mod/booking/view.php',
                    array('id' => $optionvalues->id, 'optionid' => $id, 'action' => 'showonlyone',
                        'whichview' => 'showonlyone'));
            $onlyoneurl->set_anchor('goenrol');

            $shorturl = $googer->shorten(htmlspecialchars_decode($onlyoneurl->__toString()));
            if ($shorturl) {
                $option->shorturl = $shorturl;
                $option->id = $id;
                $DB->update_record("booking_options", $option);
            }
        }

        // Save custom fields if there are any.
        if (!empty($customfields)) {
            foreach ($customfields as $fieldcfgname => $field) {
                if (!empty($optionvalues->$fieldcfgname)) {
                    $customfield = new stdClass();
                    $customfield->value = (is_array($optionvalues->$fieldcfgname) ? implode("\n", $optionvalues->$fieldcfgname) : $optionvalues->$fieldcfgname);;
                    $customfield->optionid = $id;
                    $customfield->bookingid = $booking->id;
                    $customfield->cfgname = $fieldcfgname;
                    $DB->insert_record('booking_customfields', $customfield);
                }
            }
        }

        $event = \mod_booking\event\bookingoption_created::create(array('context' => $context, 'objectid' => $id,
                        'userid' => $USER->id));
        $event->trigger();

        return $id;
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
 * Extend booking user navigation
 */
function booking_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if ($iscurrentuser) {
        $url = new moodle_url('/mod/booking/mybookings.php');
        $string = get_string('mybookings', 'mod_booking');
        $node = new core_user\output\myprofile\node('miscellaneous', 'booking', $string, null, $url);

        $tree->add_node($node);
    }
}

/**
 * Extend booking navigation settings
 *
 * @param settings_navigation $settings
 * @param navigation_node $navref
 * @return void
 */
function booking_extend_settings_navigation(settings_navigation $settings, navigation_node $navref) {
    global $PAGE, $DB, $USER;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = $cm->context;
    $course = $PAGE->course;
    $contextcourse = context_course::instance($course->id);
    $optionid = $PAGE->url->get_param('optionid');

    if (!$course) {
        return;
    }

    if (has_capability('mod/booking:updatebooking', $context) ||
             has_capability('mod/booking:addeditownoption', $context)) {

        $settingnode = $navref->add(get_string("bookingoptionsmenu", "booking"), null,
                navigation_node::TYPE_CONTAINER);

        if (has_capability('mod/booking:manageoptiontemplates', $context)) {
            $settingnode->add(get_string("canmanageoptiontemplates", "mod_booking"),
                new moodle_url('optiontemplatessettings.php', array('id' => $cm->id)));
        }

        $urlparam = array('id' => $cm->id, 'optionid' => -1);
        if (!$templatedid = $DB->get_field('booking', 'templateid', ['id' => $cm->instance])) {
            $templatedid = get_config('booking', 'defaulttemplate');
        }
        if (!empty($templatedid) && $DB->record_exists('booking_options', ['id' => $templatedid])) {
            $urlparam['copyoptionid'] = $templatedid;
        }
        $settingnode->add(get_string('addnewbookingoption', 'booking'),
                new moodle_url('editoptions.php', $urlparam));

        if (has_capability('mod/booking:updatebooking', $context)) {
            $settingnode->add(get_string('importcsvbookingoption', 'booking'),
                    new moodle_url('importoptions.php', array('id' => $cm->id)));
            $settingnode->add(get_string('importexcelbutton', 'booking'),
                    new moodle_url('importexcel.php', array('id' => $cm->id)));
            $settingnode->add(get_string('tagtemplates', 'booking'),
                    new moodle_url('tagtemplates.php', array('id' => $cm->id)));
        }

        $alloptiontemplates = $DB->get_records('booking_options', array('bookingid' => 0), '', $fields = 'id, text', 0, 0);
        if (!empty($alloptiontemplates)) {
            $settingnode = $navref->add(get_string("bookingoptionsfromtemplatemenu", "booking"), null,
            navigation_node::TYPE_CONTAINER);
            foreach ($alloptiontemplates as $key => $value) {
                $settingnode->add($value->text,
                new moodle_url('editoptions.php', array('id' => $cm->id, 'optionid' => -1, 'copyoptionid' => $value->id)));
            }
        }

        if (!is_null($optionid) AND $optionid > 0) {
            $option = $DB->get_record('booking_options', array('id' => $optionid));
            $booking = $DB->get_record('booking', array('id' => $option->bookingid));
            $settingnode = $navref->add(get_string("optionmenu", "booking"), null,
                    navigation_node::TYPE_CONTAINER);
            $keys = $settingnode->parent->get_children_key_list();
            foreach ($keys as $key => $name) {
                if ($name == 'modedit' || $name == 'roleassign' || $name == 'roleoverride' ||
                         $name == 'rolecheck' || $name == 'filtermanage' || $name == 'logreport' ||
                         $name == 'backup' || $name == 'restore') {
                    $node = $settingnode->parent->get($name)->remove();
                }
            }
            $settingnode->add(get_string('edit', 'core'),
                    new moodle_url('/mod/booking/editoptions.php',
                            array('id' => $cm->id, 'optionid' => $optionid)));
            if (has_capability('mod/booking:updatebooking', $context)) {
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
            }
            if (has_capability ( 'mod/booking:subscribeusers', $context ) || booking_check_if_teacher ($option, $USER )) {
                $settingnode->add(get_string('bookotherusers', 'booking'),
                        new moodle_url('/mod/booking/subscribeusers.php',
                                array('id' => $cm->id, 'optionid' => $optionid)));
                $completion = new \completion_info($course);
                if ($completion->is_enabled($cm)) {
                    $settingnode->add(get_string('bookuserswithoutcompletedactivity', 'booking'),
                            new moodle_url('/mod/booking/subscribeusersctivity.php',
                                    array('id' => $cm->id, 'optionid' => $optionid)));
                }
            }
            $modinfo = get_fast_modinfo($course);
            $bookinginstances = isset($modinfo->instances['booking']) ? count($modinfo->instances['booking']) : 0;
            if (has_capability('mod/booking:updatebooking', $contextcourse) && $bookinginstances > 1) {
                $settingnode->add(get_string('moveoptionto', 'booking'),
                    new moodle_url('/mod/booking/moveoption.php',
                        array('id' => $cm->id, 'optionid' => $optionid, 'sesskey' => sesskey())));
            }
            if (has_capability ( 'mod/booking:readresponses', $context ) || booking_check_if_teacher ($option, $USER )) {
                $completion = new \completion_info($course);
                if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $booking->enablecompletion > 0) {
                    $settingnode->add(get_string('confirmuserswith', 'booking'),
                    new moodle_url('/mod/booking/confirmactivity.php', array('id' => $cm->id, 'optionid' => $optionid)));
                }
            }
            if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id)) &&
                    $booking->conectedbooking > 0) {
                $settingnode->add(get_string('editotherbooking', 'booking'),
                        new moodle_url('/mod/booking/otherbooking.php',
                                array('id' => $cm->id, 'optionid' => $optionid)));
            }
        }
    }
}

/**
 * Send an email to a specified user
 *
 * @param stdClass $user A {@link $USER} object
 * @param stdClass $from A {@link $USER} object
 * @param string $subject plain text subject line of the email
 * @param string $messagetext plain text version of the message
 * @param string $messagehtml complete html version of the message (optional)
 * @param string $attachment a file on the filesystem, either relative to $CFG->dataroot or a full
 *        path to a file in $CFG->tempdir
 * @param string $attachname the name of the file (extension indicates MIME)
 * @param bool $usetrueaddress determines whether $from email address should
 *        be sent out. Will be overruled by user profile setting for maildisplay
 * @param string $replyto Email address to reply to
 * @param string $replytoname Name of reply to recipient
 * @param int $wordwrapwidth custom word wrap width, default 79
 * @return bool Returns true if mail was sent OK and false if there was an error.
 */
function booking_email_to_user($user, $from, $subject, $messagetext, $messagehtml = '',
        $attachment = '', $attachname = '', $usetrueaddress = true, $replyto = '', $replytoname = '',
        $wordwrapwidth = 79) {
    global $CFG, $PAGE, $SITE;

    if (empty($user) or empty($user->id)) {
        debugging('Can not send email to null user', DEBUG_DEVELOPER);
        return false;
    }

    if (empty($user->email)) {
        debugging('Can not send email to user without email: ' . $user->id, DEBUG_DEVELOPER);
        return false;
    }

    if (!empty($user->deleted)) {
        debugging('Can not send email to deleted user: ' . $user->id, DEBUG_DEVELOPER);
        return false;
    }

    if (defined('BEHAT_SITE_RUNNING')) {
        // Fake email sending in behat.
        return true;
    }

    if (!empty($CFG->noemailever)) {
        // Hidden setting for development sites, set in config.php if needed.
        debugging('Not sending email due to $CFG->noemailever config setting', DEBUG_NORMAL);
        return true;
    }

    if (email_should_be_diverted($user->email)) {
        $subject = "[DIVERTED {$user->email}] $subject";
        $user = clone ($user);
        $user->email = $CFG->divertallemailsto;
    }

    // Skip mail to suspended users.
    if ((isset($user->auth) && $user->auth == 'nologin') or
            (isset($user->suspended) && $user->suspended)) {
        return true;
    }

    if (!validate_email($user->email)) {
        // We can not send emails to invalid addresses - it might create security issue or confuse
        // the mailer.
        debugging(
                "email_to_user: User $user->id (" . fullname($user) .
                ") email ($user->email) is invalid! Not sending.");
        return false;
    }

    if (over_bounce_threshold($user)) {
        debugging(
                "email_to_user: User $user->id (" . fullname($user) .
                ") is over bounce threshold! Not sending.");
        return false;
    }

    // TLD .invalid is specifically reserved for invalid domain names.
    // For More information, see {@link http://tools.ietf.org/html/rfc2606#section-2}.
    if (substr($user->email, -8) == '.invalid') {
        debugging(
                "email_to_user: User $user->id (" . fullname($user) .
                ") email domain ($user->email) is invalid! Not sending.");
        return true; // This is not an error.
    }

    // If the user is a remote mnet user, parse the email text for URL to the
    // wwwroot and modify the url to direct the user's browser to login at their
    // home site (identity provider - idp) before hitting the link itself.
    if (is_mnet_remote_user($user)) {
        require_once($CFG->dirroot . '/mnet/lib.php');

        $jumpurl = mnet_get_idp_jump_url($user);
        $callback = partial('mnet_sso_apply_indirection', $jumpurl);

        $messagetext = preg_replace_callback("%($CFG->wwwroot[^[:space:]]*)%", $callback,
                $messagetext);
        $messagehtml = preg_replace_callback("%href=[\"'`]($CFG->wwwroot[\w_:\?=#&@/;.~-]*)[\"'`]%",
                $callback, $messagehtml);
    }
    $mail = get_mailer();

    if (!empty($mail->SMTPDebug)) {
        echo '<pre>' . "\n";
    }

    $temprecipients = array();
    $tempreplyto = array();

    // Make sure that we fall back onto some reasonable no-reply address.
    $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
    $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

    if (!validate_email($noreplyaddress)) {
        debugging('email_to_user: Invalid noreply-email ' . s($noreplyaddress));
        $noreplyaddress = $noreplyaddressdefault;
    }

    // Make up an email address for handling bounces.
    if (!empty($CFG->handlebounces)) {
        $modargs = 'B' . base64_encode(pack('V', $user->id)) . substr(md5($user->email), 0, 16);
        $mail->Sender = generate_email_processing_address(0, $modargs);
    } else {
        $mail->Sender = $noreplyaddress;
    }

    // Make sure that the explicit replyto is valid, fall back to the implicit one.
    if (!empty($replyto) && !validate_email($replyto)) {
        debugging('email_to_user: Invalid replyto-email ' . s($replyto));
        $replyto = $noreplyaddress;
    }

    if (is_string($from)) { // So we can pass whatever we want if there is need.
        $mail->From = $noreplyaddress;
        $mail->FromName = $from;
        // Check if using the true address is true, and the email is in the list of allowed domains
        // for sending email,
        // and that the senders email setting is either displayed to everyone, or display to only
        // other users that are enrolled
        // in a course with the sender.
    } else if ($usetrueaddress && can_send_from_real_email_address($from, $user)) {
        if (!validate_email($from->email)) {
            debugging('email_to_user: Invalid from-email ' . s($from->email) . ' - not sending');
            // Better not to use $noreplyaddress in this case.
            return false;
        }
        $mail->From = $from->email;
        $fromdetails = new stdClass();
        $fromdetails->name = fullname($from);
        $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
        $fromdetails->siteshortname = format_string($SITE->shortname);
        $fromstring = $fromdetails->name;
        if ($CFG->emailfromvia == EMAIL_VIA_ALWAYS) {
            $fromstring = get_string('emailvia', 'core', $fromdetails);
        }
        $mail->FromName = $fromstring;
        if (empty($replyto)) {
            $tempreplyto[] = array($from->email, fullname($from));
        }
    } else {
        $mail->From = $noreplyaddress;
        $fromdetails = new stdClass();
        $fromdetails->name = fullname($from);
        $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
        $fromdetails->siteshortname = format_string($SITE->shortname);
        $fromstring = $fromdetails->name;
        if ($CFG->emailfromvia != EMAIL_VIA_NEVER) {
            $fromstring = get_string('emailvia', 'core', $fromdetails);
        }
        $mail->FromName = $fromstring;
        if (empty($replyto)) {
            $tempreplyto[] = array($noreplyaddress, get_string('noreplyname'));
        }
    }

    if (!empty($replyto)) {
        $tempreplyto[] = array($replyto, $replytoname);
    }

    $temprecipients[] = array($user->email, fullname($user));

    // Set word wrap.
    $mail->WordWrap = $wordwrapwidth;

    if (!empty($from->customheaders)) {
        // Add custom headers.
        if (is_array($from->customheaders)) {
            foreach ($from->customheaders as $customheader) {
                $mail->addCustomHeader($customheader);
            }
        } else {
            $mail->addCustomHeader($from->customheaders);
        }
    }

    // If the X-PHP-Originating-Script email header is on then also add an additional
    // header with details of where exactly in moodle the email was triggered from,
    // either a call to message_send() or to email_to_user().
    if (ini_get('mail.add_x_header')) {

        $stack = debug_backtrace(false);
        $origin = $stack[0];

        foreach ($stack as $depth => $call) {
            if ($call['function'] == 'message_send') {
                $origin = $call;
            }
        }

        $originheader = $CFG->wwwroot . ' => ' . gethostname() . ':' .
                str_replace($CFG->dirroot . '/', '', $origin['file']) . ':' . $origin['line'];
        $mail->addCustomHeader('X-Moodle-Originating-Script: ' . $originheader);
    }

    if (!empty($from->priority)) {
        $mail->Priority = $from->priority;
    }

    $renderer = $PAGE->get_renderer('core');
    $context = array('sitefullname' => $SITE->fullname, 'siteshortname' => $SITE->shortname,
        'sitewwwroot' => $CFG->wwwroot, 'subject' => $subject, 'to' => $user->email,
        'toname' => fullname($user), 'from' => $mail->From, 'fromname' => $mail->FromName);
    if (!empty($tempreplyto[0])) {
        $context['replyto'] = $tempreplyto[0][0];
        $context['replytoname'] = $tempreplyto[0][1];
    }
    if ($user->id > 0) {
        $context['touserid'] = $user->id;
        $context['tousername'] = $user->username;
    }

    if (!empty($user->mailformat) && $user->mailformat == 1) {
        // Only process html templates if the user preferences allow html email.

        if ($messagehtml) {
            // If html has been given then pass it through the template.
            $context['body'] = $messagehtml;
            $messagehtml = $renderer->render_from_template('core/email_html', $context);
        } else {
            // If no html has been given, BUT there is an html wrapping template then
            // auto convert the text to html and then wrap it.
            $autohtml = trim(text_to_html($messagetext));
            $context['body'] = $autohtml;
            $temphtml = $renderer->render_from_template('core/email_html', $context);
            if ($autohtml != $temphtml) {
                $messagehtml = $temphtml;
            }
        }
    }

    $context['body'] = $messagetext;
    $mail->Subject = $renderer->render_from_template('core/email_subject', $context);
    $mail->FromName = $renderer->render_from_template('core/email_fromname', $context);
    $messagetext = $renderer->render_from_template('core/email_text', $context);

    // Autogenerate a MessageID if it's missing.
    if (empty($mail->MessageID)) {
        $mail->MessageID = generate_email_messageid();
    }

    if ($messagehtml && !empty($user->mailformat) && $user->mailformat == 1) {
        // Don't ever send HTML to users who don't want it.
        $mail->isHTML(true);
        $mail->Encoding = 'quoted-printable';
        $mail->Body = $messagehtml;
        $mail->AltBody = "\n$messagetext\n";
    } else {
        $mail->IsHTML(false);
        $mail->Body = "\n$messagetext\n";
    }
    if (!is_array((array) $attachment) && ($attachment && $attachname)) {
        $attachment[$attachname] = $attachment;
    }
    if (is_array((array) $attachment)) {
        foreach ($attachment as $attachname => $attachlocation) {
            if (preg_match("~\\.\\.~", $attachlocation)) {
                // Security check for ".." in dir path.
                $supportuser = core_user::get_support_user();
                $temprecipients[] = array($supportuser->email, fullname($supportuser, true));
                $mail->addStringAttachment(
                        'Error in attachment.  User attempted to attach a filename with a unsafe name.',
                        'error.txt', '8bit', 'text/plain');
            } else {
                require_once($CFG->libdir . '/filelib.php');
                $mimetype = mimeinfo('type', $attachname);

                $attachmentpath = $attachlocation;

                // Before doing the comparison, make sure that the paths are correct (Windows uses
                // slashes in the other direction).
                $attachpath = str_replace('\\', '/', $attachmentpath);
                // Make sure both variables are normalised before comparing.
                $temppath = str_replace('\\', '/', realpath($CFG->tempdir));

                // If the attachment is a full path to a file in the tempdir, use it as is,
                // otherwise assume it is a relative path from the dataroot (for backwards
                // compatibility reasons).
                if (strpos($attachpath, $temppath) !== 0) {
                    $attachmentpath = $CFG->dataroot . '/' . $attachmentpath;
                }

                $mail->addAttachment($attachmentpath, $attachname, 'base64', $mimetype);
            }
        }
    }

    // Check if the email should be sent in an other charset then the default UTF-8.
    if ((!empty($CFG->sitemailcharset) || !empty($CFG->allowusermailcharset))) {

        // Use the defined site mail charset or eventually the one preferred by the recipient.
        $charset = $CFG->sitemailcharset;
        if (!empty($CFG->allowusermailcharset)) {
            if ($useremailcharset = get_user_preferences('mailcharset', '0', $user->id)) {
                $charset = $useremailcharset;
            }
        }

        // Convert all the necessary strings if the charset is supported.
        $charsets = get_list_of_charsets();
        unset($charsets['UTF-8']);
        if (in_array($charset, $charsets)) {
            $mail->CharSet = $charset;
            $mail->FromName = core_text::convert($mail->FromName, 'utf-8', strtolower($charset));
            $mail->Subject = core_text::convert($mail->Subject, 'utf-8', strtolower($charset));
            $mail->Body = core_text::convert($mail->Body, 'utf-8', strtolower($charset));
            $mail->AltBody = core_text::convert($mail->AltBody, 'utf-8', strtolower($charset));

            foreach ($temprecipients as $key => $values) {
                $temprecipients[$key][1] = core_text::convert($values[1], 'utf-8',
                        strtolower($charset));
            }
            foreach ($tempreplyto as $key => $values) {
                $tempreplyto[$key][1] = core_text::convert($values[1], 'utf-8', strtolower(
                        $charset));
            }
        }
    }

    foreach ($temprecipients as $values) {
        $mail->addAddress($values[0], $values[1]);
    }
    foreach ($tempreplyto as $values) {
        $mail->addReplyTo($values[0], $values[1]);
    }

    if ($mail->send()) {
        set_send_count($user);
        if (!empty($mail->SMTPDebug)) {
            echo '</pre>';
        }
        return true;
    } else {
        // Trigger event for failing to send email.
        $event = \core\event\email_failed::create(
                array('context' => context_system::instance(), 'userid' => $from->id,
                    'relateduserid' => $user->id,
                    'other' => array('subject' => $subject, 'message' => $messagetext,
                        'errorinfo' => $mail->ErrorInfo)));
        $event->trigger();
        if (CLI_SCRIPT) {
            mtrace('Error: lib/moodlelib.php email_to_user(): ' . $mail->ErrorInfo);
        }
        if (!empty($mail->SMTPDebug)) {
            echo '</pre>';
        }
        return false;
    }
}


/**
 * Check if logged in user is in teachers db.
 *
 * @return true if is assigned as teacher otherwise return false
 */
function booking_check_if_teacher($option) {
    global $DB, $USER;

    $user = $DB->get_record('booking_teachers',
            array('userid' => $USER->id,
                'optionid' => $option->id));

    if ($user === false) {
        return false;
    } else {
        return true;
    }
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
    global $DB, $CFG;
    list($course, $cm) = get_course_and_cm_from_cmid($cmid, "booking");

    require_once($CFG->libdir . '/completionlib.php');
    $completion = new \completion_info($course);

    foreach ($selectedusers as $uid) {
        foreach ($uid as $ui) {
            // TODO: Optimization of db query: instead of loop, one get_records query.
            $userdata = $DB->get_record('booking_teachers',
                    array('optionid' => $optionid, 'userid' => $ui));

            if ($userdata->completed == '1') {
                $userdata->completed = '0';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records('booking_teachers',
                    array('bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1'));

                if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
                    $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
                }
            } else {
                $userdata->completed = '1';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records('booking_teachers',
                    array('bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1'));

                if ($completion->is_enabled($cm) && $booking->enablecompletion <= $countcomplete) {
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
 * @param number $cmid
 * @param number $optionid
 * @param array $allselectedusers
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
            // TODO: Optimize DB query: get_records instead of loop.
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
    global $DB, $CFG;

    $course = $DB->get_record('course', array('id' => $booking->course));
    require_once($CFG->libdir . '/completionlib.php');
    $completion = new \completion_info($course);

    $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);

    foreach ($selectedusers as $ui) {
        $userdata = $DB->get_record('booking_answers',
                array('optionid' => $optionid, 'userid' => $ui));

        if ($userdata->completed == '1') {
            $userdata->completed = '0';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);
            $countcomplete = $DB->count_records('booking_answers',
                    array('bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1'));

            if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
            }
        } else {
            $userdata->completed = '1';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);
            $countcomplete = $DB->count_records('booking_answers',
                    array('bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1'));

            if ($completion->is_enabled($cm) && $booking->enablecompletion <= $countcomplete) {
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

    // Need these to work backwards to get a context id. Is there a better way to get contextid from a module instance.
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
    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
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
 * @throws coding_exception
 * @throws dml_exception
 * @throws rating_exception
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
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 * @throws require_login_exception
 */
function booking_rate($ratings, $params) {
    global $CFG, $USER, $DB, $OUTPUT;
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
            } else { // Delete the rating if the user set to "Rate...".
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
/**
 * Send reminder email.
 * @param $selectedusers
 * @param $booking
 * @param $cmid
 * @param $optionid
 * @throws coding_exception
 */
function booking_sendreminderemail($selectedusers, $booking, $cmid, $optionid) {
    booking_send_notification($optionid, get_string('notificationsubject', 'booking'),
            $selectedusers);
}

/**
 * Send mail to all teachers - pollurlteachers.
 * @param booking_option $booking
 * @param $cmid
 * @param $optionid
 * @return bool|mixed
 * @throws coding_exception
 * @throws dml_exception
 */
function booking_sendpollurlteachers(\mod_booking\booking_option $booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnval = true;

    $teachers = $DB->get_records("booking_teachers",
            array("optionid" => $optionid, 'bookingid' => $booking->booking->settings->id));

    foreach ($teachers as $tuser) {
        $userdata = $DB->get_record('user', array('id' => $tuser->userid));

        $params = booking_generate_email_params($booking->booking->settings, $booking->option, $userdata,
                $cmid, $booking->optiontimes);

        $pollurlmessage = booking_get_email_body($booking->booking->settings, 'pollurlteacherstext',
                'pollurlteacherstextmessage', $params);
        $booking->booking->settings->pollurlteacherstext = $pollurlmessage;
        $pollurlmessage = booking_get_email_body($booking->booking->settings, 'pollurlteacherstext',
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

/**
 * Send pollurl
 *
 * @param $userids
 * @param booking_option $booking
 * @param $cmid
 * @param $optionid
 * @return bool|mixed
 * @throws coding_exception
 * @throws dml_exception
 */
function booking_sendpollurl($userids, \mod_booking\booking_option $booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnval = true;

    $sender = $DB->get_record('user', array('username' => $booking->booking->settings->bookingmanager));

    foreach ($userids as $userid) {
        $tuser = $DB->get_record('user', array('id' => $userid));

        $params = booking_generate_email_params($booking->booking->settings, $booking->option, $tuser, $cmid, $booking->optiontimes);

        $pollurlmessage = booking_get_email_body($booking->booking->settings, 'pollurltext',
                'pollurltextmessage', $params);
        $booking->booking->settings->pollurltext = $pollurlmessage;

        $eventdata = new core\message\message();
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

/**
 * Send a custom message to one or more users.
 *
 * @param integer $optionid
 * @param string $subject
 * @param string $message
 * @param array $uids
 * @return boolean|mixed|number
 */
function booking_sendcustommessage($optionid, $subject, $message, $uids) {
    global $DB, $USER, $CFG;

    $returnval = true;

    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $booking = $DB->get_record('booking', array('id' => $option->bookingid));

    $cm = get_coursemodule_from_instance('booking', $booking->id);
    foreach ($uids as $record) {
        $ruser = $DB->get_record('user', array('id' => $record));
        $eventdata = new \core\message\message();
        if ($CFG->branch > 31) {
            $eventdata->courseid = $cm->course;
        }
        $eventdata->modulename = 'booking';
        $eventdata->userfrom = $USER;
        $eventdata->userto = $ruser;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = $message;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->component = 'mod_booking';
        $eventdata->name = 'bookingconfirmation';
        $returnval = message_send($eventdata);
    }

    return $returnval;
}

/**
 * @param $optionid
 * @param $subject
 * @param array $tousers
 * @return bool|mixed
 * @throws coding_exception
 * @throws dml_exception
 */
function booking_send_notification($optionid, $subject, $tousers = array()) {
    global $DB, $USER, $CFG;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $returnval = true;
    $allusers = array();

    // TODO: Remove this query, not really necessary.
    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $cm = get_coursemodule_from_instance('booking', $option->bookingid);

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
            foreach ($bookingdata->usersonlist as $value) {
                $tmpuser = new stdClass();
                $tmpuser->id = $value->userid;
                $allusers[] = $tmpuser;
            }
        } else {
            $allusers = array();
        }
    }
    if (!empty($allusers)) {
        foreach ($allusers as $record) {
            $ruser = $DB->get_record('user', array('id' => $record->id));

            $params = booking_generate_email_params($bookingdata->booking->settings, $bookingdata->option,
                    $ruser, $cm->id, $bookingdata->optiontimes);
            $pollurlmessage = booking_get_email_body($bookingdata->booking->settings, 'notifyemail',
                    'notifyemaildefaultmessage', $params);

            $eventdata = new \core\message\message();
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
            // $eventdata->modulename = 'booking';
            if ($CFG->branch > 31) {
                $eventdata->courseid = $bookingdata->booking->settings->course;
            }

            $returnval = message_send($eventdata);
        }
        return $returnval;
    } else {
        return false;
    }
}

/**
 * Given an ID of an instance of this module, will permanently delete the instance and data.
 *
 * @param number $id
 * @return boolean
 */
function booking_delete_instance($id) {
    global $DB;

    if (!$booking = $DB->get_record("booking", array("id" => "$id"))) {
        return false;
    }

    if (!$cm = get_coursemodule_from_instance('booking', $id)) {
        return false;
    }

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        return false;
    }

    $result = true;

    if (!$DB->delete_records("booking_answers", array("bookingid" => "$booking->id"))) {
        $result = false;
    }

    $alloptionsid = \mod_booking\booking::get_all_optionids($id);

    foreach ($alloptionsid as $optionid) {
        $bookingoption = new \mod_booking\booking_option($cm->id, $optionid);
        $bookingoption->delete_booking_option();
    }

    if (!$DB->delete_records("booking", array("id" => "$booking->id"))) {
        $result = false;
    }

    return $result;
}

function booking_get_option_text($booking, $id) {
    global $DB, $USER;
    // Returns text string which is the answer that matches the id.
    if ($result = $DB->get_records_sql(
            "SELECT bo.text FROM {booking_options} bo
            LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id
            WHERE bo.bookingid = :bookingid
            AND ba.userid = :userid;",
            array("bookingid" => $booking->id, "userid" => $USER->id))) {
        $tmptxt = array();
        foreach ($result as $value) {
            $tmptxt[] = $value->text;
        }
        return implode(', ', $tmptxt);
    } else {
        return get_string("notanswered", "booking");
    }
}

/**
 * Implementation of the function for printing the form elements that control whether the course reset
 * functionality affects the booking.
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
function booking_generate_email_params(stdClass $booking, stdClass $option, stdClass $user, $cmid, $optiontimes = array()) {
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
    $params->email = $user->email;
    $params->title = format_string($option->text);
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
    $params->shorturl = $option->shorturl;
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

    if (!empty($optiontimes)) {
        $times = explode(',', trim($optiontimes, ','));
        $i = 1;
        foreach ($times as $time) {
            $slot = explode('-', $time);
            $tmpdate = new stdClass();
            $tmpdate->number = $i;
            $tmpdate->date = userdate($slot[0], get_string('strftimedate', 'langconfig'));
            $tmpdate->starttime = userdate($slot[0], get_string('strftimetime', 'langconfig'));
            $tmpdate->endtime = userdate($slot[1], get_string('strftimetime', 'langconfig'));
            $val .= get_string('optiondatesmessage', 'mod_booking', $tmpdate) . '<br><br>';
            $i++;
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
    // Backward compatibility hack TODO: remove.
    if (!isset($booking->option[$optionid])) {
        $option = $DB->get_record('booking_options',
                array('bookingid' => $booking->id, 'id' => $optionid));
    } else {
        $option = $booking->option[$optionid];
    }
    if ($option->maxanswers == 0) {
        return false; // No limit on bookings => no waiting list to manage.
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
 * Returns all other caps used in module
 */
function booking_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $optionid
 * @param $cm
 */
function booking_optionid_subscribe($userid, $optionid, $cm, $groupid = '') {
    global $DB;

    if ($DB->record_exists("booking_teachers", array("userid" => $userid, "optionid" => $optionid))) {
        return true;
    }

    $option = new \mod_booking\booking_option($cm->id, $optionid);

    $sub = new stdClass();
    $sub->userid = $userid;
    $sub->optionid = $optionid;
    $sub->bookingid = $option->booking->settings->id;

    $inserted = $DB->insert_record("booking_teachers", $sub);

    if (!empty($groupid)) {
        groups_add_member($groupid, $userid);
    }

    $option->enrol_user($userid, false, $option->booking->settings->teacherroleid);
    if ($inserted) {
        $event = \mod_booking\event\teacher_added::create(
                array('relateduserid' => $userid, 'objectid' => $optionid,
                    'context' => context_module::instance($cm->id)));
        $event->trigger();
    }

    return $inserted;
}

/**
 * Removes teacher from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $optionid
 * @param $cm
 */
function booking_optionid_unsubscribe($userid, $optionid, $cm) {
    global $DB;

    $event = \mod_booking\event\teacher_removed::create(
            array('relateduserid' => $userid, 'objectid' => $optionid,
                'context' => context_module::instance($cm->id)
            ));
    $event->trigger();

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
    $params['optionid'] = $optionid;
    $results = $DB->get_records_sql(
            "SELECT $fields
                    FROM {user} u
                    JOIN {booking_teachers} s ON s.userid = u.id
                    WHERE s.optionid = :optionid
                    ORDER BY u.email ASC", $params);

    // Guest user should never be subscribed to a forum.
    unset($results[$CFG->siteguest]);

    return $results;
}
