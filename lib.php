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
 * Library of common module functions and constants.
 *
 * @package     mod_booking
 * @copyright   2023 Georg Maißer <georg.maisser@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->dirroot . '/course/externallib.php');

use local_entities\entitiesrelation_handler;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\output\coursepage_shortinfo_and_button;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;
use mod_booking\utils\wb_payment;
use mod_booking\booking_rules\rules_info;

// Default fields for bookingoptions in view.php and for download.
define('MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS', "identifier,titleprefix,text,description,teacher,responsiblecontact," .
"showdates,dayofweektime,location,institution,course,minanswers,bookings,bookingopeningtime,bookingclosingtime");

// View params.
define('MOD_BOOKING_VIEW_PARAM_LIST', 0); // List view.
define('MOD_BOOKING_VIEW_PARAM_CARDS', 1); // Cards view.
define('MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT', 2); // List view with image on the left.
define('MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT', 3); // List view with image on the right.

// Currently up to 9 different price categories can be set.
define('MOD_BOOKING_MAX_PRICE_CATEGORIES', 9);

// Time to confirm booking or cancellation in seconds.
define('MOD_BOOKING_TIME_TO_CONFIRM', 20);

// Define description parameters.
define('MOD_BOOKING_DESCRIPTION_WEBSITE', 1); // Shows link button with text "book now" and no link to TeamsMeeting etc.
define('MOD_BOOKING_DESCRIPTION_CALENDAR', 2); // Shows link button with text "go to bookingoption" and meeting links via link.php.
define('MOD_BOOKING_DESCRIPTION_ICAL', 3); // Shows link with text "go to bookingoption" and meeting links via link.php for iCal.
define('MOD_BOOKING_DESCRIPTION_MAIL', 4); // Shows link with text "go to bookingoption" and meeting links via link.php...
                            // ...for mail placeholder {bookingdetails}.
define('MOD_BOOKING_DESCRIPTION_OPTIONVIEW', 5); // Description for booking option preview page.

// Define message parameters.
define('MOD_BOOKING_MSGPARAM_CONFIRMATION', 1);
define('MOD_BOOKING_MSGPARAM_WAITINGLIST', 2);
define('MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT', 3);
define('MOD_BOOKING_MSGPARAM_REMINDER_TEACHER', 4);
define('MOD_BOOKING_MSGPARAM_STATUS_CHANGED', 5);
define('MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT', 6);
define('MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM', 7);
define('MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION', 8);
define('MOD_BOOKING_MSGPARAM_POLLURL_PARTICIPANT', 9);
define('MOD_BOOKING_MSGPARAM_POLLURL_TEACHER', 10);
define('MOD_BOOKING_MSGPARAM_COMPLETED', 11);
define('MOD_BOOKING_MSGPARAM_SESSIONREMINDER', 12);
define('MOD_BOOKING_MSGPARAM_REPORTREMINDER', 13); // Reminder sent from report.php.
define('MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE', 14);

// Define booking status parameters.
define('MOD_BOOKING_STATUSPARAM_BOOKED', 0);
define('MOD_BOOKING_STATUSPARAM_WAITINGLIST', 1);
define('MOD_BOOKING_STATUSPARAM_RESERVED', 2);
define('MOD_BOOKING_STATUSPARAM_NOTIFYMELIST', 3); // Get message when place is open.
define('MOD_BOOKING_STATUSPARAM_NOTBOOKED', 4);
define('MOD_BOOKING_STATUSPARAM_DELETED', 5);

// Params to define behavior of booking_option::update.
define('MOD_BOOKING_UPDATE_OPTIONS_PARAM_DEFAULT', 1);
define('MOD_BOOKING_UPDATE_OPTIONS_PARAM_REDUCED', 2);
define('MOD_BOOKING_UPDATE_OPTIONS_PARAM_IMPORT', 3);

// Define message controller parameters.
define('MOD_BOOKING_MSGCONTRPARAM_SEND_NOW', 1);
define('MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC', 2);
define('MOD_BOOKING_MSGCONTRPARAM_DO_NOT_SEND', 3);
define('MOD_BOOKING_MSGCONTRPARAM_VIEW_CONFIRMATION', 4);

// Define booking availability condition ids.
define('MOD_BOOKING_BO_COND_CONFIRMCANCEL', 170);
define('MOD_BOOKING_BO_COND_ALREADYBOOKED', 150);
define('MOD_BOOKING_BO_COND_ALREADYRESERVED', 140);
define('MOD_BOOKING_BO_COND_ISCANCELLED', 130);
define('MOD_BOOKING_BO_COND_ISBOOKABLEINSTANCE', 125);
define('MOD_BOOKING_BO_COND_ISBOOKABLE', 120);
define('MOD_BOOKING_BO_COND_ONWAITINGLIST', 110);

define('MOD_BOOKING_BO_COND_CANCELMYSELF', 105);
define('MOD_BOOKING_BO_COND_BOOKONDETAIL', 104);

define('MOD_BOOKING_BO_COND_NOTIFYMELIST', 100);
define('MOD_BOOKING_BO_COND_FULLYBOOKED', 90);
define('MOD_BOOKING_BO_COND_MAX_NUMBER_OF_BOOKINGS', 80);

define('MOD_BOOKING_BO_COND_ISLOGGEDINPRICE', 75);
define('MOD_BOOKING_BO_COND_ISLOGGEDIN', 74);

define('MOD_BOOKING_BO_COND_OPTIONHASSTARTED', 70);
define('MOD_BOOKING_BO_COND_BOOKING_TIME', 60);
define('MOD_BOOKING_BO_COND_BOOKINGPOLICY', 50);
define('MOD_BOOKING_BO_COND_SUBBOOKINGBLOCKS', 45);
define('MOD_BOOKING_BO_COND_SUBBOOKING', 40);
define('MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING', 35);

// Careful with changing these JSON COND values! They are stored.
// If changed, DB Values need to be updated.
define('MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE', 18); // We might want to moove this up?
define('MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS', 17);
define('MOD_BOOKING_BO_COND_JSON_CUSTOMFORM', 16);
define('MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE', 15);
define('MOD_BOOKING_BO_COND_JSON_SELECTUSERS', 14);
define('MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED', 13);
define('MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD', 12);
define('MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD', 11);

define('MOD_BOOKING_BO_COND_CAPBOOKINGCHOOSE', 4);

define('MOD_BOOKING_BO_COND_ASKFORCONFIRMATION', 0);

define('MOD_BOOKING_BO_COND_ELECTIVENOTBOOKABLE', -5);
define('MOD_BOOKING_BO_COND_ELECTIVEBOOKITBUTTON', -10);

define('MOD_BOOKING_BO_COND_CONFIRMBOOKWITHSUBSCRIPTION', -20);
define('MOD_BOOKING_BO_COND_BOOKWITHSUBSCRIPTION', -30);

define('MOD_BOOKING_BO_COND_CONFIRMBOOKWITHCREDITS', -40);
define('MOD_BOOKING_BO_COND_BOOKWITHCREDITS', -50);

define('MOD_BOOKING_BO_COND_NOSHOPPINGCART', -60);
define('MOD_BOOKING_BO_COND_PRICEISSET', -70);

define('MOD_BOOKING_BO_COND_CONFIRMBOOKIT', -80);
define('MOD_BOOKING_BO_COND_BOOKITBUTTON', -90); // This is only used to show the book it button.
define('MOD_BOOKING_BO_COND_CONFIRMATION', -100); // This is the last page after booking.

// Define conditions parameters.
define('MOD_BOOKING_CONDPARAM_ALL', 0);
define('MOD_BOOKING_CONDPARAM_HARDCODED_ONLY', 1);
define('MOD_BOOKING_CONDPARAM_JSON_ONLY', 2);
define('MOD_BOOKING_CONDPARAM_MFORM_ONLY', 3);
define('MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN', 4);

// Define status for booking & subbooking options.
define('MOD_BOOKING_UNVERIFIED', 0);
define('MOD_BOOKING_PENDING', 1);
define('MOD_BOOKING_VERIFIED', 2);

// Define common bookin settings.
define('MOD_BOOKING_PAGINATIONDEF', 25);

// Define campaign types.
define('MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD', 0);
define('MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING', 1);

// Categories for option fields.
define('MOD_BOOKING_OPTION_FIELD_NECESSARY', 1);
define('MOD_BOOKING_OPTION_FIELD_STANDARD', 2); // This field is part of standard.
define('MOD_BOOKING_OPTION_FIELD_EASY', 3); // This field is part of easy.

// Define IDs of Fields.
define('MOD_BOOKING_OPTION_FIELD_PREPARE_IMPORT', 1); // Has to be the first field class.
define('MOD_BOOKING_OPTION_FIELD_ID', 10);
define('MOD_BOOKING_OPTION_FIELD_JSON', 11);
define('MOD_BOOKING_OPTION_FIELD_DUPLICATION', 12); // Needed for duplication to work.
define('MOD_BOOKING_OPTION_FIELD_RETURNURL', 20);
define('MOD_BOOKING_OPTION_FIELD_FORMCONFIG', 25);
define('MOD_BOOKING_OPTION_FIELD_MOVEOPTION', 28);
define('MOD_BOOKING_OPTION_FIELD_TEMPLATE', 30);
define('MOD_BOOKING_OPTION_FIELD_TEXT', 40);
define('MOD_BOOKING_OPTION_FIELD_IDENTIFIER', 50);
define('MOD_BOOKING_OPTION_FIELD_TITLEPREFIX', 60);
define('MOD_BOOKING_OPTION_FIELD_EASY_TEXT', 61);
define('MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGOPENINGTIME', 62);
define('MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGCLOSINGTIME', 63);
define('MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_SELECTUSERS', 64);
define('MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_PREVIOUSLYBOOKED', 65);
define('MOD_BOOKING_OPTION_FIELD_DESCRIPTION', 70);
define('MOD_BOOKING_OPTION_FIELD_INVISIBLE', 80);
define('MOD_BOOKING_OPTION_FIELD_ANNOTATION', 90);
define('MOD_BOOKING_OPTION_FIELD_LOCATION', 100);
define('MOD_BOOKING_OPTION_FIELD_INSTITUTION', 110);
define('MOD_BOOKING_OPTION_FIELD_ADDRESS', 120);
define('MOD_BOOKING_OPTION_FIELD_OPTIONIMAGES', 130);
define('MOD_BOOKING_OPTION_FIELD_MAXANSWERS', 140);
define('MOD_BOOKING_OPTION_FIELD_MAXOVERBOOKING', 150);
define('MOD_BOOKING_OPTION_FIELD_MINANSWERS', 160);
define('MOD_BOOKING_OPTION_FIELD_POLLURL', 170);
define('MOD_BOOKING_OPTION_FIELD_COURSEID', 180); // Course to enrol to.
define('MOD_BOOKING_OPTION_FIELD_ENROLMENTSTATUS', 185);
define('MOD_BOOKING_OPTION_FIELD_ADDTOGROUP', 190);
define('MOD_BOOKING_OPTION_FIELD_DURATION', 195);
define('MOD_BOOKING_OPTION_FIELD_ENTITIES', 200);
define('MOD_BOOKING_OPTION_FIELD_SHOPPPINGCART', 205);
define('MOD_BOOKING_OPTION_FIELD_OPTIONDATES', 210);
define('MOD_BOOKING_OPTION_FIELD_COURSESTARTTIME', 220); // Replaced with optiondates class.
define('MOD_BOOKING_OPTION_FIELD_COURSEENDTIME', 230); // Replaced with optiondates class.
define('MOD_BOOKING_OPTION_FIELD_ADDTOCALENDAR', 240);
define('MOD_BOOKING_OPTION_FIELD_TEACHERS', 250);
define('MOD_BOOKING_OPTION_FIELD_RESPONSIBLECONTACT', 260);
define('MOD_BOOKING_OPTION_FIELD_PRICE', 270);
define('MOD_BOOKING_OPTION_FIELD_PRICEFORMULAADD', 280);
define('MOD_BOOKING_OPTION_FIELD_PRICEFORMULAMULTIPLY', 290);
define('MOD_BOOKING_OPTION_FIELD_PRICEFORMULAOFF', 300);
define('MOD_BOOKING_OPTION_FIELD_CREDITS', 310);
define('MOD_BOOKING_OPTION_FIELD_ELECTIVE', 320);
define('MOD_BOOKING_OPTION_FIELD_COSTUMFIELDS', 330);
define('MOD_BOOKING_OPTION_FIELD_AVAILABILITY', 340);
define('MOD_BOOKING_OPTION_FIELD_BOOKINGOPENINGTIME', 350);
define('MOD_BOOKING_OPTION_FIELD_BOOKINGCLOSINGTIME', 360);
define('MOD_BOOKING_OPTION_FIELD_SUBBOOKINGS', 370);
define('MOD_BOOKING_OPTION_FIELD_ACTIONS', 380);
define('MOD_BOOKING_OPTION_FIELD_ADVANCED', 390);
define('MOD_BOOKING_OPTION_FIELD_DISABLEBOOKINGUSERS', 400);
define('MOD_BOOKING_OPTION_FIELD_DISABLECANCEL', 410);
define('MOD_BOOKING_OPTION_FIELD_CANCELUNTIL', 420);
define('MOD_BOOKING_OPTION_FIELD_WAITFORCONFIRMATION', 425);
define('MOD_BOOKING_OPTION_FIELD_ATTACHMENT', 430);
define('MOD_BOOKING_OPTION_FIELD_NOTIFICATIONTEXT', 440);
define('MOD_BOOKING_OPTION_FIELD_REMOVEAFTERMINUTES', 450);
define('MOD_BOOKING_OPTION_FIELD_HOWMANYUSERS', 470);
define('MOD_BOOKING_OPTION_FIELD_BEFOREBOOKEDTEXT', 480);
define('MOD_BOOKING_OPTION_FIELD_BEFORECOMPLETEDTEXT', 490);
define('MOD_BOOKING_OPTION_FIELD_AFTERCOMPLETEDTEXT', 500);
define('MOD_BOOKING_OPTION_FIELD_RECURRINGOPTIONS', 510);
define('MOD_BOOKING_OPTION_FIELD_BOOKUSERS', 520);
define('MOD_BOOKING_OPTION_FIELD_TIMEMODIFIED', 530);
define('MOD_BOOKING_OPTION_FIELD_TEMPLATESAVE', 600);
define('MOD_BOOKING_OPTION_FIELD_EVENTSLIST', 700);
define('MOD_BOOKING_OPTION_FIELD_AFTERSUBMITACTION', 999);

// To define execution of field methods.
define('MOD_BOOKING_EXECUTION_NORMAL', 0);
define('MOD_BOOKING_EXECUTION_POSTSAVE', 1);

// Definition of Header sections in option form.
define('MOD_BOOKING_HEADER_GENERAL', 'general');
define('MOD_BOOKING_HEADER_DATES', 'datesheader');
define('MOD_BOOKING_HEADER_TEACHERS', 'bookingoptionteachers');
define('MOD_BOOKING_HEADER_RESPONSIBLECONTACT', 'responsiblecontactheader');
define('MOD_BOOKING_HEADER_ADVANCEDOPTIONS', 'advancedoptions');
define('MOD_BOOKING_HEADER_BOOKINGOPTIONTEXT', 'textdependingonstatus');
define('MOD_BOOKING_HEADER_RECURRINGOPTIONS', 'recurringheader');
define('MOD_BOOKING_HEADER_TEMPLATES', 'templateheader');
define('MOD_BOOKING_HEADER_PRICE', 'bookingoptionprice');
define('MOD_BOOKING_HEADER_ELECTIVE', 'electivesettings');
define('MOD_BOOKING_HEADER_ACTIONS', 'bookingactionsheader');
define('MOD_BOOKING_HEADER_AVAILABILITY', 'availabilityconditionsheader');
define('MOD_BOOKING_HEADER_SUBBOOKINGS', 'bookingsubbookingsheader');
define('MOD_BOOKING_HEADER_CUSTOMFIELDS', 'category_'); // There can be multiple headers, with custom names.
define('MOD_BOOKING_HEADER_TEMPLATESAVE', 'templateheader');
define('MOD_BOOKING_HEADER_COURSES', 'coursesheader');

define('MOD_BOOKING_MAX_CUSTOM_FIELDS', 3);
define('MOD_BOOKING_FORM_OPTIONDATEID', 'optiondateid_');
define('MOD_BOOKING_FORM_DAYSTONOTIFY', 'daystonotify_');
define('MOD_BOOKING_FORM_COURSESTARTTIME', 'coursestarttime_');
define('MOD_BOOKING_FORM_COURSEENDTIME', 'courseendtime_');
define('MOD_BOOKING_FORM_DELETEDATE', 'deletedate_');

// SQL Filter.
define('MOD_BOOKING_SQL_FILTER_INACTIVE', 0);
define('MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO', 1);
define('MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME', 2);

// Tracking of changes can be excluded for classes (fields).
// Implement this as setting if needed.
define('MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING', [
]);

/**
 * Booking get coursemodule info.
 *
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

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionoptioncompleted'] = $booking->settings->enablecompletion;
    }
    return $info;
}

/**
 * Serves the booking / option files.
 *
 * @package  mod_booking
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 *
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
    if (
        $filearea !== 'myfilemanager'
        && $filearea !== 'bookingimages'
        && $filearea !== 'myfilemanageroption'
        && $filearea !== 'bookingoptionimage'
        && $filearea !== 'signinlogoheader'
        && $filearea !== 'signinlogofooter'
        && $filearea !== 'templatefile'
    ) {
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

/**
 * Booking user outline.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $booking
 *
 * @return stdClass|null
 *
 */
function booking_user_outline($course, $user, $mod, $booking) {
    global $DB;
    if (
        $answer = $DB->get_record(
            'booking_answers',
            ['bookingid' => $booking->id, 'userid' => $user->id, 'waitinglist' => 0]
        )
    ) {
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
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $mod
 * @param object $booking
 *
 * @throws coding_exception
 * @throws dml_exception
 *
 * @return void
 */
function booking_user_complete($course, $user, $mod, $booking) {
    global $DB;
    if (
        $answer = $DB->get_record(
            'booking_answers',
            ["bookingid" => $booking->id, "userid" => $user->id]
        )
    ) {
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

/**
 * Booking supports.
 *
 * @param bool $feature
 *
 * @return bool|null
 *
 */
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
 *
 * @param object $commentparam
 *
 * @return array
 *
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
            $udata = $DB->get_record(
                'booking_answers',
                ['userid' => $USER->id, 'optionid' => $commentparam->itemid]
            );
            if ($udata) {
                return ['post' => true, 'view' => true];
            } else {
                return ['post' => false, 'view' => true];
            }
            break;
        case 3:
            $udata = $DB->get_record(
                'booking_answers',
                ['userid' => $USER->id, 'optionid' => $commentparam->itemid]
            );
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
 * @return bool
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
        $booking->optionsfields = MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (
        isset($booking->optionsdownloadfields)
        && is_array($booking->optionsdownloadfields)
        && count($booking->optionsdownloadfields) > 0
    ) {
        $booking->optionsdownloadfields = implode(',', $booking->optionsdownloadfields);
    } else {
        $booking->optionsdownloadfields = MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (
        isset($booking->signinsheetfields)
        && is_array($booking->signinsheetfields)
        && count($booking->signinsheetfields) > 0
    ) {
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

    if (isset($booking->cancelrelativedate)) {
        booking::add_data_to_json($booking, 'cancelrelativedate', $booking->cancelrelativedate);
    }

    if (isset($booking->allowupdatetimestamp)) {
        booking::add_data_to_json($booking, 'allowupdatetimestamp', $booking->allowupdatetimestamp);
    }

    // If no policy was entered, we still have to check for HTML tags.
    if (!isset($booking->bookingpolicy) || empty(strip_tags($booking->bookingpolicy))) {
        $booking->bookingpolicy = '';
    }

    // Insert answer options from mod_form.
    $booking->id = $DB->insert_record("booking", $booking);

    $cmid = $booking->coursemodule;
    $context = context_module::instance($cmid);

    if ($draftitemid = file_get_submitted_draft_itemid('myfilemanager')) {
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_booking',
            'myfilemanager',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 50]
        );
    }

    if ($draftitemid = file_get_submitted_draft_itemid('bookingimages')) {
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_booking',
            'bookingimages',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 500]
        );
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogoheader')) {
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_booking',
            'signinlogoheader',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 1]
        );
    }

    if ($draftitemid = file_get_submitted_draft_itemid('signinlogofooter')) {
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_booking',
            'signinlogofooter',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 1]
        );
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
    booking::purge_cache_for_booking_instance_by_cmid($cmid, false, false, false);

    return $booking->id;
}

/**
 * Given an object containing all the necessary data this will update an existing instance.
 *
 * @param stdClass|booking $booking
 * @return bool
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
        $booking->optionsfields = MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS;
    }

    if (
        isset($booking->optionsdownloadfields)
        && is_array($booking->optionsdownloadfields)
        && count($booking->optionsdownloadfields) > 0
    ) {
        $booking->optionsdownloadfields = implode(',', $booking->optionsdownloadfields);
    } else {
        $booking->optionsdownloadfields = MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS;
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

    if (!empty($booking->signinlogoheader)) {
        file_save_draft_area_files(
            $booking->signinlogoheader,
            $context->id,
            'mod_booking',
            'signinlogoheader',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 1]
        );
    }

    if (!empty($booking->signinlogofooter)) {
        file_save_draft_area_files(
            $booking->signinlogofooter,
            $context->id,
            'mod_booking',
            'signinlogofooter',
            $booking->id,
            ['subdirs' => false, 'maxfiles' => 1]
        );
    }

    if (!empty($booking->myfilemanager)) {
        file_save_draft_area_files(
            $booking->myfilemanager,
            $context->id,
            'mod_booking',
            'myfilemanager',
            $booking->id,
            ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 50]
        );
    }

    if (!empty($booking->bookingimages)) {
        file_save_draft_area_files(
            $booking->bookingimages,
            $context->id,
            'mod_booking',
            'bookingimages',
            $booking->id,
            ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 500]
        );
    }

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

    $booking->bookedtext = $booking->bookedtext['text'] ?? $booking->bookedtext ?? null;
    $booking->waitingtext = $booking->waitingtext['text'] ?? $booking->waitingtext ?? null;
    $booking->notifyemail = $booking->notifyemail['text'] ?? $booking->notifyemail ?? null;
    $booking->notifyemailteachers = $booking->notifyemailteachers['text'] ?? $booking->notifyemailteachers ?? null;
    $booking->statuschangetext = $booking->statuschangetext['text'] ?? $booking->statuschangetext ?? null;
    $booking->deletedtext = $booking->bookingchangedtext['text'] ?? $booking->bookingchangedtext ?? null;
    $booking->bookingchangedtext = $booking->bookingchangedtext['text'] ?? $booking->bookingchangedtext ?? null;
    $booking->pollurltext = $booking->pollurltext['text'] ?? $booking->pollurltext ?? null;
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'] ?? $booking->pollurlteacherstext ?? null;
    $booking->activitycompletiontext = $booking->activitycompletiontext['text'] ?? $booking->activitycompletiontext ?? null;
    $booking->userleave = $booking->userleave['text'] ?? $booking->userleave ?? null;

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
    // View param (list view or card view) is also stored in JSON.
    if (empty($booking->viewparam)) {
        // Save list view as default value.
        booking::add_data_to_json($booking, "viewparam", MOD_BOOKING_VIEW_PARAM_LIST);
    } else {
        booking::add_data_to_json($booking, "viewparam", $booking->viewparam);
    }
    if (empty($booking->disablebooking)) {
        // This will store the correct JSON to $optionvalues->json.
        booking::remove_key_from_json($booking, "disablebooking");
    } else {
        booking::add_data_to_json($booking, "disablebooking", 1);
    }
    if (empty($booking->overwriteblockingwarnings)) {
        // This will store the correct JSON to $optionvalues->json.
        booking::remove_key_from_json($booking, "overwriteblockingwarnings");
    } else {
        booking::add_data_to_json($booking, "overwriteblockingwarnings", 1);
    }
    if (empty($booking->billboardtext)) {
        // This will store the correct JSON to $optionvalues->json.
        booking::remove_key_from_json($booking, "billboardtext");
    } else {
        booking::add_data_to_json($booking, "billboardtext", $booking->billboardtext);
    }
    if (empty($booking->cancelrelativedate)) {
        // If date is not relative, delete given entries for relative dates.
        $booking->allowupdatedays = "0";
        booking::add_data_to_json($booking, "cancelrelativedate", $booking->cancelrelativedate);
        booking::add_data_to_json($booking, "allowupdatetimestamp", $booking->allowupdatetimestamp);
    } else {
        booking::add_data_to_json($booking, "cancelrelativedate", $booking->cancelrelativedate);
        booking::remove_key_from_json($booking, "allowupdatetimestamp");
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
    booking::purge_cache_for_booking_instance_by_cmid($cm->id);

    return $DB->update_record('booking', $booking);
}

/**
 * Extend booking user navigation
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass $course
 *
 * @return void
 *
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
        $bookingisteacher = booking_check_if_teacher($option->option);
    }

    if (!$course) {
        return;
    }

    // Set the returnurl to navigate back to after form is saved.
    $viewphpurl = new moodle_url('/mod/booking/view.php', ['id' => $cm->id]);
    $returnurl = $viewphpurl->out();

    if (has_capability('mod/booking:updatebooking', $context)) {
        $navref->add(
            get_string('createnewbookingoption', 'booking'),
            // For a new booking option, optionid needs to be empty.
            new moodle_url(
                '/mod/booking/editoptions.php',
                [
                    'id' => $cm->id,
                    'optionid' => '',
                    'returnto' => 'url',
                    'returnurl' => $returnurl,
                ]
            ),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_createnewbookingoption'
        );
    }

    if (
        has_capability('mod/booking:manageoptiontemplates', $context)
        || has_capability('mod/booking:updatebooking', $context)
        || has_capability('mod/booking:addeditownoption', $context)
        || has_capability('mod/booking:subscribeusers', $context)
        || has_capability('mod/booking:readresponses', $context)
        || $bookingisteacher
    ) {
        if (has_capability('mod/booking:manageoptiontemplates', $context)) {
            if (empty($optionid)) {
                // We only want to show this in instance mode.
                $navref->add(
                    get_string('saveinstanceastemplate', 'mod_booking'),
                    new moodle_url(
                        '/mod/booking/instancetemplateadd.php',
                        ['id' => $cm->id]
                    ),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'nav_saveinstanceastemplate'
                );

                $navref->add(
                    get_string("managecustomreporttemplates", "mod_booking"),
                    new moodle_url(
                        '/mod/booking/customreporttemplates.php',
                        ['id' => $cm->id]
                    ),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'nav_managecustomreporttemplates'
                );
            }
        }
    }

    $urlparam = ['id' => $cm->id, 'optionid' => -1];
    if (!$templateid = $DB->get_field('booking', 'templateid', ['id' => $cm->instance])) {
        $templateid = get_config('booking', 'defaulttemplate');
    }
    if (!empty($templateid) && $DB->record_exists('booking_options', ['id' => $templateid])) {
        $urlparam['copyoptionid'] = $templateid;
    }

    if (has_capability('mod/booking:updatebooking', $context)) {
        $navref->add(
            get_string('importcsvbookingoption', 'mod_booking'),
            new moodle_url('/mod/booking/importoptions.php', ['id' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_importcsvbookingoption'
        );
        $navref->add(
            get_string('tagtemplates', 'mod_booking'),
            new moodle_url('/mod/booking/tagtemplates.php', ['id' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_tagtemplates'
        );
        $navref->add(
            get_string('importexcelbutton', 'mod_booking'),
            new moodle_url('/mod/booking/importexcel.php', ['id' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_importexcelbutton'
        );
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
        // TODO: Add capability for changesemester. Only admins should be allowed to do this!
        $navref->add(
            get_string('changesemester', 'mod_booking'),
            new moodle_url('/mod/booking/semesters.php', ['id' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_changesemester'
        );
        $navref->add(
            get_string('recalculateprices', 'mod_booking'),
            new moodle_url('/mod/booking/recalculateprices.php', ['id' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_recalculateprices'
        );
        $navref->add(
            get_string('teachersinstancereport', 'mod_booking') . " ($bookingsettings->name)",
            new moodle_url('/mod/booking/teachers_instance_report.php', ['cmid' => $cm->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_teachers_instance_report'
        );
        // Pro version entries - visible to all, but greyed out for non-pro users.
        $proversion = wb_payment::pro_version_is_activated();

        // Option Form Config.
        $optionformconfignode = $navref->add(
            get_string('optionformconfig', 'mod_booking') . " ($bookingsettings->name)"
            . '&nbsp;<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>',
            new moodle_url(
                '/mod/booking/optionformconfig.php',
                ['cmid' => $cm->id]
            ),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_optionformconfig'
        );

        if (!$proversion) {
            $optionformconfignode->add_class('disabled-profeature');  // Add a custom class for non-pro users.
        }

        // Booking Rules.
        if (has_capability('mod/booking:editbookingrules', $context)) {
            $bookingrulesnode = $navref->add(
                get_string('bookingrules', 'mod_booking') . " ($bookingsettings->name)"
                . '&nbsp;<span class="badge bg-success text-light"><i class="fa fa-cogs" aria-hidden="true"></i> PRO</span>',
                new moodle_url(
                    '/mod/booking/edit_rules.php',
                    ['cmid' => $cm->id]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_editbookingrules'
            );

            if (!$proversion) {
                $bookingrulesnode->add_class('disabled-profeature');  // Add a custom class for non-pro users.
            }
        }
    }

    // We currently never show these entries as we are not sure if they work correctly.
    // Filters, Permissions, Backup, Restore - will not be shown in "More..." menu.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /* $keys = $navref->get_children_key_list();
    foreach ($keys as $key => $name) {
        if ($name == 'roleassign' || $name == 'roleoverride' || $name == 'rolecheck' ||
            $name == 'filtermanage' || $name == 'logreport' ||
            $name == 'backup' || $name == 'restore') {
            $navref->get($name)->remove();
        }
    } */

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

        if (
            has_capability('mod/booking:updatebooking', $context)
            || has_capability('mod/booking:addeditownoption', $context)
        ) {
            $navref->add(
                get_string('editbookingoption', 'mod_booking'),
                new moodle_url(
                    '/mod/booking/editoptions.php',
                    ['id' => $cm->id, 'optionid' => $optionid]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_edit'
            );
            $navref->add(
                get_string('manageresponses', 'mod_booking'),
                new moodle_url(
                    '/mod/booking/report.php',
                    ['id' => $cm->id, 'optionid' => $optionid]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_manageresponses'
            );
        }
        if (has_capability('mod/booking:updatebooking', $context)) {
            $navref->add(
                get_string('duplicatebooking', 'booking'),
                new moodle_url(
                    '/mod/booking/editoptions.php',
                    ['id' => $cm->id, 'optionid' => -1, 'copyoptionid' => $optionid]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_duplicatebooking'
            );
        }

        if (has_capability('mod/booking:subscribeusers', $context)) {
            $navref->add(
                get_string('bookotherusers', 'booking'),
                new moodle_url(
                    '/mod/booking/subscribeusers.php',
                    ['id' => $cm->id, 'optionid' => $optionid]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_bookotherusers'
            );
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $navref->add(
                    get_string('bookuserswithoutcompletedactivity', 'booking'),
                    new moodle_url(
                        '/mod/booking/subscribeusersactivity.php',
                        ['id' => $cm->id, 'optionid' => $optionid]
                    ),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'nav_bookuserswithoutcompletedactivity'
                );
            }
        }
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
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

        // Won't be checked if it's a template.
        if ($booking) {
            if (
                has_capability('mod/booking:readresponses', $context)
                || booking_check_if_teacher($option)
            ) {
                $completion = new \completion_info($course);
                if (
                    $booking->enablecompletion > 0
                    && (
                        $completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC
                        || $completion->is_enabled($cm) == COMPLETION_TRACKING_MANUAL
                    )
                ) {
                    $navref->add(
                        get_string('confirmuserswith', 'booking'),
                        new moodle_url(
                            '/mod/booking/confirmactivity.php',
                            ['id' => $cm->id, 'optionid' => $optionid]
                        ),
                        navigation_node::TYPE_CUSTOM,
                        null,
                        'nav_confirmuserswith'
                    );
                }
            }
            if (
                has_capability('mod/booking:updatebooking', context_module::instance($cm->id))
                && $booking->conectedbooking > 0
            ) {
                $navref->add(
                    get_string('editotherbooking', 'booking'),
                    new moodle_url(
                        '/mod/booking/otherbooking.php',
                        ['id' => $cm->id, 'optionid' => $optionid]
                    ),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'nav_editotherbooking'
                );
            }
        }

        if (has_capability('mod/booking:updatebooking', $context)) {
            $navref->add(
                get_string('deletethisbookingoption', 'mod_booking'),
                new moodle_url(
                    '/mod/booking/report.php',
                    [
                        'id' => $cm->id,
                        'optionid' => $optionid,
                        'action' => 'deletebookingoption',
                        'sesskey' => sesskey(),
                    ]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_deletebookingoption'
            );
        }
    }

    if (has_capability('mod/booking:manageoptiontemplates', $context)) {
        if (!empty($optionid)) {
            $navref->add(
                get_string('copytotemplate', 'mod_booking'),
                new moodle_url(
                    '/mod/booking/report.php',
                    [
                        'id' => $cm->id,
                        'optionid' => $optionid,
                        'action' => 'copytotemplate',
                        'sesskey' => sesskey(),
                    ]
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'nav_copytotemplate'
            );
        }

        $navref->add(
            get_string("manageoptiontemplates", "mod_booking"),
            new moodle_url(
                '/mod/booking/optiontemplatessettings.php',
                ['id' => $cm->id]
            ),
            navigation_node::TYPE_CUSTOM,
            null,
            'nav_manageoptiontemplates'
        );
    }
}

/**
 * Check if logged in user is a teacher of the passed option.
 * @param mixed|int $optionoroptionid optional option class or optionid
 * @return true if is assigned as teacher otherwise return false
 */
function booking_check_if_teacher($optionoroptionid = null) {
    global $DB, $USER;

    if (empty($optionoroptionid)) {
        // If we have no option, we check, if the teacher is a teacher of ANY option.
        $user = $DB->get_records(
            'booking_teachers',
            ['userid' => $USER->id]
        );
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
        } else if (
            get_config('booking', 'responsiblecontactcanedit')
            && $settings->responsiblecontact == $USER->id
        ) {
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
    [$course, $cm] = get_course_and_cm_from_cmid($cmid, "booking");

    require_once($CFG->libdir . '/completionlib.php');
    $completion = new \completion_info($course);

    foreach ($selectedusers as $uid) {
        foreach ($uid as $ui) {
            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // TODO: Optimization of db query: instead of loop, one get_records query.
            $userdata = $DB->get_record(
                'booking_teachers',
                ['optionid' => $optionid, 'userid' => $ui]
            );

            if ($userdata->completed == '1') {
                $userdata->completed = '0';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records(
                    'booking_teachers',
                    ['bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1']
                );

                if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
                    $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
                }
            } else {
                $userdata->completed = '1';

                $DB->update_record('booking_teachers', $userdata);
                $countcomplete = $DB->count_records(
                    'booking_teachers',
                    ['bookingid' => $booking->id, 'userid' => $ui, 'completed' => '1']
                );

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
          WHERE optionid = :optionid
            AND waitinglist < 2",
        ['optionid' => $optionid]
    );

    if (!empty($allselectedusers)) {
        $tmprecnum = $DB->get_record_sql(
            "SELECT numrec
               FROM {booking_answers}
              WHERE optionid = :optionid
                AND waitinglist < 2
           ORDER BY numrec DESC
              LIMIT 1",
            ['optionid' => $optionid]
        );

        // If NO users or ALL users are selected, we always want to start with 1.
        if ($tmprecnum->numrec == 0 || count($allselectedusers) == $answerscount) {
            $recnum = 1;
        } else {
            $recnum = $tmprecnum->numrec + 1;
        }

        foreach ($allselectedusers as $userid) {
            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // TODO: Optimize DB query: get_records instead of loop.
            $userdata = $DB->get_record_sql(
                "SELECT *
                   FROM {booking_answers}
                  WHERE optionid = :optionid
                    AND userid = :userid
                    AND waitinglist < 2",
                ['optionid' => $optionid, 'userid' => $userid]
            );

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
              WHERE optionid = :optionid
                AND waitinglist < 2
           ORDER BY {$random}",
            ['optionid' => $optionid]
        );

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
            "SELECT *
               FROM {booking_answers}
              WHERE optionid = :optionid
                AND userid = :selecteduser
                AND waitinglist <> 5", // Waitinglist 5 means deleted.
            ['optionid' => $optionid, 'selecteduser' => $selecteduser]
        );

        if ($userdata->completed == '1') {
            $userdata->completed = '0';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);
            $countcomplete = $DB->count_records(
                'booking_answers',
                ['bookingid' => $booking->id, 'userid' => $selecteduser, 'completed' => '1']
            );

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
            $countcomplete = $DB->count_records(
                'booking_answers',
                ['bookingid' => $booking->id, 'userid' => $selecteduser, 'completed' => '1']
            );

            if ($completion->is_enabled($cm) && $booking->enablecompletion <= $countcomplete) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $selecteduser);
            }
        }
    }

    // After activity completion, we need to purge caches for the option.
    booking_option::purge_cache_for_answers($optionid);
}

// GRADING AND RATING.

/**
 * Return grade for given user or all users.
 *
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
 * @param bool $nullifnone return null if grade does not exist
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

    $params = ['itemname' => $booking->name, 'idnumber' => $booking->cmidnumber ?? ''];

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

    return grade_update(
        'mod/booking',
        $booking->course,
        'mod',
        'booking',
        $booking->id,
        0,
        $grades,
        $params
    );
}

/**
 * Delete grade item
 *
 * @param object $booking
 *
 * @return int
 *
 */
function booking_grade_item_delete($booking) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/booking',
        $booking->course,
        'mod',
        'booking',
        $booking->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * This function returns if a scale is being used by the booking instance
 *
 * @param int $bookingid
 * @param int $scaleid negative number
 *
 * @return bool
 *
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
 * @param int $scaleid
 *
 * @return bool True if the scale is used by any booking instance
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
 * @param int $contextid
 * @param string $component
 * @param string $ratingarea
 *
 * @return array|null an associative array of the user's rating permissions
 *
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
 * @return bool true if the rating is valid. Will throw rating_exception if not
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
    $answer = $DB->get_record(
        'booking_answers',
        ['id' => $params['itemid'], 'userid' => $params['rateduserid']],
        '*',
        MUST_EXIST
    );
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
        if (
            $answer->timecreated < $booking->assesstimestart
            || $answer->timecreated > $booking->assesstimefinish
        ) {
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

    [$context, $course, $cm] = get_context_info_array($params->contextid);
    require_login($course, false, $cm);

    $contextid = null; // Now we have a context object, throw away the id from the user.

    $rm = new rating_manager();

    // Check the module rating permissions.
    // Doing this check here rather than within rating_manager::get_ratings() so we can choose how to handle the error.
    $pluginpermissionsarray = $rm->get_plugin_permissions_array(
        $context->id,
        'mod_booking',
        $ratingarea
    );

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
 * @return bool
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
            $imgfile = $fs->get_file(
                $fileinfo['contextid'],
                $fileinfo['component'],
                $fileinfo['filearea'],
                $fileinfo['itemid'],
                $fileinfo['filepath'],
                $fileinfo['filename']
            );
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
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
        // TODO: this should be moved into delete_booking_option.
        teachers_handler::delete_booking_optiondates_teachers_by_bookingid($booking->id);
    }

    // We also need to delete the booking teachers in the booking_teachers table!
    if (!$DB->delete_records('booking_teachers', ["bookingid" => "$booking->id"])) {
        $result = false;
    }

    // Delete any entity relations for the booking instance.
    // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
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
    booking::purge_cache_for_booking_instance_by_cmid($cm->id);

    return $result;
}

/**
 * Booking get option text.
 *
 * @param object $booking
 * @param int $id
 *
 * @return string
 *
 */
function booking_get_option_text($booking, $id) {
    global $DB, $USER;
    // Returns text string which is the answer that matches the id.
    if (
        $result = $DB->get_records_sql(
            "SELECT bo.text
               FROM {booking_options} bo
          LEFT JOIN {booking_answers} ba
                 ON ba.optionid = bo.id
              WHERE bo.bookingid = :bookingid
                AND ba.userid = :userid;",
            ["bookingid" => $booking->id, "userid" => $USER->id]
        )
    ) {
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
 * @param object $mform form passed by reference
 *
 * @return void
 */
function booking_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'bookingheader', get_string('modulenameplural', 'booking'));
    $mform->addElement('advcheckbox', 'reset_booking', get_string('removeresponses', 'booking'));
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course
 *
 * @return array
 *
 */
function booking_reset_course_form_defaults($course) {
    return ['reset_booking' => 1];
}

/**
 * Booking pretty duration.
 *
 * @param int $seconds
 *
 * @return string
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
 * Returns all other caps used in module
 */
function booking_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * Booking show subcategories.
 *
 * @param int $catid
 * @param int $courseid
 *
 * @return void
 *
 */
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
 * This will create the options list on the coursepage.
 *
 * @param cm_info $cm
 * @return void
 */
function mod_booking_cm_info_view(cm_info $cm) {
    global $PAGE;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cm->id);
    $html = '';
    if (
        isset($bookingsettings->showlistoncoursepage)
        && ($bookingsettings->showlistoncoursepage == 1 || $bookingsettings->showlistoncoursepage == 2)
    ) {
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

/**
 * Helper function to check if a string is valid JSON.
 *
 * @param string $string the string to check
 *
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

// With this function, we can execute code at the last moment.
register_shutdown_function(function () {

    // To avoid loops, we need a counter.

    $counter = 0;

    while (
        (count(rules_info::$rulestoexecute) > 0
        || count(rules_info::$eventstoexecute) > 0)
        && $counter < 10
    ) {
        rules_info::filter_rules_and_execute();

        rules_info::events_to_execute();
        $counter++;
    }
});
