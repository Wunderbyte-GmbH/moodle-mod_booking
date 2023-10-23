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

global $CFG;

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/question/category_class.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->dirroot .'/course/externallib.php');

use local_entities\entitiesrelation_handler;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\booking_rules\rules_info;
use mod_booking\booking_utils;
use mod_booking\option\dates_handler;
use mod_booking\elective;
use mod_booking\output\coursepage_shortinfo_and_button;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;
use mod_booking\utils\wb_payment;

// Default fields for bookingoptions in view.php and for download.
define('BOOKINGOPTION_DEFAULTFIELDS', "identifier,titleprefix,text,description,teacher,responsiblecontact," .
"showdates,dayofweektime,location,institution,course,minanswers,bookings");

// Currently up to 9 different price categories can be set.
define('MAX_PRICE_CATEGORIES', 9);

// Currently up to 20 different semesters can be created.
define('MAX_SEMESTERS', 20);

// Time to confirm booking or cancellation in seconds.
define('TIME_TO_CONFIRM', 20);

// Define description parameters.
define('DESCRIPTION_WEBSITE', 1); // Shows link button with text "book now" and no link to TeamsMeeting etc.
define('DESCRIPTION_CALENDAR', 2); // Shows link button with text "go to bookingoption" and meeting links via link.php.
define('DESCRIPTION_ICAL', 3); // Shows link with text "go to bookingoption" and meeting links via link.php for iCal.
define('DESCRIPTION_MAIL', 4); // Shows link with text "go to bookingoption" and meeting links via link.php...
                            // ...for mail placeholder {bookingdetails}.
define('DESCRIPTION_OPTIONVIEW', 5); // Description for booking option preview page.

// Define message parameters.
define('MSGPARAM_CONFIRMATION', 1);
define('MSGPARAM_WAITINGLIST', 2);
define('MSGPARAM_REMINDER_PARTICIPANT', 3);
define('MSGPARAM_REMINDER_TEACHER', 4);
define('MSGPARAM_STATUS_CHANGED', 5);
define('MSGPARAM_CANCELLED_BY_PARTICIPANT', 6);
define('MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM', 7);
define('MSGPARAM_CHANGE_NOTIFICATION', 8);
define('MSGPARAM_POLLURL_PARTICIPANT', 9);
define('MSGPARAM_POLLURL_TEACHER', 10);
define('MSGPARAM_COMPLETED', 11);
define('MSGPARAM_SESSIONREMINDER', 12);
define('MSGPARAM_REPORTREMINDER', 13); // Reminder sent from report.php.
define('MSGPARAM_CUSTOM_MESSAGE', 14);

// Define booking status parameters.
define('STATUSPARAM_BOOKED', 0);
define('STATUSPARAM_WAITINGLIST', 1);
define('STATUSPARAM_RESERVED', 2);
define('STATUSPARAM_NOTIFYMELIST', 3); // Get message when place is open.
define('STATUSPARAM_NOTBOOKED', 4);
define('STATUSPARAM_DELETED', 5);

// Params to define behavior of booking_update_options.
define('UPDATE_OPTIONS_PARAM_DEFAULT', 1);
define('UPDATE_OPTIONS_PARAM_REDUCED', 2);
define('UPDATE_OPTIONS_PARAM_IMPORT', 3);

// Define message controller parameters.
define('MSGCONTRPARAM_SEND_NOW', 1);
define('MSGCONTRPARAM_QUEUE_ADHOC', 2);
define('MSGCONTRPARAM_DO_NOT_SEND', 3);
define('MSGCONTRPARAM_VIEW_CONFIRMATION', 4);

// Define booking availability condition ids.
define('BO_COND_ISLOGGEDINPRICE', 190);
define('BO_COND_ISLOGGEDIN', 180);
define('BO_COND_CONFIRMCANCEL', 170);
define('BO_COND_CANCELMYSELF', 105);
define('BO_COND_ALREADYBOOKED', 150);
define('BO_COND_ALREADYRESERVED', 140);
define('BO_COND_ISCANCELLED', 130);
define('BO_COND_ISBOOKABLE', 120);
define('BO_COND_ONWAITINGLIST', 110);
define('BO_COND_NOTIFYMELIST', 100);
define('BO_COND_FULLYBOOKED', 90);
define('BO_COND_MAX_NUMBER_OF_BOOKINGS', 80);
define('BO_COND_OPTIONHASSTARTED', 70);
define('BO_COND_BOOKING_TIME', 60);
define('BO_COND_BOOKINGPOLICY', 50);
define('BO_COND_SUBBOOKINGBLOCKS', 45);
define('BO_COND_SUBBOOKING', 40);
define('BO_COND_CAMPAIGN_BLOCKBOOKING', 35);

define('BO_COND_JSON_CUSTOMFORM', 16);
define('BO_COND_JSON_ENROLLEDINCOURSE', 15);
define('BO_COND_JSON_SELECTUSERS', 14);
define('BO_COND_JSON_PREVIOUSLYBOOKED', 13);
define('BO_COND_JSON_CUSTOMUSERPROFILEFIELD', 12);
define('BO_COND_JSON_USERPROFILEFIELD', 11);

define('BO_COND_ELECTIVENOTBOOKABLE', 10);
define('BO_COND_ELECTIVEBOOKITBUTTON', 9);

define('BO_COND_CONFIRMBOOKWITHSUBSCRIPTION', 8);
define('BO_COND_BOOKWITHSUBSCRIPTION', 7);

define('BO_COND_CONFIRMBOOKWITHCREDITS', 6);
define('BO_COND_BOOKWITHCREDITS', 5);

define('BO_COND_NOSHOPPINGCART', 4);
define('BO_COND_PRICEISSET', 3);

define('BO_COND_CONFIRMBOOKIT', 2);
define('BO_COND_BOOKITBUTTON', 1); // This is only used to show the book it button.
define('BO_COND_CONFIRMATION', 0); // This is the last page after booking.

// Define booking options status.
define('BO_STATUS_NORMAL', 0);
define('BO_STATUS_CANCELLED_AND_VISIBLE', 1);

// Define conditions parameters.
define('CONDPARAM_ALL', 0);
define('CONDPARAM_HARDCODED_ONLY', 1);
define('CONDPARAM_JSON_ONLY', 2);
define('CONDPARAM_MFORM_ONLY', 3);
define('CONDPARAM_CANBEOVERRIDDEN', 4);

// Define status for booking & subbooking options.
define('UNVERIFIED', 0);
define('PENDING', 1);
define('VERIFIED', 1);

// Define common bookin settings.
define('PAGINATIONDEF', 25);

// Define campaign types.
define('CAMPAIGN_TYPE_CUSTOMFIELD', 0);
define('CAMPAIGN_TYPE_BLOCKBOOKING', 1);

/**
 * @param stdClass $cm
 * @return cached_cm_info
 */
function booking_get_coursemodule_info($cm) {
    $info = new cached_cm_info();
    $booking = singleton_service::get_instance_of_booking_by_cmid($cm->id);
    $booking->apply_tags();
    if (!empty($booking->settings->name)) {
        $info->name = $booking->settings->name;
    }
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
function booking_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {

    // Check the contextlevel is as expected - if your plugin is a block.
    // We need context course if wee like to acces template files.
    if (!in_array($context->contextlevel, [CONTEXT_MODULE, CONTEXT_COURSE])) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'myfilemanager'
        && $filearea !== 'bookingimages'
        && $filearea !== 'myfilemanageroption'
        && $filearea !== 'bookingoptionimage'
        && $filearea !== 'signinlogoheader'
        && $filearea !== 'signinlogofooter'
        && $filearea !== 'templatefile') {
        return false;
    }

    // Make sure the user is logged in and has access to the module.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /* require_login($course, true, $cm); */

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
    send_stored_file($file, null, 0, true, $options);
}

function booking_user_outline($course, $user, $mod, $booking) {
    global $DB;
    if ($answer = $DB->get_record('booking_answers',
            ['bookingid' => $booking->id, 'userid' => $user->id])) {
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
            ["bookingid" => $booking->id, "userid" => $user->id])) {
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
function booking_comment_permissions($commentparam): array {
    global $DB, $USER;

    $odata = $DB->get_record('booking_options', ['id' => $commentparam->itemid]);
    $bdata = $DB->get_record('booking', ['id' => $odata->bookingid]);

    switch ($bdata->comments) {
        case 0:
            return ['post' => false, 'view' => false];
            break;
        case 1:
            return ['post' => true, 'view' => true];
            break;
        case 2:
            $udata = $DB->get_record('booking_answers',
                    ['userid' => $USER->id, 'optionid' => $commentparam->itemid]);
            if ($udata) {
                return ['post' => true, 'view' => true];
            } else {
                return ['post' => false, 'view' => true];
            }
            break;
        case 3:
            $udata = $DB->get_record('booking_answers',
                    ['userid' => $USER->id, 'optionid' => $commentparam->itemid]);
            if ($udata && $udata->completed == 1) {
                return ['post' => true, 'view' => true];
            } else {
                return ['post' => false, 'view' => true];
            }
            break;
    }
    return [];
}

/**
 * Validate comment parameter before perform other comments actions.
 *
 * @param stdClass $commentparam { context => context the context object
 *                                  courseid => int course id
 *                                  cm => stdClass course module object
 *                                  commentarea => string comment area
 *                                  itemid => int itemid }
 * @return boolean
 * @throws coding_exception
 * @throws comment_exception
 * @throws dml_exception
 * @category comment
 * @package mod_booking
 */
function booking_comment_validate(stdClass $commentparam): bool {
    global $DB;

    if ($commentparam->commentarea != 'booking_option') {
        throw new comment_exception('invalidcommentarea');
    }
    if (!$record = $DB->get_record('booking_options', ['id' => $commentparam->itemid])) {
        throw new comment_exception('invalidcommentitemid');
    }
    if ($record->id) {
        $booking = $DB->get_record('booking', ['id' => $record->bookingid]);
    }
    if (!$booking) {
        throw new comment_exception('invalidid', 'data');
    }
    if (!$course = $DB->get_record('course', ['id' => $booking->course])) {
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
    if (!($booking = $DB->get_record('booking', ['id' => $cm->instance]))) {
        throw new Exception("Can't find booking {$cm->instance}");
    }

    if ($booking->enablecompletion > 0) {
        $user = $DB->count_records('booking_answers',
                ['bookingid' => $booking->id, 'userid' => $userid, 'completed' => '1']);

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

    if (isset($booking->responsesfields) && is_array($booking->responsesfields) && count($booking->responsesfields) > 0) {
        $booking->responsesfields = implode(',', $booking->responsesfields);
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

    $booking->iselective = !empty($booking->iselective) ? $booking->iselective : 0;

    if (empty($booking->timerestrict)) {
        $booking->timeopen = $booking->timeclose = 0;
    }

    if (isset($booking->showviews) && is_array($booking->showviews) && count($booking->showviews) > 0) {
        $booking->showviews = implode(',', $booking->showviews);
    } else if (!isset($booking->showviews) || $booking->showviews === null) {
        $booking->showviews = '';
    }

    if (isset($booking->reportfields) && is_array($booking->reportfields) && count($booking->reportfields) > 0) {
        $booking->reportfields = implode(',', $booking->reportfields);
    }

    if (isset($booking->optionsfields) && is_array($booking->optionsfields) && count($booking->optionsfields) > 0) {
        $booking->optionsfields = implode(',', $booking->optionsfields);
    } else {
        $booking->optionsfields = BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (isset($booking->optionsdownloadfields) && is_array($booking->optionsdownloadfields)
        && count($booking->optionsdownloadfields) > 0) {
        $booking->optionsdownloadfields = implode(',', $booking->optionsdownloadfields);
    } else {
        $booking->optionsdownloadfields = BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (isset($booking->signinsheetfields) && is_array($booking->signinsheetfields)
        && count($booking->signinsheetfields) > 0) {
        $booking->signinsheetfields = implode(',', $booking->signinsheetfields);
    }

    // Copy the text fields out.
    $booking->bookedtext = $booking->bookedtext['text'] ?? $booking->bookedtext ?? null;
    $booking->notificationtext = $booking->notificationtext['text'] ?? $booking->notificationtext ?? null;
    $booking->waitingtext = $booking->waitingtext['text'] ?? $booking->waitingtext ?? null;
    $booking->notifyemail = $booking->notifyemail['text'] ?? $booking->notifyemail ?? null;
    $booking->notifyemailteachers = $booking->notifyemailteachers['text'] ?? $booking->notifyemailteachers ?? null;
    $booking->statuschangetext = $booking->statuschangetext['text'] ?? $booking->statuschangetext ?? null;
    $booking->deletedtext = $booking->deletedtext['text'] ?? $booking->deletedtext ?? null;
    $booking->bookingchangedtext = $booking->bookingchangedtext['text'] ?? $booking->bookingchangedtext ?? null;
    $booking->pollurltext = $booking->pollurltext['text'] ?? $booking->pollurltext ?? null;
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'] ?? $booking->pollurlteacherstext ?? null;
    $booking->activitycompletiontext = $booking->activitycompletiontext['text'] ?? $booking->activitycompletiontext ?? null;
    $booking->userleave = $booking->userleave['text'] ?? $booking->userleave ?? null;
    $booking->beforebookedtext = $booking->beforebookedtext['text'] ?? null;
    $booking->beforecompletedtext = $booking->beforecompletedtext['text'] ?? null;
    $booking->aftercompletedtext = $booking->aftercompletedtext['text'] ?? null;

    // If no policy was entered, we still have to check for HTML tags.
    if (!isset($booking->bookingpolicy) || empty(strip_tags($booking->bookingpolicy))) {
        $booking->bookingpolicy = '';
    }

    // Insert answer options from mod_form.
    $booking->id = $DB->insert_record("booking", $booking);

    $cmid = $booking->coursemodule;
    $context = context_module::instance($cmid);

    if ($draftitemid = file_get_submitted_draft_itemid('myfilemanager')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanager',
                $booking->id, ['subdirs' => false, 'maxfiles' => 50]);
    }

    if ($draftitemid = file_get_submitted_draft_itemid('bookingimages')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'bookingimages',
                $booking->id, ['subdirs' => false, 'maxfiles' => 500]);
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogoheader')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'signinlogoheader',
                $booking->id, ['subdirs' => false, 'maxfiles' => 1]);
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogofooter')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'signinlogofooter',
                $booking->id, ['subdirs' => false, 'maxfiles' => 1]);
    }

    if (isset($booking->tags)) {
        core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context, $booking->tags);
    }

    if (!empty($booking->option)) {
        foreach ($booking->option as $key => $value) {
            $value = trim($value);
            if (!empty($value)) {
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

    // When adding an instance, we need to invalidate the cache for booking instances.
    cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);

    // Also purge caches for options table and booking_option_settings.
    cache_helper::purge_by_event('setbackoptionstable');
    cache_helper::purge_by_event('setbackoptionsettings');

    return $booking->id;
}

/**
 * Given an object containing all the necessary data this will update an existing instance.
 *
 * @param stdClass|booking $booking
 * @return boolean
 */
function booking_update_instance($booking) {
    global $DB, $CFG;
    // We have to prepare the bookingclosingtimes as an $arrray, currently they are in $booking as $key (string).
    $booking->id = $booking->instance;
    $bookingid = $booking->id;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);

    $booking->timemodified = time();
    $cm = get_coursemodule_from_instance('booking', $booking->id);
    $context = context_module::instance($cm->id);

    if (isset($booking->showviews) && count($booking->showviews) > 0) {
        $booking->showviews = implode(',', $booking->showviews);
    } else {
        $booking->showviews = '';
    }

    if (isset($booking->responsesfields) && is_array($booking->responsesfields) && count($booking->responsesfields) > 0) {
        $booking->responsesfields = implode(',', $booking->responsesfields);
    }

    if (isset($booking->reportfields) && is_array($booking->reportfields) && count($booking->reportfields) > 0) {
        $booking->reportfields = implode(',', $booking->reportfields);
    }

    if (isset($booking->signinsheetfields) && is_array($booking->signinsheetfields) && count($booking->signinsheetfields) > 0) {
        $booking->signinsheetfields = implode(',', $booking->signinsheetfields);
    }

    if (isset($booking->templateid) && $booking->templateid > 0) {
        $booking->templateid = $booking->templateid;
    } else {
        $booking->templateid = 0;
    }

    $booking->iselective = !empty($booking->iselective) ? $booking->iselective : 0;

    if (isset($booking->optionsfields) && is_array($booking->optionsfields) && count($booking->optionsfields) > 0) {
        $booking->optionsfields = implode(',', $booking->optionsfields);
    } else {
        $booking->optionsfields = BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (isset($booking->optionsdownloadfields) && is_array($booking->optionsdownloadfields)
        && count($booking->optionsdownloadfields) > 0) {
        $booking->optionsdownloadfields = implode(',', $booking->optionsdownloadfields);
    } else {
        $booking->optionsdownloadfields = BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    } else {
        $booking->categoryid = null;
    }

    if (empty($booking->assessed)) {
        $booking->assessed = 0;
    }

    if (empty($booking->ratingtime) || empty($booking->assessed)) {
        $booking->assesstimestart = 0;
        $booking->assesstimefinish = 0;
    }

    $arr = [];
    core_tag_tag::set_item_tags('mod_booking', 'booking', $booking->id, $context, $booking->tags);

    file_save_draft_area_files($booking->signinlogoheader, $context->id, 'mod_booking',
            'signinlogoheader', $booking->id, ['subdirs' => false, 'maxfiles' => 1]);

    file_save_draft_area_files($booking->signinlogofooter, $context->id, 'mod_booking',
            'signinlogofooter', $booking->id, ['subdirs' => false, 'maxfiles' => 1]);

    file_save_draft_area_files($booking->myfilemanager, $context->id, 'mod_booking',
            'myfilemanager', $booking->id, ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 50]);

    file_save_draft_area_files($booking->bookingimages, $context->id, 'mod_booking',
            'bookingimages', $booking->id, ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 500]);

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

    // If no policy was entered, we still have to check for HTML tags.
    // NOTE: $booking->bookingpolicy is a string! So we never use ['text'] here!
    if (!isset($booking->bookingpolicy) || empty(strip_tags($booking->bookingpolicy))) {
        $booking->bookingpolicy = '';
    }

    $booking->bookedtext = $booking->bookedtext['text'];
    $booking->waitingtext = $booking->waitingtext['text'];
    $booking->notifyemail = $booking->notifyemail['text'];
    $booking->notifyemailteachers = $booking->notifyemailteachers['text'];
    $booking->statuschangetext = $booking->statuschangetext['text'];
    $booking->deletedtext = $booking->deletedtext['text'];
    $booking->bookingchangedtext = $booking->bookingchangedtext['text'];
    $booking->pollurltext = $booking->pollurltext['text'];
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'];
    $booking->activitycompletiontext = $booking->activitycompletiontext['text'];
    $booking->userleave = $booking->userleave['text'];

    // Get JSON from bookingsettings.
    $booking->json = $bookingsettings->json;

    // We store the information if a booking option can be cancelled in the JSON.
    // So this has to happen BEFORE JSON is saved!
    if (empty($booking->disablecancel)) {
        // This will store the correct JSON to $optionvalues->json.
        booking::remove_key_from_json($booking, "disablecancel");
    } else {
        booking::add_data_to_json($booking, "disablecancel", 1);
    }

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
                if (!empty($value)) {
                    $DB->update_record("booking_options", $option);
                } else { // Empty old option - needs to be deleted.
                    $DB->delete_records("booking_options", ["id" => $option->id]);
                }
            } else {
                if (!empty($value)) {
                    $DB->insert_record("booking_options", $option);
                }
            }
        }
    }

    booking_grade_item_update($booking);

    $oldrecord = $DB->get_record('booking', ['id' => $booking->id]);
    $changes = booking::booking_instance_get_changes($oldrecord, $booking);

    // We trigger the right event.
    $event = \mod_booking\event\bookinginstance_updated::create([
        'context' => $context,
        'objectid' => $cm->id,
        'other' => [
            'changes' => $changes ?? '',
        ],
    ]);
    $event->trigger();

    // When updating an instance, we need to invalidate the cache for booking instances.
    cache_helper::invalidate_by_event('setbackbookinginstances', [$cm->id]);

    // Also purge caches for options table, semesters and booking_option_settings.
    cache_helper::purge_by_event('setbackoptionstable');
    cache_helper::purge_by_event('setbacksemesters');
    cache_helper::purge_by_event('setbackoptionsettings');

    // We also need to set back Wunderbyte table cache!
    cache_helper::purge_by_event('setbackencodedtables');
    cache_helper::purge_by_event('setbackeventlogtable');

    return $DB->update_record('booking', $booking);
}

/**
 * Update the booking option settings when adding and modifying a single booking option.
 *
 * @param object $optionvalues
 * @param context_module $context
 * @param int $updateparam optional param to define behavior
 * @return boolean|number optionid
 */
function booking_update_options(object $optionvalues, context_module $context, int $updateparam = UPDATE_OPTIONS_PARAM_DEFAULT) {
    global $DB, $CFG, $PAGE, $USER;

    require_once("$CFG->dirroot/mod/booking/locallib.php");
    require_once("{$CFG->dirroot}/mod/booking/classes/GoogleUrlApi.php");

    $option = new stdClass();
    $option->bookingid = $optionvalues->bookingid;

    $customfields = booking_option::get_customfield_settings();
    if (!($booking = $DB->get_record('booking', ['id' => $optionvalues->bookingid]))) {
        $booking = new stdClass();
        $booking->id = 0;
    }

    // Get the original option to compare it for changes.
    if (!empty($optionvalues->optionid) &&
            $optionvalues->optionid != -1) {
        if (!$originaloption = $DB->get_record('booking_options', ['id' => $optionvalues->optionid])) {
            $originaloption = false;
        }
    }

    // Bugfix: Use !empty instead of isset to check for 0 too.
    if (!empty($optionvalues->courseid)) {
        $option->courseid = $optionvalues->courseid;
    } else {
        $option->courseid = 0;
    }

    // For global option templates, 0 is used as bookingid.
    if (isset($optionvalues->addastemplate) && $optionvalues->addastemplate == 1) {
        $option->bookingid = 0;
    }

    if (isset($optionvalues->parentid)) {
        $option->parentid = $optionvalues->parentid;
    }

    // Set semesterid and dayofweektime string which we got from the dynamic form (see editoptions.php).
    if (!empty($optionvalues->semesterid)) {
        $option->semesterid = $optionvalues->semesterid;
    }

    if (!empty($optionvalues->dayofweektime)) {
        // Possible improvement: We could add the dates_handler::reoccurring_datestring_is_correct check here...
        // ...and only store if the string is correct, if this is needed.
        $option->dayofweektime = $optionvalues->dayofweektime;

        // This is only for sql filtering, but we need the weekday in an extra column.

        $dayinfo = dates_handler::prepare_day_info($optionvalues->dayofweektime);
        if (!empty($dayinfo['day'])) {
            $option->dayofweek = $dayinfo['day'];
        }

    }

    // Prefix to be shown before title of the booking option.
    if (empty($optionvalues->titleprefix)) {
        $option->titleprefix = '';
    } else {
        $option->titleprefix = $optionvalues->titleprefix;
    }

    // Unique identifier of the booking option.
    if (empty($optionvalues->identifier)) {
        $option->identifier = booking_option::create_truly_unique_option_identifier();
    } else {
        $option->identifier = $optionvalues->identifier;
    }

    // Customizable availability conditions (json).
    // Make sure json availability conditions are saved, if they exist.
    if (!empty($optionvalues->availability)) {
        $option->availability = $optionvalues->availability;
    } else {
        $option->availability = null;
    }

    // Title of the booking option.
    $option->text = trim($optionvalues->text);

    if (!isset($optionvalues->howmanyusers) || empty($optionvalues->howmanyusers)) {
        $option->howmanyusers = 0;
    } else {
        $option->howmanyusers = $optionvalues->howmanyusers;
    }
    if (!isset($optionvalues->removeafterminutes) || empty($optionvalues->removeafterminutes)) {
        $option->removeafterminutes = 0;
    } else {
        $option->removeafterminutes = $optionvalues->removeafterminutes;
    }
    if (!isset($optionvalues->notificationtext) || empty($optionvalues->notificationtext)) {
        $option->notificationtext = "";
    } else {
        $option->notificationtext = $optionvalues->notificationtext;
    }
    if (empty($optionvalues->disablebookingusers)) {
        $option->disablebookingusers = 0;
    } else {
        $option->disablebookingusers = $optionvalues->disablebookingusers;
    }

    $option->sent = 0;
    $option->sent2 = 0;
    $option->sentteachers = 0;

    if (isset($optionvalues->location)) {
        $option->location = trim($optionvalues->location);
    } else {
        $option->location = '';
    }

    if (isset($optionvalues->institution)) {
        $option->institution = trim($optionvalues->institution);
    } else {
        $option->institution = '';
    }

    if (isset($optionvalues->address)) {
        $option->address = trim($optionvalues->address);
    } else {
        $option->address = '';
    }

    // Visibility of the option.
    if (isset($optionvalues->invisible)) {
        $option->invisible = $optionvalues->invisible;
    } else {
        $option->invisible = 0;
    }

    // Annotation field for internal remarks.
    if (empty($optionvalues->annotation)) {
        $option->annotation = "";
    } else {
        $option->annotation = $optionvalues->annotation;
    }

    // Absolute value to be added to price calculation with formula.
    if (isset($optionvalues->priceformulaadd)) {
        $option->priceformulaadd = $optionvalues->priceformulaadd;
    } else {
        $option->priceformulaadd = 0; // Default: Add 0.
    }

    // Manual factor to be applied to price calculation with formula.
    if (isset($optionvalues->priceformulamultiply)) {
        $option->priceformulamultiply = $optionvalues->priceformulamultiply;
    } else {
        $option->priceformulamultiply = 1; // Default: Multiply with 1.
    }

    // Flag if price formula is turned on or off.
    if (isset($optionvalues->priceformulaoff)) {
        $option->priceformulaoff = $optionvalues->priceformulaoff;
    } else {
        $option->priceformulaoff = 0; // Default: Turned on.
    }

    // Link to feedback form.
    if (isset($optionvalues->pollurl)) {
        $option->pollurl = $optionvalues->pollurl;
    } else {
        $option->pollurl = '';
    }

    // Link to teachers' feedback form.
    if (isset($optionvalues->pollurlteachers)) {
        $option->pollurlteachers = $optionvalues->pollurlteachers;
    } else {
        $option->pollurlteachers = '';
    }

    // Responsible contact person.
    if (!empty($optionvalues->responsiblecontact)) {
        $option->responsiblecontact = $optionvalues->responsiblecontact;
    } else {
        $option->responsiblecontact = null;
    }

    if (isset($optionvalues->limitanswers)) {
        $option->limitanswers = $optionvalues->limitanswers;
    } else {
        $option->limitanswers = 0;
    }
    if (isset($optionvalues->limitanswers) && $optionvalues->limitanswers == 0) {
        $option->limitanswers = 0;
        $option->maxanswers = 0;
        $option->maxoverbooking = 0;
    } else {
        if (isset($optionvalues->maxanswers)) {
            $option->maxanswers = $optionvalues->maxanswers;
        } else {
            $option->maxanswers = 0;
        }
        if (isset($optionvalues->maxoverbooking)) {
            $option->maxoverbooking = $optionvalues->maxoverbooking;
        } else {
            $option->maxoverbooking = 0;
        }
    }

    if (isset($optionvalues->minanswers)) {
        $option->minanswers = $optionvalues->minanswers;
    } else {
        $option->minanswers = 0;
    }

    if (isset($optionvalues->restrictanswerperiodopening) && !empty($optionvalues->bookingopeningtime)) {
        $option->bookingopeningtime = $optionvalues->bookingopeningtime;
    } else {
        $option->bookingopeningtime = 0;
    }

    if (isset($optionvalues->restrictanswerperiodclosing) && !empty($optionvalues->bookingclosingtime)) {
        $option->bookingclosingtime = $optionvalues->bookingclosingtime;
    } else {
        $option->bookingclosingtime = 0;
    }

    if (isset($optionvalues->startendtimeknown)) {
        $option->coursestarttime = $optionvalues->coursestarttime ?? 0;
        $option->courseendtime = $optionvalues->courseendtime ?? 0;
    } else {
        $option->coursestarttime = 0;
        $option->courseendtime = 0;
    }

    if (isset($optionvalues->enrolmentstatus)) {
        $option->enrolmentstatus = $optionvalues->enrolmentstatus;
    } else {
        $option->enrolmentstatus = 0;
    }

    if (isset($optionvalues->description)) {
        $option->description = trim($optionvalues->description);
    } else {
        $option->description = "";
    }

    // We add the json key only if there is actually something committed.
    // The reason is that this comes from a different form.
    if (!empty($optionvalues->json)) {
        $option->json = $optionvalues->json;
    } else {
        $option->json = json_encode(new stdClass);
    }

    // We store the information if a booking option can be cancelled in the JSON.
    // So this has to happen BEFORE JSON is saved!
    if (empty($optionvalues->disablecancel)) {
        // This will store the correct JSON to $optionvalues->json.
        booking_option::remove_key_from_json($option, "disablecancel");
    } else {
        booking_option::add_data_to_json($option, "disablecancel", 1);
    }

    if (isset($optionvalues->beforebookedtext)) {
        $option->beforebookedtext = $optionvalues->beforebookedtext;
    } else {
        $option->beforebookedtext = "";
    }

    if (isset($optionvalues->beforecompletedtext)) {
        $option->beforecompletedtext = $optionvalues->beforecompletedtext;
    } else {
        $option->beforecompletedtext = "";
    }

    if (isset($optionvalues->aftercompletedtext)) {
        $option->aftercompletedtext = $optionvalues->aftercompletedtext;
    } else {
        $option->aftercompletedtext = "";
    }

    if ((empty($optionvalues->duration) || $optionvalues->duration == 0)
        && (isset($optionvalues->coursestarttime)
    && isset($optionvalues->courseendtime))
    && $delta = $optionvalues->courseendtime - $optionvalues->coursestarttime) {
        $option->duration = $delta;
    } else {
        if (isset($optionvalues->duration)) {
            $option->duration = $optionvalues->duration;
        } else {
            $option->duration = 0;
        }
    }

    $option->timemodified = time();

    // Add to calendar option.
    if (isset($optionvalues->addtocalendar) && $optionvalues->addtocalendar == 1) {
        // 1 ... Add to calendar as COURSE event.
        $option->addtocalendar = 1;
    } else if (isset($optionvalues->addtocalendar) && $optionvalues->addtocalendar == 2) {
        // 2 ... Add to calendar as SITE event.
        $option->addtocalendar = 2;
    } else {
        // 0 ... Do not add to calendar.
        $option->addtocalendar = 0;
    }

    // Every time we save an entity, we want to make sure that the name of the entity is stored in location.
    if (!empty($optionvalues->local_entities_entityid)) {
        // We might have more than one address, this will lead to more than one record which comes back.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $entities = entitiesrelation_handler::get_entities_by_id($optionvalues->local_entities_entityid);
            $option->address = '';
            foreach ($entities as $entity) {
                $option->location = $entity->parentname ?? $entity->name;
                $option->address .= "$entity->postcode $entity->city $entity->streetname $entity->streetnumber";
                if (count($entities) > 1) {
                    $option->address .= ', ';
                }
            }
            if (count($entities) > 1) {
                $option->address = substr($option->address, 0, -2);
            }
        };
    }

    /* Create a new course and put it either in a new course category
       or in an already existing one. */
    if ($option->courseid == -1) {
        $categoryid = 1; // By default, we use the first category.
        if (!empty(get_config('booking', 'newcoursecategorycfield'))) {
            // FEATURE add more settingfields add customfield_ to settingsvalue from customfields allwo only Textfields or Selects.
            $cfforcategory = 'customfield_' . get_config('booking', 'newcoursecategorycfield');
            $category = new stdClass();
            $category->name = $_POST[$cfforcategory];

            if (!empty($category->name)) {
                $categories = core_course_external::get_categories([
                        ['key' => 'name', 'value' => $category->name],
                ]);

                if (empty($categories)) {
                    $category->idnumber = $category->name;
                    $categories = [
                            ['name' => $category->name, 'idnumber' => $category->idnumber, 'parent' => 0],
                    ];
                    $createdcats = core_course_external::create_categories($categories);
                    $categoryid = $createdcats[0]['id'];
                } else {
                    $categoryid = $categories[0]['id'];
                }
            }
        }

        // Create course.
        $fullnamewithprefix = '';
        if (!empty($option->titleprefix)) {
            $fullnamewithprefix .= $option->titleprefix . ' - ';
        }
        $fullnamewithprefix .= $option->text;

        // Courses need to have unique shortnames.
        $i = 1;
        $shortname = $fullnamewithprefix;
        while ($DB->get_record('course', ['shortname' => $shortname])) {
            $shortname = $fullnamewithprefix . '_' . $i;
            $i++;
        };
        $newcourse['fullname'] = $fullnamewithprefix;
        $newcourse['shortname'] = $shortname;
        $newcourse['categoryid'] = $categoryid;

        $courses = [$newcourse];
        $createdcourses = core_course_external::create_courses($courses);
        $option->courseid = $createdcourses[0]['id'];
        $optionvalues->courseid = $option->courseid;
    }

    // Existing booking option record.
    if (!empty($optionvalues->optionid) && $optionvalues->optionid != -1) {

        $option->id = $optionvalues->optionid;

        // We do nothing if the booking option has no name.
        if (isset($optionvalues->text) && $optionvalues->text != '') {

            if (isset($optionvalues->shorturl)) {
                $option->shorturl = $optionvalues->shorturl;
            } else {
                $option->shorturl = '';
            }

            $option->credits = $optionvalues->credits ?? 0;
            $option->sortorder = $optionvalues->sortorder ?? 0;

            $option->calendarid = $DB->get_field('booking_options', 'calendarid',
                    ['id' => $option->id]);
            $coursestarttime = $DB->get_field('booking_options', 'coursestarttime',
                    ['id' => $option->id]);

            if ($coursestarttime != $optionvalues->coursestarttime) {
                $option->sent = 0;
                $option->sent2 = 0;
                $option->sentteachers = 0;
            } else {
                $option->sent = $DB->get_field('booking_options', 'sent',
                        ['id' => $option->id]);
                $option->sent2 = $DB->get_field('booking_options', 'sent2',
                        ['id' => $option->id]);
                $option->sentteachers = $DB->get_field('booking_options', 'sentteachers',
                        ['id' => $option->id]);
            }

            // Save the additional JSON conditions (the ones which have been added to the mform).
            bo_info::save_json_conditions_from_form($optionvalues);
            $option->availability = $optionvalues->availability;

            // This is the default behavior but we do not want this when using other update params.
            if ($updateparam == UPDATE_OPTIONS_PARAM_DEFAULT || $updateparam == UPDATE_OPTIONS_PARAM_IMPORT) {
                // Elective.
                // Save combination arrays to DB.
                if (!empty($booking->iselective)) {
                    elective::addcombinations($option->id, $optionvalues->mustcombine, 1);
                    elective::addcombinations($option->id, $optionvalues->mustnotcombine, 0);
                }

                if (!empty($booking->addtogroup) && $option->courseid > 0) {
                    $bo = singleton_service::get_instance_of_booking_option($context->instanceid, $option->id);
                    $bo->option->courseid = $option->courseid;
                    $option->groupid = $bo->create_group();
                    $booked = $bo->get_all_users_booked();
                    if (!empty($booked) && $booking->autoenrol) {
                        foreach ($booked as $bookinganswer) {
                            $bo->enrol_user($bookinganswer->userid);
                        }
                    }
                }

                // This is needed to create option dates with the webservice importer.
                deal_with_multisessions($optionvalues, $booking, $option->id, $context);

                // Check if custom field will be updated or newly created.
                if (!empty($customfields)) {
                    foreach ($customfields as $fieldcfgname => $field) {
                        if (!empty($optionvalues->$fieldcfgname)) {
                            $customfieldid = $DB->get_field(
                                    'booking_customfields',
                                    'id',
                                    [
                                        'bookingid' => $booking->id,
                                        'optionid' => $option->id,
                                        'cfgname' => $fieldcfgname,
                                    ]);
                            if ($customfieldid) {
                                $customfield = new stdClass();
                                $customfield->id = $customfieldid;

                                if (is_array($optionvalues->$fieldcfgname)) {
                                    $customfield->value = implode("\n", $optionvalues->$fieldcfgname);
                                } else {
                                    $customfield->value = $optionvalues->$fieldcfgname;
                                }

                                $DB->update_record('booking_customfields', $customfield);
                            } else {
                                $customfield = new stdClass();

                                if (is_array($optionvalues->$fieldcfgname)) {
                                    $customfield->value = implode("\n", $optionvalues->$fieldcfgname);
                                } else {
                                    $customfield->value = $optionvalues->$fieldcfgname;
                                }

                                $customfield->optionid = $option->id;
                                $customfield->bookingid = $booking->id;
                                $customfield->cfgname = $fieldcfgname;
                                $DB->insert_record('booking_customfields', $customfield);
                            }
                        }
                    }
                }
            }

            // Save the changes to DB.
            $DB->update_record("booking_options", $option);

            // This is the default behavior but we do not want this when using other update params.
            if ($updateparam == UPDATE_OPTIONS_PARAM_DEFAULT || $updateparam == UPDATE_OPTIONS_PARAM_IMPORT) {
                if (!empty($booking->addtogroup) && $option->courseid > 0) {
                    $bo = singleton_service::get_instance_of_booking_option($context->instanceid, $option->id);
                    $bo->option->courseid = $option->courseid;
                    $option->groupid = $bo->create_group();
                    $booked = $bo->get_all_users_booked();
                    if (!empty($booked) && $booking->autoenrol) {
                        foreach ($booked as $bookinganswer) {
                            $bo->enrol_user_coursestart($bookinganswer->userid);
                        }
                    }
                }

                // If there have been changes to significant fields, we have to resend an e-mail with the updated ical attachment.
                $bu = new booking_utils();
                if ($changes = $bu->booking_option_get_changes($originaloption, $option)) {

                    // Fix a bug where $PAGE->cm->id is not set for webservice importer.
                    if (!empty($PAGE->cm->id)) {
                        $cmid = $PAGE->cm->id;
                    } else {
                        $cm = context_module::instance($context->instanceid);
                        if (!empty($cm->id)) {
                            $cmid = $cm->id;
                        }
                    }
                    // If we have no cmid, it's most possibly a template.
                    if (!empty($cmid) && $option->bookingid != 0) {
                        // We only react on changes, if a cmid exists.
                        $bu->react_on_changes($cmid, $context, $option->id, $changes);
                    }
                }
            }
        }

        // This is the default behavior but we do not want this when using other update params.
        if ($updateparam == UPDATE_OPTIONS_PARAM_DEFAULT || $updateparam == UPDATE_OPTIONS_PARAM_IMPORT) {

            // Update start and end date of the option depending on the sessions.
            booking_updatestartenddate($option->id);

            $optiondateshandler = new dates_handler($optionvalues->optionid, $optionvalues->bookingid);
            if (!empty($optionvalues->newoptiondates) || !empty($optionvalues->stillexistingdates)) {
                // Save the optiondates.
                $optiondateshandler->save_from_form($optionvalues);
            } else {
                // Delete optiondates.
                $optiondateshandler->delete_all_option_dates();
            }

            // Save teachers using handler.
            $teachershandler = new teachers_handler($option->id);
            $teachershandler->save_from_form($optionvalues);

            // Save relation for each newly created optiondate if checkbox is active.
            $isimport = $updateparam == UPDATE_OPTIONS_PARAM_IMPORT ? true : false; // For import we need to force this!
            save_entity_relations_for_optiondates_of_option($optionvalues, $option->id, $isimport);
        }

        // We need to purge cache after updating an option.
        booking_option::purge_cache_for_option($option->id);

        // Now check, if there are rules to execute.
        rules_info::execute_rules_for_option($option->id);

        return $option->id;
    } else if (!empty($optionvalues->text)) {
        // New booking option record.

        // If option "Use as global template" has been set.
        if (isset($optionvalues->addastemplate) && $optionvalues->addastemplate == 1) {

            // 1) count the number of booking options templates.
            $optiontemplatesdata = $DB->get_records("booking_options", ['bookingid' => 0]);
            $numberofoptiontemplates = count($optiontemplatesdata);

            // 2) if the user has not activated a valid PRO license, then only allow one booking option.
            if ($numberofoptiontemplates > 0 && !wb_payment::pro_version_is_activated()) {
                $dbrecord = $DB->get_record("booking_options", ['text' => $option->text]);
                if (empty($dbrecord)) {
                    return 'BOOKING_OPTION_NOT_CREATED';
                }
            }
        }

        // Make sure it's no template by checking if bookingid is something else than 0.
        if ($option->bookingid != 0) {
            // A new booking option is added.
            $optionid = $DB->insert_record("booking_options", $option);
        } else {
            // Add as template.
            // Fixed: For templates, make sure they won't get inserted twice.
            $dbrecord = $DB->get_record("booking_options",
                    ['text' => $option->text, 'bookingid' => $option->bookingid]);
            if (empty($dbrecord)) {
                $optionid = $DB->insert_record("booking_options", $option);
            } else {
                $optionid = $dbrecord->id;
            }
        }

        // Elective.
        // Save combination arrays to DB.
        if (!empty($booking->iselective)) {
            elective::addcombinations($optionid, $optionvalues->mustcombine, 1);
            elective::addcombinations($optionid, $optionvalues->mustnotcombine, 0);
        }

        $option->credits = $optionvalues->credits ?? 0;
        $option->sortorder = $optionvalues->sortorder ?? 0;

        // Create group in target course if there is a course specified only.
        if (!empty($option->courseid) && !empty($booking->addtogroup)) {
            $option->id = $optionid;
            $bo = singleton_service::get_instance_of_booking_option($context->instanceid, $optionid);
            $option->groupid = $bo->create_group($booking, $option);
            $DB->update_record('booking_options', $option);
        }

        $option->shorturl = '';

        $event = \mod_booking\event\bookingoption_created::create([
            'context' => $context,
            'objectid' => $optionid,
            'relateduserid' => $USER->id,
        ]);
        $event->trigger();

        // Save custom fields if there are any.
        if (!empty($customfields)) {
            foreach ($customfields as $fieldcfgname => $field) {
                if (!empty($optionvalues->$fieldcfgname)) {
                    $customfield = new stdClass();

                    if (is_array($optionvalues->$fieldcfgname)) {
                        $customfield->value = implode("\n", $optionvalues->$fieldcfgname);
                    } else {
                        $customfield->value = $optionvalues->$fieldcfgname;
                    }

                    $customfield->optionid = $optionid;
                    $customfield->bookingid = $booking->id;
                    $customfield->cfgname = $fieldcfgname;
                    $DB->insert_record('booking_customfields', $customfield);
                }
            }
        }

        // Fixed: Also create optiondates for new options!
        if (!empty($optionvalues->newoptiondates) || !empty($optionvalues->stillexistingdates) && !empty($optionid)) {
            // Save the optiondates.
            $optiondateshandler = new dates_handler($optionid, $optionvalues->bookingid);
            $optiondateshandler->save_from_form($optionvalues);
        }

        $doenrol = true;
        // If it's a duplicate, we also duplicate referenced values like teachers, entities and customfields!
        if (!empty($optionvalues->copyoptionid) && $optionvalues->copyoptionid > 0) {
            $doenrol = false; // For a duplicate, we do not want to enrol the teachers right away...
            // ...as we most possibly will change the Moodle course in the duplicate.
            $copyoptionsettings = singleton_service::get_instance_of_booking_option_settings($optionvalues->copyoptionid);
            $optionvalues->teachersforoption = $copyoptionsettings->teacherids;
            // If there was an associated entity, also copy it.
            if (class_exists('local_entities\entitiesrelation_handler')) {
                $erhandler = new entitiesrelation_handler('mod_booking', 'option');
                $entityid = $erhandler->get_entityid_by_instanceid($optionvalues->copyoptionid);
                if ($entityid) {
                    $erhandler->save_entity_relation($optionid, $entityid);
                }
            }
            // If there are prices defined, let's duplicate them too.
            if (get_config('booking', 'duplicationrestoreprices')) {
                /* IMPORTANT: Once we support subbookings, we might have different areas than 'option'
                    and this means 'itemid' might be something else than an optionid.
                    So we have to find out, if we still can set the params like this. */
                $prices = $DB->get_records('booking_prices', ['itemid' => $optionvalues->copyoptionid, 'area' => 'option']);
                foreach ($prices as $price) {
                    $price->itemid = $optionid;
                }
                $DB->insert_records('booking_prices', $prices);
            }
            // Also duplicate associated Moodle custom fields (e.g. "sports").
            $sql = "SELECT cfd.*
                    FROM {customfield_data} cfd
                    LEFT JOIN {customfield_field} cff
                    ON cff.id = cfd.fieldid
                    LEFT JOIN {customfield_category} cfc
                    ON cfc.id = cff.categoryid
                    WHERE cfc.component = 'mod_booking'
                    AND cfd.instanceid = :oldoptionid";

            $params = [
                    'oldoptionid' => $optionvalues->copyoptionid,
            ];

            $now = time();
            $oldcustomfields = $DB->get_records_sql($sql, $params);
            foreach ($oldcustomfields as $cf) {
                unset($cf->id);
                $cf->timecreated = $now;
                $cf->timemodified = $now;
                $cf->instanceid = $optionid;
                $DB->insert_record('customfield_data', $cf);
            }

            // We also need to duplicate subbookings of the booking option.
            $sql = "SELECT *
                    FROM {booking_subbooking_options}
                    WHERE optionid = :oldoptionid";
            $oldsubbookings = $DB->get_records_sql($sql, $params);
            foreach ($oldsubbookings as $sb) {
                unset($sb->id);
                $sb->usermodified = $USER->id;
                $sb->timecreated = $now;
                $sb->timemodified = $now;
                $sb->optionid = $optionid;
                $DB->insert_record('booking_subbooking_options', $sb);
            }
        }

        // We only save teachers if there are any.
        if (!empty($optionvalues->teachersforoption)) {
            // Save teachers using handler.
            $teachershandler = new teachers_handler($optionid);
            $teachershandler->save_from_form($optionvalues, $doenrol);
        }

        // Deal with multiple option dates (multisessions).
        deal_with_multisessions($optionvalues, $booking, $optionid, $context);

        // Update start and end date of the option depending on the sessions.
        booking_updatestartenddate($optionid);

        // Save relation for each newly created optiondate if checkbox is active.
        $isimport = $updateparam == UPDATE_OPTIONS_PARAM_IMPORT ? true : false; // For import we need to force this!
        save_entity_relations_for_optiondates_of_option($optionvalues, $optionid, $isimport);

        // Save the additional JSON conditions (the ones which have been added to the mform).
        bo_info::save_json_conditions_from_form($optionvalues);
        $option->availability = $optionvalues->availability ?? null;

        // Trigger an event that booking option has been updated - only if it is NOT a template.
        if (!isset($optionvalues->addastemplate) || $optionvalues->addastemplate == 0) {
            $event = \mod_booking\event\bookingoption_updated::create(
                    [
                            'context' => $context,
                            'objectid' => $optionid,
                            'userid' => $USER->id,
                            'other' => [
                                'changes' => $changes ?? '',
                            ],
                    ]
            );
            $event->trigger();

            cache_helper::purge_by_event('setbackeventlogtable');

        }
        // Finally, we need to check if any existing booking rules are affected.
        if ($option->bookingid != 0) {
            rules_info::execute_rules_for_option($optionid);
        }

        // At the very last moment, we invalidate caches.
        booking_option::purge_cache_for_option($optionid);

        return $optionid;
    }
}

/**
 * Helper function to save entity relations for all associated optiondates.
 * @param stdClass &$optionvalues option values from form
 * @param int $optionid
 * @param bool $isimport for CSV or webservice import this needs to be true
 * */
function save_entity_relations_for_optiondates_of_option(stdClass &$optionvalues, int $optionid, bool $isimport = false) {
    global $DB;
    if (class_exists('local_entities\entitiesrelation_handler')
            && (!empty($optionvalues->er_saverelationsforoptiondates) || $isimport)) {

        $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');

        $entities = [];
        if ($isimport) {
            // If we import we still need to fetch the entity from the location field value.
            // It can be either the entity name or the entity id.
            if (!empty($optionvalues->location)) {
                if (is_numeric($optionvalues->location)) {
                    // It's the entity id.
                    $entities = $erhandler->get_entities_by_id($optionvalues->location);
                } else {
                    // It's the entity name (NOT shortname).
                    $entities = $erhandler->get_entities_by_name($optionvalues->location);
                }
                // If we have exactly one entity, we create the entities entry.
                if (count($entities) === 1) {
                    $entity = reset($entities);
                    $optionvalues->local_entities_entityid = $entity->id;
                }
            }
        }

        $optiondateids = $DB->get_fieldset_sql(
            "SELECT id FROM {booking_optiondates} WHERE optionid = :optionid",
            ['optionid' => $optionid]
        );
        foreach ($optiondateids as $optiondateid) {
            if (!empty($optionvalues->local_entities_entityid)) {
                $erhandler->save_entity_relation($optiondateid, $optionvalues->local_entities_entityid);
            } else {
                // If entity was deleted from the option, we delete it form optiondates too.
                $erhandler->delete_relation($optiondateid);
            }
        }
    }
}

/**
 * Helper function to deal with the creation of multisessions (optiondates).
 */
function deal_with_multisessions(&$optionvalues, $booking, $optionid, $context) {

    global $DB;

    // Deal with new optiondates (Multisessions).
    // TODO: We should have an optiondates class to deal with all of this.
    // As of now, we do it the hacky way.
    for ($i = 1; $i < 100; ++$i) {

        $starttimekey = 'ms' . $i . 'starttime';
        $endtimekey = 'ms' . $i . 'endtime';
        $daystonotify = 'ms' . $i . 'nt';

        if (!empty($optionvalues->$starttimekey) && !empty($optionvalues->$endtimekey)) {
            $optiondate = new stdClass();
            $optiondate->bookingid = $booking->id;
            $optiondate->optionid = $optionid;
            $optiondate->coursestarttime = $optionvalues->$starttimekey;
            $optiondate->courseendtime = $optionvalues->$endtimekey;
            if (!empty($optionvalues->$daystonotify)) {
                $optiondate->daystonotify = $optionvalues->$daystonotify;
            }
            $dateshandler = new dates_handler($optionid, $booking->id);
            $optiondateid = $dateshandler->create_option_date($optiondate);

            for ($j = 1; $j < 4; ++$j) {
                $cfname = 'ms' . $i . 'cf' . $j . 'name';
                $cfvalue = 'ms' . $i . 'cf'. $j . 'value';

                if (!empty($optionvalues->$cfname)
                    && !empty($optionvalues->$cfvalue)) {

                    $customfield = new stdClass();
                    $customfield->bookingid = $booking->id;
                    $customfield->optionid = $optionid;
                    $customfield->optiondateid = $optiondateid;
                    $customfield->cfgname = $optionvalues->$cfname;
                    $customfield->value = $optionvalues->$cfvalue;
                    $DB->insert_record("booking_customfields", $customfield);
                }
            }
        }
    }
}

/**
 * Extend booking user navigation
 */
function booking_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if ($iscurrentuser) {
        $url = new moodle_url('/mod/booking/mybookings.php');
        $string = get_string('mybookingoptions', 'mod_booking');
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
    global $PAGE, $DB;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = $cm->context;
    $course = $PAGE->course;
    $optionid = $PAGE->url->get_param('optionid');

    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cm->id);

    $bookingisteacher = false; // Set to false by default.
    if (!is_null($optionid) && $optionid > 0) {
        $option = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
        $bookingisteacher = booking_check_if_teacher ($option->option);
    }

    if (!$course) {
        return;
    }

    if (has_capability('mod/booking:updatebooking', $context)) {
        $navref->add(
            get_string('createnewbookingoption', 'booking'),
            // For a new booking option, optionid needs to be empty.
            new moodle_url('/mod/booking/editoptions.php', ['id' => $cm->id, 'optionid' => '']),
                navigation_node::TYPE_CUSTOM, null, 'nav_createnewbookingoption'
        );
    }

    if (has_capability('mod/booking:manageoptiontemplates', $context) ||
        has_capability('mod/booking:updatebooking', $context) ||
        has_capability('mod/booking:addeditownoption', $context) ||
        has_capability('mod/booking:subscribeusers', $context ) ||
        has_capability('mod/booking:readresponses', $context ) ||
        $bookingisteacher) {

        if (has_capability('mod/booking:manageoptiontemplates', $context)) {
            if (empty($optionid)) {
                // We only want to show this in instance mode.
                $navref->add(get_string('saveinstanceastemplate', 'mod_booking'),
                    new moodle_url('/mod/booking/instancetemplateadd.php', ['id' => $cm->id]),
                        navigation_node::TYPE_CUSTOM, null, 'nav_saveinstanceastemplate');

                $navref->add(get_string("managecustomreporttemplates", "mod_booking"),
                    new moodle_url('/mod/booking/customreporttemplates.php', ['id' => $cm->id]),
                        navigation_node::TYPE_CUSTOM, null, 'nav_managecustomreporttemplates');
            }
        }
    }

    $urlparam = ['id' => $cm->id, 'optionid' => -1];
    if (!$templatedid = $DB->get_field('booking', 'templateid', ['id' => $cm->instance])) {
        $templatedid = get_config('booking', 'defaulttemplate');
    }
    if (!empty($templatedid) && $DB->record_exists('booking_options', ['id' => $templatedid])) {
        $urlparam['copyoptionid'] = $templatedid;
    }

    if (has_capability('mod/booking:updatebooking', $context)) {
        $navref->add(get_string('importcsvbookingoption', 'mod_booking'),
                new moodle_url('/mod/booking/importoptions.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_importcsvbookingoption');
        $navref->add(get_string('tagtemplates', 'mod_booking'),
                new moodle_url('/mod/booking/tagtemplates.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_tagtemplates');
        $navref->add(get_string('importexcelbutton', 'mod_booking'),
                new moodle_url('/mod/booking/importexcel.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_importexcelbutton');
        // TODO: Add capability for changesemester. Only admins should be allowed to do this!
        $navref->add(get_string('changesemester', 'mod_booking'),
                new moodle_url('/mod/booking/semesters.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_changesemester');
        $navref->add(get_string('recalculateprices', 'mod_booking'),
                new moodle_url('/mod/booking/recalculateprices.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_recalculateprices');
        $navref->add(get_string('teachers_instance_report', 'mod_booking') . " ($bookingsettings->name)",
                new moodle_url('/mod/booking/teachers_instance_report.php', ['cmid' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_teachers_instance_report');
    }

    // We currently never show these entries as we are not sure if they work correctly.
    // Filters, Permissions, Backup, Restore - will not be shown in "More..." menu.
    $keys = $navref->get_children_key_list();
    foreach ($keys as $key => $name) {
        if ($name == 'roleassign' || $name == 'roleoverride' ||
                    $name == 'rolecheck' || $name == 'filtermanage' || $name == 'logreport' ||
                    $name == 'backup' || $name == 'restore') {
            $navref->get($name)->remove();
        }
    }

    if (!is_null($optionid) && $optionid > 0) {
        // In previous booking versions Filters, Permissions, Backup, Restore where only hidden for booking options.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $keys = $navref->get_children_key_list();
        foreach ($keys as $key => $name) {
            if ($name == 'roleassign' || $name == 'roleoverride' ||
                        $name == 'rolecheck' || $name == 'filtermanage' || $name == 'logreport' ||
                        $name == 'backup' || $name == 'restore') {
                $node = $navref->get($name)->remove();
            }
        } */

        $option = $DB->get_record('booking_options', ['id' => $optionid]);
        $booking = $DB->get_record('booking', ['id' => $option->bookingid]);

        if (has_capability('mod/booking:updatebooking', $context) ||
            has_capability('mod/booking:addeditownoption', $context)) {
            $navref->add(get_string('editbookingoption', 'mod_booking'),
                    new moodle_url('/mod/booking/editoptions.php',
                        ['id' => $cm->id, 'optionid' => $optionid]),
                        navigation_node::TYPE_CUSTOM, null, 'nav_edit');
            $navref->add(get_string('manageresponses', 'mod_booking'),
                    new moodle_url('/mod/booking/report.php',
                        ['id' => $cm->id, 'optionid' => $optionid]),
                        navigation_node::TYPE_CUSTOM, null, 'nav_manageresponses');
        }
        if (has_capability('mod/booking:updatebooking', $context)) {
            $navref->add(get_string('duplicatebooking', 'booking'),
                    new moodle_url('/mod/booking/editoptions.php',
                        ['id' => $cm->id, 'optionid' => -1, 'copyoptionid' => $optionid]),
                        navigation_node::TYPE_CUSTOM, null, 'nav_duplicatebooking');

            if (has_capability('mod/booking:manageoptiondates', $context)) {
                $navref->add(get_string('optiondatesmanager', 'booking'),
                        new moodle_url('/mod/booking/optiondates.php',
                            ['id' => $cm->id, 'optionid' => $optionid]),
                            navigation_node::TYPE_CUSTOM, null, 'nav_optiondatesmanager');
            }
        }

        if (has_capability ( 'mod/booking:subscribeusers', $context )) {
            $navref->add(get_string('bookotherusers', 'booking'),
                    new moodle_url('/mod/booking/subscribeusers.php',
                            ['id' => $cm->id, 'optionid' => $optionid]),
                            navigation_node::TYPE_CUSTOM, null, 'nav_bookotherusers');
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $navref->add(get_string('bookuserswithoutcompletedactivity', 'booking'),
                        new moodle_url('/mod/booking/subscribeusersactivity.php',
                                ['id' => $cm->id, 'optionid' => $optionid]),
                                navigation_node::TYPE_CUSTOM, null, 'nav_bookuserswithoutcompletedactivity');
            }
        }

        // TODO: Move booking options to another option currently does not work correcly.
        // We temporarily remove it from booking until we are sure, it works.
        // We need to make sure it works for: teachers, optiondates, prices, answers customfields etc.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $modinfo = get_fast_modinfo($course);
        $bookinginstances = isset($modinfo->instances['booking']) ? count($modinfo->instances['booking']) : 0;
        if (has_capability('mod/booking:updatebooking', context_course::instance($course->id)) && $bookinginstances > 1) {
            $navref->add(get_string('moveoptionto', 'booking'),
                new moodle_url('/mod/booking/moveoption.php',
                    array('id' => $cm->id, 'optionid' => $optionid, 'sesskey' => sesskey())),
                    navigation_node::TYPE_CUSTOM, null, 'nav_moveoptionto');
        } */

        if (has_capability ('mod/booking:readresponses', $context) || booking_check_if_teacher($option)) {
            $completion = new \completion_info($course);
            if ($booking->enablecompletion > 0 &&
                ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC ||
                $completion->is_enabled($cm) == COMPLETION_TRACKING_MANUAL)) {
                $navref->add(get_string('confirmuserswith', 'booking'),
                    new moodle_url('/mod/booking/confirmactivity.php', ['id' => $cm->id, 'optionid' => $optionid]),
                    navigation_node::TYPE_CUSTOM, null, 'nav_confirmuserswith');
            }
        }
        if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id)) &&
                $booking->conectedbooking > 0) {
            $navref->add(get_string('editotherbooking', 'booking'),
                    new moodle_url('/mod/booking/otherbooking.php',
                        ['id' => $cm->id, 'optionid' => $optionid]),
                    navigation_node::TYPE_CUSTOM, null, 'nav_editotherbooking');
        }
        if (has_capability('mod/booking:updatebooking', $context)) {
            $navref->add(get_string('deletethisbookingoption', 'mod_booking'),
                    new moodle_url('/mod/booking/report.php',
                        [
                            'id' => $cm->id,
                            'optionid' => $optionid,
                            'action' => 'deletebookingoption',
                            'sesskey' => sesskey(),
                        ]),
                    navigation_node::TYPE_CUSTOM, null, 'nav_deletebookingoption');
        }
    }

    if (has_capability('mod/booking:manageoptiontemplates', $context)) {
        if (!empty($optionid)) {
            $navref->add(get_string('copytotemplate', 'mod_booking'),
                new moodle_url('/mod/booking/report.php',
                        [
                            'id' => $cm->id,
                            'optionid' => $optionid,
                            'action' => 'copytotemplate',
                            'sesskey' => sesskey(),
                        ]),
                    navigation_node::TYPE_CUSTOM, null, 'nav_copytotemplate');
        }

        $navref->add(get_string("manageoptiontemplates", "mod_booking"),
            new moodle_url('/mod/booking/optiontemplatessettings.php', ['id' => $cm->id]),
                navigation_node::TYPE_CUSTOM, null, 'nav_manageoptiontemplates');
    }
}

/**
 * Check if logged in user is in teachers db.
 * @param mixed|int $optionoroptionid optional option class or optionid
 * @return true if is assigned as teacher otherwise return false
 */
function booking_check_if_teacher($optionoroptionid = null) {
    global $DB, $USER;

    if (empty($optionoroptionid)) {
        // If we have no option, we check, if the teacher is a teacher of ANY option.
        $user = $DB->get_records('booking_teachers',
            ['userid' => $USER->id]);
        if (empty($user)) {
            return false;
        } else {
            return true;
        }
    } else {
        if (is_object($optionoroptionid)) {
            $optionid = (int) $optionoroptionid->id;
        } else if (is_number($optionoroptionid)) {
            $optionid = (int) $optionoroptionid;
        } else {
            return false;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (in_array($USER->id, $settings->teacherids)) {
            return true;
        } else {
            return false;
        }
    }
}

/**
 * This inverts the completion status of the selected users.
 *
 * @param array $selectedusers
 * @param unknown $booking
 * @param int $cmid
 * @param int $optionid
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
                    ['optionid' => $optionid, 'userid' => $ui]);

            if ($userdata->completed == '1') {
                $userdata->completed = '0';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records('booking_teachers',
                    ['bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1']);

                if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
                    $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
                }
            } else {
                $userdata->completed = '1';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records('booking_teachers',
                    ['bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1']);

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
 * @param int $cmid
 * @param int $optionid
 * @param array $allselectedusers
 */
function booking_generatenewnumbers($bookingdatabooking, $cmid, $optionid, $allselectedusers) {
    global $DB, $CFG;

    $answerscount = $DB->get_field_sql(
        "SELECT COUNT(*) AS answerscount
        FROM {booking_answers}
        WHERE optionid = :optionid AND waitinglist < 2",
        ['optionid' => $optionid]);

    if (!empty($allselectedusers)) {
        $tmprecnum = $DB->get_record_sql(
                "SELECT numrec
                FROM {booking_answers}
                WHERE optionid = :optionid AND waitinglist < 2
                ORDER BY numrec DESC
                LIMIT 1",
                ['optionid' => $optionid]);

        // If NO users or ALL users are selected, we always want to start with 1.
        if ($tmprecnum->numrec == 0 || count($allselectedusers) == $answerscount) {
            $recnum = 1;
        } else {
            $recnum = $tmprecnum->numrec + 1;
        }

        foreach ($allselectedusers as $userid) {
            // TODO: Optimize DB query: get_records instead of loop.
            $userdata = $DB->get_record_sql(
                "SELECT *
                FROM {booking_answers}
                WHERE optionid = :optionid AND userid = :userid AND waitinglist < 2",
                ['optionid' => $optionid, 'userid' => $userid]);

            $userdata->numrec = $recnum++;
            $DB->update_record('booking_answers', $userdata);
        }
    } else {
        // Mysql and MariaDB use RAND().
        $random = "RAND()";
        // Postgres uses RANDOM().
        if (isset($CFG->dbfamily) && $CFG->dbfamily == "postgres") {
            $random = "RANDOM()";
        }

        $allusers = $DB->get_records_sql(
                "SELECT *
                FROM {booking_answers}
                WHERE optionid = :optionid AND waitinglist < 2
                ORDER BY {$random}",
                ['optionid' => $optionid]);

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
 * @param int $cmid course module id
 * @param int $optionid
 */
function booking_activitycompletion($selectedusers, $booking, $cmid, $optionid) {
    global $DB, $CFG, $USER;

    $course = $DB->get_record('course', ['id' => $booking->course]);
    require_once($CFG->libdir . '/completionlib.php');
    $completion = new \completion_info($course);

    $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);

    foreach ($selectedusers as $selecteduser) {
        $userdata = $DB->get_record_sql(
            "SELECT * FROM {booking_answers}
            WHERE optionid = :optionid AND userid = :selecteduser
            AND waitinglist <> 5", // Waitinglist 5 means deleted.
            ['optionid' => $optionid, 'selecteduser' => $selecteduser ]);

        if ($userdata->completed == '1') {
            $userdata->completed = '0';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);
            $countcomplete = $DB->count_records('booking_answers',
                    ['bookingid' => $booking->id, 'userid' => $selecteduser, 'completed' => '1']);

            if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $selecteduser);
            }
        } else {
            $userdata->completed = '1';
            $userdata->timemodified = time();

            // Trigger the completion event, in order to send the notification mail.
            $event = \mod_booking\event\bookingoption_completed::create([
                'context' => context_module::instance($cmid),
                'objectid' => $optionid,
                'userid' => $USER->id,
                'relateduserid' => $selecteduser,
                'other' => ['cmid' => $cmid],
            ]);
            $event->trigger();
            // Important: userid is the user who triggered, relateduserid is the affected user who completed.

            $DB->update_record('booking_answers', $userdata);
            $countcomplete = $DB->count_records('booking_answers',
                    ['bookingid' => $booking->id, 'userid' => $selecteduser, 'completed' => '1']);

            if ($completion->is_enabled($cm) && $booking->enablecompletion <= $countcomplete) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $selecteduser);
            }
        }
    }

    // After activity completion, we need to purge caches for the option.
    booking_option::purge_cache_for_option($optionid);
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
    } else if ($userid && $nullifnone) {
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

    $params = ['itemname' => $booking->name, 'idnumber' => $booking->cmidnumber];

    if (!$booking->assessed || $booking->scale == 0) {
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
            ['deleted' => 1]);
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
    $rec = $DB->get_record("booking", ["id" => $bookingid, "scale" => "-$scaleid"]);

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
    if ($scaleid && $DB->record_exists('booking', ['scale' => -$scaleid])) {
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
    return [
        'view' => has_capability('mod/booking:viewrating', $context),
        'viewany' => has_capability('mod/booking:viewanyrating', $context),
        'viewall' => has_capability('mod/booking:viewallratings', $context),
        'rate' => has_capability('mod/booking:rate', $context),
    ];
}

/**
 * Validates a submitted rating. OBSOLETE?
 *
 * @param array $params submitted data context => object the context in which the rated items exists [required]
 * component => The component for this module - should always be mod_forum [required]
 * ratingarea => object the context in which the rated items exists [required]
 * itemid => int the ID of the object being rated [required]
 * scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 * rating => int the submitted rating [required]
 * rateduserid => int the id of the user whose items have been rated.
 *      NOT the user who submitted the ratings. 0 to update all. [required]
 * aggregation => int the aggregation method to apply when calculating grades i.e. RATING_AGGREGATE_AVERAGE [required]
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
            ['id' => $params['itemid'], 'userid' => $params['rateduserid']], '*', MUST_EXIST);
    $booking = $DB->get_record('booking', ['id' => $answer->bookingid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $booking->course], '*', MUST_EXIST);
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
        $scalerecord = $DB->get_record('scale', ['id' => -$booking->scale]);
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
 * @param stdClass $params
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
        throw new moodle_exception('ratepermissiondenied', 'rating');
    } else {
        foreach ($ratings as $rating) {
            $checks = [
                'context' => $context,
                'component' => $component,
                'ratingarea' => $ratingarea,
                'itemid' => $rating->itemid,
                'scaleid' => $scaleid,
                'rating' => $rating->rating,
                'rateduserid' => $rating->rateduserid,
            ];
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
        $modinstance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
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
 * Given an ID of an instance of this module, will permanently delete the instance and data.
 *
 * @param int $id this is the bookingid - not the cmid!
 * @return boolean
 */
function booking_delete_instance($id) {
    global $DB;

    if (!$booking = $DB->get_record("booking", ["id" => "$id"])) {
        return false;
    }

    if (!$cm = get_coursemodule_from_instance('booking', $id)) {
        return false;
    }

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        return false;
    }

    $result = true;

    $alloptionids = \mod_booking\booking::get_all_optionids($id);
    foreach ($alloptionids as $optionid) {
        $bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
        $bookingoption->delete_booking_option();
    }

    // Delete option header images.
    // Delete image files belonging to the option.
    $imgfilesql = "SELECT id, contextid, filepath, filename, userid, source, author, license
    FROM {files}
    WHERE component = 'mod_booking'
    AND filearea = 'bookingimages'
    AND filesize > 0
    AND mimetype LIKE 'image%'
    AND itemid = :bookingid";

    $imgfileparams = [
        'bookingid' => $booking->id,
    ];

    if ($imgfilerecords = $DB->get_records_sql($imgfilesql, $imgfileparams)) {
        foreach ($imgfilerecords as $imgfilerecord) {
            $fs = get_file_storage();
            $fileinfo = [
                'component' => 'mod_booking',
                'filearea' => 'bookingimages',
                'itemid' => $booking->id,
                'contextid' => $imgfilerecord->contextid,
                'filepath' => $imgfilerecord->filepath,
                'filename' => $imgfilerecord->filename,
            ];
            // Get file.
            $imgfile = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                    $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
            // Delete it if it exists.
            if ($imgfile) {
                $imgfile->delete();
                // Also delete remaining artifacts.
                $DB->delete_records('files', [
                    'component' => 'mod_booking',
                    'filearea' => 'bookingimages',
                    'itemid' => $booking->id,
                    'contextid' => $imgfilerecord->contextid,
                    'filepath' => $imgfilerecord->filepath,
                ]);
            }
        }
    }

    if (!$DB->delete_records("booking_answers", ["bookingid" => "$booking->id"])) {
        $result = false;
    }

    if (!$DB->delete_records('booking_optiondates', ["bookingid" => "$booking->id"])) {
        $result = false;
    } else {
        // If optiondates are deleted we also have to delete the associated entries in booking_optiondates_teachers.
        // TODO: this should be moved into delete_booking_option.
        teachers_handler::delete_booking_optiondates_teachers_by_bookingid($booking->id);
    }

    // We also need to delete the booking teachers in the booking_teachers table!
    if (!$DB->delete_records('booking_teachers', ["bookingid" => "$booking->id"])) {
        $result = false;
    }

    // Delete any entity relations for the booking instance.
    // TODO: this should be moved into delete_booking_option.
    if (class_exists('local_entities\entitiesrelation_handler')) {
        if (!entitiesrelation_handler::delete_entities_relations_by_bookingid($booking->id)) {
            $result = false;
        }
    }

    if (!$DB->delete_records("event", ["instance" => "$booking->id"])) {
        $result = false;
    }

    if (!$DB->delete_records("booking", ["id" => "$booking->id"])) {
        $result = false;
    }

    // When deleting an instance, we need to invalidate the cache for booking instances.
    cache_helper::invalidate_by_event('setbackbookinginstances', [$cm->id]);

    // Also purge caches for options table and booking_option_settings.
    cache_helper::purge_by_event('setbackoptionstable');
    cache_helper::purge_by_event('setbackoptionsettings');

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
            ["bookingid" => $booking->id, "userid" => $USER->id])) {
        $tmptxt = [];
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
    return ['reset_booking' => 1];
}

/**
 *
 * @param int $seconds
 */
function booking_pretty_duration($seconds) {
    $measures = ['days' => 24 * 60 * 60, 'hours' => 60 * 60, 'minutes' => 60];
    $durationparts = [];
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
 * THIS FUNCTION IS NEVER USED. CAN WE DELETE IT?
 *
 * Checks if user on waitinglist gets normal place if a user is deleted
 *
 * @param $optionid id of booking option
 * @param $booking booking object
 * @param $cancelleduserid user id that was deleted form booking option
 * @param $cmid course module id
 * @return mixed false if no user gets from waitinglist to booked list or userid of user now on booked list
 */
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/*function booking_check_statuschange($optionid, $booking, $cancelleduserid, $cmid) {
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
    $allresponses = $DB->get_records('booking_answers', array('optionid' => $optionid), 'timemodified', 'userid');
    $context = context_module::instance($cmid);
    $firstuseronwaitinglist = $option->maxanswers + 1;
    $i = 1;
    $sortedresponses = [];
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
*/

/**
 * Returns all other caps used in module
 */
function booking_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * Adds user as teacher (booking manager) to a booking option
 *
 * @param int $userid
 * @param int $optionid
 * @param int $cmid
 * @param mixed $groupid the group object or group id
 * @param bool $doenrol true if we want to enrol the teacher into the relevant course
 * @return bool true if teacher was subscribed
 */
function subscribe_teacher_to_booking_option(int $userid, int $optionid, int $cmid, $groupid = null,
    bool $doenrol = true) {

    global $DB, $USER;

    $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
    // Get settings of the booking instance (do not confuse with option settings).
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

    // Event if teacher already exists in DB, we still might want to enrol it into a new course.
    if ($doenrol) {
        // We enrol teacher with the type defined in settings.
        $option->enrol_user($userid, true, $bookingsettings->teacherroleid, true);

        /* NOTE: In the future, we might need a teacher_enrolled event here (or inside enrol_user)
        which indicates that a teacher has been enrolled into a Moodle course. */
    }

    if ($DB->record_exists("booking_teachers", ["userid" => $userid, "optionid" => $optionid])) {
        return true;
    }

    $newteacherrecord = new stdClass();
    $newteacherrecord->userid = $userid;
    $newteacherrecord->optionid = $optionid;
    $newteacherrecord->bookingid = $bookingsettings->id;

    $inserted = $DB->insert_record("booking_teachers", $newteacherrecord);

    // When inserting a new teacher, we also need to insert the teacher for each optiondate.
    teachers_handler::subscribe_teacher_to_all_optiondates($optionid, $userid);

    if (!empty($groupid)) {
        groups_add_member($groupid, $userid);
    }

    if ($inserted) {
        $event = \mod_booking\event\teacher_added::create([
            'userid' => $USER->id,
            'relateduserid' => $userid,
            'objectid' => $optionid,
            'context' => context_module::instance($cmid),
        ]);
        $event->trigger();
    }

    return $inserted;
}

/**
 * Removes teacher from the subscriber list.
 *
 * @param int $userid
 * @param int $optionid
 * @param int $cmid
 * @return bool true if successful
 */
function unsubscribe_teacher_from_booking_option(int $userid, int $optionid, int $cmid) {
    global $DB, $USER;

    $event = \mod_booking\event\teacher_removed::create(
            ['userid' => $USER->id, 'relateduserid' => $userid, 'objectid' => $optionid,
                'context' => context_module::instance($cmid),
            ]);
    $event->trigger();

    // Also delete the teacher from every optiondate.
    teachers_handler::remove_teacher_from_all_optiondates($optionid, $userid);

    return ($DB->delete_records('booking_teachers',
            ['userid' => $userid, 'optionid' => $optionid]));
}

function booking_show_subcategories($catid, $courseid) {
    global $DB;
    $categories = $DB->get_records('booking_category', ['cid' => $catid]);
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

    if ($CFG->version >= 2021051700) {
        // This only works in Moodle 3.11 and later.
        $allnames = \core_user\fields::for_identity($context)->with_userpic()->get_sql('u')->selects;
        $allnames = trim($allnames, ', ');
    } else {
        // This is deprecated in Moodle 3.11 and later.
        $extrauserfields = get_extra_user_fields($context);
        $allnames = user_picture::fields('u', $extrauserfields);
    }

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


/**
 * This will create the options list on the coursepage.
 *
 * @param cm_info $cm
 * @return void
 */
function mod_booking_cm_info_view(cm_info $cm) {
    global $PAGE;

    $booking = singleton_service::get_instance_of_booking_by_cmid($cm->id);

    if (!empty($booking)) {
        $html = '';

        if (isset($booking->settings->showlistoncoursepage) &&
            ($booking->settings->showlistoncoursepage == 1 || $booking->settings->showlistoncoursepage == 2)) {

            /* NOTE: For backwards compatibility, we kept both values (1 and 2).
            Coursepage_available_options are no longer supported! */

            // Show course name, a short info text and a button redirecting to available booking options.
            $data = new coursepage_shortinfo_and_button($cm);
            $output = $PAGE->get_renderer('mod_booking');
            $html .= $output->render_coursepage_shortinfo_and_button($data);
        }

        if ($html !== '') {
            $cm->set_content($html);
        }
    }
}

/**
 * Helper function to check if a string is valid JSON.
 * @param $string the string to check
 * @return bool true if valid json
 */
function is_json($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Helper function to get a list of all booking events to be shown in a select (dropdown).
 * @return array a list containing the full paths of all booking events as key
 *               and the event names as values
 */
function get_list_of_booking_events() {
    $eventinformation = [];
    $events = core_component::get_component_classes_in_namespace('mod_booking', 'event');
    foreach (array_keys($events) as $event) {
        // We need to filter all classes that extend event base, or the base class itself.
        if (is_a($event, \core\event\base::class, true)) {
            $parts = explode('\\', $event);
            $eventwithnamespace = "\\{$event}";
            $eventinformation[$eventwithnamespace] = $eventwithnamespace::get_name() .
                " (" . array_pop($parts) . ")";
        }
    }
    return $eventinformation;
}

/**
 * Helper function to replace special characters within a string.
 * @param string $text a text string
 * @return string|string[]|null
 */
function clean_string(string $text) {
    $utf8 = [
        '/[áàâãªä]/u'   => 'a',
        '/[ÁÀÂÃÄ]/u'    => 'A',
        '/[ÍÌÎÏ]/u'     => 'I',
        '/[íìîï]/u'     => 'i',
        '/[éèêë]/u'     => 'e',
        '/[ÉÈÊË]/u'     => 'E',
        '/[óòôõºö]/u'   => 'o',
        '/[ÓÒÔÕÖ]/u'    => 'O',
        '/[úùûü]/u'     => 'u',
        '/[ÚÙÛÜ]/u'     => 'U',
        '/[çćč]/'       => 'c',
        '/ÇĆČ/'         => 'C',
        '/ñń/'          => 'n',
        '/ÑŃ/'          => 'N',
        '/–/'           => '-', // UTF-8 hyphen to "normal" hyphen.
        '/[\'’‘‹›‚]/u'  => ' ', // Single quote.
        '/[\"“”«»„]/u'  => ' ', // Double quote.
        '/ /'           => ' ', // Nonbreaking space (equiv. to 0x160).
    ];
    return preg_replace(array_keys($utf8), array_values($utf8), $text);
}
