<?php

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/booking/icallib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/tag/locallib.php');

require_once($CFG->dirroot . '/question/category_class.php');

require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/user/selector/lib.php');

//require_once('locallib.php');

$COLUMN_HEIGHT = 300;

/// Standard functions /////////////////////////////////////////////////////////

function booking_cron() {
    global $DB, $USER, $CFG;

    mtrace('Starting cron for Booking ...');

    $toProcess = $DB->count_records_select('booking_options', "sent = 0 AND daystonotify > 0 AND coursestarttime > 0");

    if ($toProcess > 0) {
        $allToSend = $DB->get_records_select('booking_options', "sent = 0 AND daystonotify > 0 AND coursestarttime > 0");
        foreach ($allToSend as $value) {

            $dateEvent = new DateTime();
            $dateEvent->setTimestamp($value->coursestarttime);
            $dateNow = new DateTime();

            $dateEvent->modify('-' . $value->daystonotify . ' day');

            if ($dateEvent < $dateNow) {
                $booking = $DB->get_record('booking', array('id' => $value->bookingid));

                $value->sent = 1;

                booking_send_notification($value->id, get_string('notificationsubject', 'booking'));

                $DB->update_record("booking_options", $value);
            }
        }
    }

    mtrace('Ending cron for Booking ...');

    return true;
}

function booking_get_coursemodule_info($cm) {

    global $CFG, $DB;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $tags = new booking_tags($cm);
    $info = new cached_cm_info();

    $booking = new booking($cm->id);
    $booking->apply_tags();

    $info->name = $booking->booking->name;

    return $info;
}

function booking_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $CFG, $DB;

    // Check the contextlevel is as expected - if your plugin is a block, this becomes CONTEXT_BLOCK, etc.
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'myfilemanager') {
        return false;
    }

    // Make sure the user is logged in and has access to the module (plugins that are not course modules should leave out the 'cm' part).
    require_login($course, true, $cm);

    // Leave this line out if you set the itemid to null in make_pluginfile_url (set $itemid to 0 instead).
    $itemid = array_shift($args); // The first item in the $args array.
    // Use the itemid to retrieve any relevant data records and perform any security checks to see if the
    // user really does have access to the file in question.
    // Extract the filename / filepath from the $args array.
    $filename = array_pop($args); // The last item in the $args array.
    if (!$args) {
        $filepath = '/'; // $args is empty => the path is '/'
    } else {
        $filepath = '/' . implode('/', $args) . '/'; // $args contains elements of the filepath
    }

    // Retrieve the file from the Files API.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_booking', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false; // The file does not exist.
    }

    // We can now send the file back to the browser - in this case with a cache lifetime of 1 day and no filtering. 
    // From Moodle 2.3, use send_stored_file instead.
    //send_file($file, 86400, 0, $forcedownload, $options);
    send_stored_file($file, 0, 0, true, $options);
}

function booking_user_outline($course, $user, $mod, $booking) {
    global $DB;
    if ($answer = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $user->id))) {
        $result = new stdClass();
        $result->info = "'" . format_string(booking_get_option_text($booking, $answer->optionid)) . "'";
        $result->time = $answer->timemodified;
        return $result;
    }
    return NULL;
}

function booking_user_complete($course, $user, $mod, $booking) {
    global $DB;
    if ($answer = $DB->get_record('booking_answers', array("bookingid" => $booking->id, "userid" => $user->id))) {
        $result = new stdClass();
        $result->info = "'" . format_string(booking_get_option_text($booking, $answer->optionid)) . "'";
        $result->time = $answer->timemodified;
        echo get_string("answered", "booking") . ": $result->info. " . get_string("updated", '', userdate($result->time));
    } else {
        print_string("notanswered", "booking");
    }
}

function booking_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS: return false;
        case FEATURE_GROUPINGS: return false;
        case FEATURE_GROUPMEMBERSONLY: return false;
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
        case FEATURE_COMPLETION_HAS_RULES: return true;
        case FEATURE_GRADE_HAS_GRADE: return false;
        case FEATURE_GRADE_OUTCOMES: return false;
        case FEATURE_BACKUP_MOODLE2: return true;

        default: return null;
    }
}

function booking_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;

    // Get forum details
    if (!($booking = $DB->get_record('booking', array('id' => $cm->instance)))) {
        throw new Exception("Can't find booking {$cm->instance}");
    }

    if ($booking->enablecompletion) {
        $user = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $userid, 'completed' => '1'));

        if ($user === FALSE) {
            return FALSE;
        } else {
            return TRUE;
        }
    } else {
        return $type;
    }
}

function booking_add_instance($booking) {
    global $DB;
    // Given an object containing all the necessary data,
    // (defined by the form in mod.html) this function
    // will create a new instance and return the id number
    // of the new instance.

    $booking->timemodified = time();

    if (isset($booking->additionalfields) && count($booking->additionalfields) > 0) {
        $booking->additionalfields = implode(',', $booking->additionalfields);
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    }

    if (empty($booking->timerestrict)) {
        $booking->timeopen = 0;
        $booking->timeclose = 0;
    }

    // Copy the text fields out:
    $booking->bookedtext = $booking->bookedtext['text'];
    $booking->waitingtext = $booking->waitingtext['text'];
    $booking->statuschangetext = $booking->statuschangetext['text'];
    $booking->deletedtext = $booking->deletedtext['text'];
    $booking->pollurltext = $booking->pollurltext['text'];
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'];
    $booking->notificationtext = $booking->notificationtext['text'];
    $booking->userleave = $booking->userleave['text'];

    //insert answer options from mod_form
    $booking->id = $DB->insert_record("booking", $booking);

    $cmid = $booking->coursemodule;
    $context = context_module::instance($cmid);

    if ($draftitemid = file_get_submitted_draft_itemid('myfilemanager')) {
        file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanager', $booking->id, array('subdirs' => false, 'maxfiles' => 50));
    }

    tag_set('booking', $booking->id, $booking->tags);

    if (!empty($booking->option)) {
        foreach ($booking->option as $key => $value) {
            $value = trim($value);
            if (isset($value) && $value <> '') {
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

    return $booking->id;
}

function booking_update_instance($booking) {
    global $DB;
    // Given an object containing all the necessary data,
    // (defined by the form in mod.html) this function
    // will update an existing instance with new data.
    // we have to prepare the bookingclosingtimes as an $arrray, currently they are in $booking as $key (string)
    $booking->id = $booking->instance;
    $booking->timemodified = time();

    if (isset($booking->additionalfields) && count($booking->additionalfields) > 0) {
        $booking->additionalfields = implode(',', $booking->additionalfields);
    }

    if (isset($booking->categoryid) && count($booking->categoryid) > 0) {
        $booking->categoryid = implode(',', $booking->categoryid);
    }

    tag_set('booking', $booking->id, $booking->tags);

    $cm = get_coursemodule_from_instance('booking', $booking->id);
    $context = context_module::instance($cm->id);
    file_save_draft_area_files($booking->myfilemanager, $context->id, 'mod_booking', 'myfilemanager', $booking->id, array('subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 50));

    if (empty($booking->timerestrict)) {
        $booking->timeopen = 0;
        $booking->timeclose = 0;
    }

    // Copy the text fields out:
    $booking->bookedtext = $booking->bookedtext['text'];
    $booking->waitingtext = $booking->waitingtext['text'];
    $booking->statuschangetext = $booking->statuschangetext['text'];
    $booking->deletedtext = $booking->deletedtext['text'];
    $booking->pollurltext = $booking->pollurltext['text'];
    $booking->pollurlteacherstext = $booking->pollurlteacherstext['text'];
    $booking->notificationtext = $booking->notificationtext['text'];
    $booking->userleave = $booking->userleave['text'];

    //update, delete or insert answers
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
            if (isset($booking->optionid[$key]) && !empty($booking->optionid[$key])) {//existing booking record
                $option->id = $booking->optionid[$key];
                if (isset($value) && $value <> '') {
                    $DB->update_record("booking_options", $option);
                } else { //empty old option - needs to be deleted.
                    $DB->delete_records("booking_options", array("id" => $option->id));
                }
            } else {
                if (isset($value) && $value <> '') {
                    $DB->insert_record("booking_options", $option);
                }
            }
        }
    }

    return $DB->update_record('booking', $booking);
}

function booking_update_options($optionvalues) {
    global $DB, $CFG;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $bokingUtils = new booking_utils();
    
    $booking = $DB->get_record('booking', array('id' => $optionvalues->bookingid));

    $option = new stdClass();
    $option->bookingid = $optionvalues->bookingid;
    $option->text = trim($optionvalues->text);

    $option->sent = 0;

    $option->location = trim($optionvalues->location);
    $option->institution = trim($optionvalues->institution);
    $option->address = trim($optionvalues->address);

    $option->daystonotify = $optionvalues->daystonotify;
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
    if (isset($optionvalues->optionid) && !empty($optionvalues->optionid) && $optionvalues->id != "add") {//existing booking record
        $option->id = $optionvalues->optionid;
        if (isset($optionvalues->text) && $optionvalues->text <> '') {
            $event = new stdClass();
            $event->id = $DB->get_field('booking_options', 'calendarid', array('id' => $option->id));
            $groupid = $DB->get_field('booking_options', 'groupid', array('id' => $option->id));
            $coursestarttime = $DB->get_field('booking_options', 'coursestarttime', array('id' => $option->id));

            if ($coursestarttime != $optionvalues->coursestarttime) {
                $option->sent = 0;
            } else {
                $option->sent = $DB->get_field('booking_options', 'sent', array('id' => $option->id));
            }

            $option->groupid = $bokingUtils->group($booking, $option);

            $whereis = '';
            if (strlen($option->location) > 0) {
                $whereis = '<p>' . get_string('location', 'booking') . ': ' . $option->location . '</p>';
            }

            if ($event->id > 0) {
                // event exist
                if (isset($optionvalues->addtocalendar)) {
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
                        $tmpEvent = calendar_event::create($event);
                        $option->calendarid = $tmpEvent->id;
                    }
                } else {
                    // Delete event if exist
                    $event = calendar_event::load($event->id);
                    $event->delete(true);

                    $option->addtocalendar = 0;
                    $option->calendarid = 0;
                }
            } else {
                $option->addtocalendar = 0;
                $option->calendarid = 0;
                // Insert into calendar
                if (isset($optionvalues->addtocalendar)) {
                    $event = new stdClass;
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

                    $tmpEvent = calendar_event::create($event);
                    $option->calendarid = $tmpEvent->id;
                    $option->addtocalendar = $optionvalues->addtocalendar;
                }
            }

            return $DB->update_record("booking_options", $option);
        }
    } elseif (isset($optionvalues->text) && $optionvalues->text <> '') {
        $option->addtocalendar = 0;
        $option->calendarid = 0;
        // Insert into calendar
        // We add a new booking_options?

        $whereis = '';
        if (strlen($option->location) > 0) {
            $whereis = '<p>' . get_string('location', 'booking') . ': ' . $option->location . '</p>';
        }

        if (isset($optionvalues->addtocalendar)) {
            $event = new stdClass;
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
            $event->timeduration = $option->courseendtime;

            $tmpEvent = calendar_event::create($event);
            $option->calendarid = $tmpEvent->id;
            $option->addtocalendar = $optionvalues->addtocalendar;
        }

        $option->groupid = $bokingUtils->group($booking, $option);

        return $DB->insert_record("booking_options", $option);
    }
}

/**
 * Checks the status of the specified user
 * @param $userid userid of the user
 * @param $optionid booking option to check
 * @param $bookingid booking id
 * @param $cmid course module id
 * @return localised string of user status
 */
function booking_get_user_status($userid, $optionid, $bookingid, $cmid) {
    global $DB;
    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $current = $DB->get_record('booking_answers', array('bookingid' => $bookingid, 'userid' => $userid, 'optionid' => $optionid));
    $allresponses = $DB->get_records_select('booking_answers', "bookingid = $bookingid AND optionid = $optionid", array(), 'timemodified', 'userid');
    //$context  = get_context_instance(CONTEXT_MODULE,$cmid);
    $context = context_module::instance($cmid);
    $i = 1;
    if (!empty($allresponses)) {
        foreach ($allresponses as $answer) {
            if (has_capability('mod/booking:choose', $context, $answer->userid)) {
                $sortedresponses[$i++] = $answer->userid;
            }
        }
        $useridaskey = array_flip($sortedresponses);
        if ($option->limitanswers) {
            if (empty($useridaskey[$userid])) {
                $status = get_string('notbooked', 'booking');
            } else if ($useridaskey[$userid] > $option->maxanswers + $option->maxoverbooking) {
                $status = "Problem, please contact the admin";
            } elseif (($useridaskey[$userid]) > $option->maxanswers) { // waitspaceavailable
                $status = get_string('onwaitinglist', 'booking');
            } elseif ($useridaskey[$userid] <= $option->maxanswers) {
                $status = get_string('booked', 'booking');
            } else {
                $status = get_string('notbooked', 'booking');
            }
        } else {
            if (!empty($useridaskey[$userid])) {
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
 * @param object $booking
 * @param object $user
 * @param object[] $bookinglist
 * @return string
 */
function booking_show_maxperuser($booking, $user, $bookinglist) {
    if (!$booking->maxperuser) {
        return ''; // No per-user limits.
    }

    $outdata = new stdClass();
    $outdata->limit = $booking->maxperuser;
    $outdata->count = booking_get_user_booking_count($booking, $user, $bookinglist);

    return html_writer::tag('p', get_string('maxperuserwarning', 'mod_booking', $outdata));
}

/**
 * determins the number of bookings that a single user has already made
 * in all booking options
 *
 * @param object $booking
 * @param object $user
 * @param object[] $bookinglist
 * @return number of bookings made by user
 */
function booking_get_user_booking_count($booking, $user, $bookinglist) {
    $count = 0;
    $now = time();
    foreach ($bookinglist as $optionid => $optbookings) {
        if (!isset($booking->option[$optionid])) {
            continue; // Booking not for one of the available options (shouldn't happen?)
        }
        if ($booking->option[$optionid]->courseendtime < $now && $booking->option[$optionid]->courseendtimetext !== get_string('endtimenotset', 'booking')) {
            continue; // Booking is in the past - ignore it.
        }
        foreach ($optbookings as $optbooking) {
            if ($optbooking->id == $user->id) {
                $count++; // Current booking for this user.
            }
        }
    }
    return $count;
}

/**
 * Echoes HTML code for booking table with all booking options and booking status
 * @param $booking object containing complete details of the booking instance
 * @param $user object of current user
 * @param $cm course module object
 * @param $allresponses array of all responses
 * @param $singleuser 0 show all booking options, 1 show only my booking options, 2 show only active
 * @param $mustReturn if TRUE return string instead of echo - for use with ajax call
 * @return void
 */
function booking_show_form($booking, $user, $cm, $allresponses, $singleuser = 0, $sorturl = '', $urlParams = array()) {
    global $DB, $OUTPUT;
    //$optiondisplay is an array of the display info for a booking $cdisplay[$optionid]->text  - text name of option.
    //                                                                            ->maxanswers -maxanswers for this option
    //                                                                            ->full - whether this option is full or not. 0=not full, 1=full
    //									      ->maxoverbooking - waitinglist places dor option
    //									      ->waitingfull - whether waitinglist is full or not 0=not, 1=full
    $bookingfull = false;
    $cdisplay = new stdClass();

    if ($booking->limitanswers) { //set bookingfull to true by default if limitanswers.
        $bookingfull = true;
        $waitingfull = true;
    }

    //$context = get_context_instance(CONTEXT_MODULE, $cm->id);
    $context = context_module::instance($cm->id);
    $table = NULL;
    $displayoptions = new stdClass();
    $displayoptions->para = false;
    $tabledata = array();
    $current = array();
    $rowclasses = array();

    $hidden = "";

    foreach ($urlParams as $key => $value) {
        if (!in_array($key, array('searchText', 'searchLocation', 'searchInstitution'))) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(array(get_string('booking', "booking"), $hidden . '<form><input value="' . $urlParams['searchText'] . '" type="text" name="searchText">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('location', "booking"), $hidden . '<input value="' . $urlParams['searchLocation'] . '" type="text" name="searchLocation">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('institution', "booking"), $hidden . '<input value="' . $urlParams['searchInstitution'] . '" type="text" name="searchInstitution">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array("", '<input type="submit" value="' . get_string('search') . '"></form>', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $underlimit = ($booking->maxperuser == 0);
    $underlimit = $underlimit || (booking_get_user_booking_count($booking, $user, $allresponses) < $booking->maxperuser);

    if (isset($booking->option)) {
        foreach ($booking->option as $option) {
            $optiondisplay = new stdClass();
            $optiondisplay->delete = "";
            $optiondisplay->button = "";
            $hiddenfields = array('answer' => $option->id);
            // determine the ranking in order of booking time. necessary to decide whether user is on waitinglist or in regular booking
            if (@$allresponses[$option->id]) {
                foreach ($allresponses[$option->id] as $rank => $userobject) {
                    if ($user->id == $userobject->id) {
                        $current[$option->id] = $rank; //ranking of the user in order of subscription time
                    }
                }
            }

            $inpast = $option->courseendtime && ($option->courseendtime < time());
            $extraclass = $inpast ? ' inpast' : '';

            if ($singleuser == 2 && $inpast) {
                // Nothing
            } else {
                if (!empty($current[$option->id])) {
                    if (!$option->limitanswers) {
                        if ($inpast) {
                            $optiondisplay->booked = get_string('bookedpast', 'booking');
                        } else {
                            $optiondisplay->booked = get_string('booked', 'booking');
                        }
                        $rowclasses[] = "mod-booking-booked" . $extraclass;
                        if ($booking->allowupdate and $option->status != 'closed') {
                            $buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
                            $url = new moodle_url('view.php', $buttonoptions);
                            $url->params($buttonoptions);
                            $optiondisplay->delete = $OUTPUT->single_button($url, get_string('cancelbooking', 'booking'), 'post') . '<br />';
                        }
                    } elseif ($current[$option->id] > $option->maxanswers + $option->maxoverbooking) {
                        $optiondisplay->booked = "Problem, please contact the admin";
                    } elseif ($current[$option->id] > $option->maxanswers) { // waitspaceavailable
                        $optiondisplay->booked = get_string('onwaitinglist', 'booking');
                        $rowclasses[] = "mod-booking-watinglist" . $extraclass;
                        if ($booking->allowupdate and $option->status != 'closed') {
                            $buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
                            $url = new moodle_url('view.php', $buttonoptions);
                            $optiondisplay->delete = $OUTPUT->single_button($url, get_string('cancelbooking', 'booking'), 'post') . '<br />';
                        }
                    } elseif ($current[$option->id] <= $option->maxanswers) {
                        if ($inpast) {
                            $optiondisplay->booked = get_string('bookedpast', 'booking');
                        } else {
                            $optiondisplay->booked = get_string('booked', 'booking');
                        }
                        $rowclasses[] = "mod-booking-booked" . $extraclass;
                        if ($booking->allowupdate and $option->status != 'closed') {
                            $buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
                            $url = new moodle_url('view.php', $buttonoptions);
                            $optiondisplay->delete = $OUTPUT->single_button($url, get_string('cancelbooking', 'booking'), 'post') . '<br />';
                        }
                    }
                    if (!$booking->allowupdate) {
                        $optiondisplay->button = "";
                    }
                } else {
                    $optiondisplay->booked = get_string('notbooked', 'booking');
                    if ($singleuser == 0) {
                        $rowclasses[] = $extraclass;
                    } else if ($singleuser == 1) {
                        $rowclasses[] = "mod-booking-invisible" . $extraclass;
                    } else {
                        $rowclasses[] = $extraclass;
                    }
                    $buttonoptions = array('answer' => $option->id, 'id' => $cm->id, 'sesskey' => $user->sesskey);
                    $url = new moodle_url('view.php', $buttonoptions);
                    $url->params($hiddenfields);
                    $optiondisplay->button = $OUTPUT->single_button($url, get_string('booknow', 'booking'), 'post');
                }
                if ($booking->option[$option->id]->limitanswers && ($booking->option[$option->id]->status == "full")) {
                    $optiondisplay->button = '';
                } elseif ($booking->option[$option->id]->status == "closed") {
                    $optiondisplay->button = '';
                }
                if (!$underlimit) {
                    $optiondisplay->button = '';
                }
                // check if user ist logged in
                if (has_capability('mod/booking:choose', $context, $user->id, false)) { //don't show booking button if the logged in user is the guest user.
                    $bookingbutton = $optiondisplay->button;
                } else {
                    $bookingbutton = get_string('havetologin', 'booking') . "<br />";
                }
                if (!$option->limitanswers) {
                    $stravailspaces = get_string("unlimited", 'booking');
                } else {
                    $stravailspaces = get_string("placesavailable", "booking") . ": " . $option->availspaces . " / " . $option->maxanswers . "<br />" . get_string("waitingplacesavailable", "booking") . ": " . $option->availwaitspaces . " / " . $option->maxoverbooking;
                }

                if (has_capability('mod/booking:readresponses', $context) || booking_check_if_teacher($option, $user)) {
                    $numberofresponses = 0;
                    if (isset($allresponses[$option->id])) {
                        $numberofresponses = count($allresponses[$option->id]);
                    }
                    $optiondisplay->manage = "<a href=\"report.php?id=$cm->id&optionid=$option->id\">" . get_string("viewallresponses", "booking", $numberofresponses) . "</a>";
                } else {
                    $optiondisplay->manage = "";
                } if (has_capability('mod/booking:subscribeusers', $context)) {
                    $optiondisplay->bookotherusers = "<a href=\"subscribeusers.php?id=$cm->id&optionid=$option->id\">" . get_string("bookotherusers", "booking") . "</a>";
                } else {
                    $optiondisplay->bookotherusers = "";
                }

                $cTeachers = $DB->count_records("booking_teachers", array("optionid" => $option->id, 'bookingid' => $option->bookingid));
                $teachers = $DB->get_records("booking_teachers", array("optionid" => $option->id, 'bookingid' => $option->bookingid));
                $niceTeachers = array();
                $printTeachers = "";

                if ($cTeachers > 0) {
                    $printTeachers = "<p>";
                    $printTeachers .= get_string('teachers', 'booking');

                    foreach ($teachers as $teacher) {
                        $tmpuser = $DB->get_record('user', array('id' => $teacher->userid));
                        $niceTeachers[] = fullname($tmpuser);
                    }

                    $printTeachers .= implode(', ', $niceTeachers);
                    $printTeachers .= "</p>";
                }

                $additionalInfo = '';
                if (strlen($option->location) > 0) {
                    $additionalInfo .= '<p>' . get_string('location', "booking") . ': ' . $option->location . '</p>';
                }
                if (strlen($option->institution) > 0) {
                    $additionalInfo .= '<p>' . get_string('institution', "booking") . ': ' . $option->institution . '</p>';
                }
                if (strlen($option->address) > 0) {
                    $additionalInfo .= '<p>' . get_string('address', "booking") . ': ' . $option->address . '</p>';
                }

                $row = new html_table_row(array("<span id=\"option{$option->id}\"></span>" . $bookingbutton . $optiondisplay->booked . '
		<br />' . get_string($option->status, "booking") . '
		<br />' . $optiondisplay->delete . $optiondisplay->manage . '
		<br />' . $optiondisplay->bookotherusers,
                    "<b>" . format_text($option->text . ' ', FORMAT_MOODLE, $displayoptions) . "</b>" . "<p>" . $option->description . "</p>" . $printTeachers . $additionalInfo,
                    $option->coursestarttimetext . " - <br />" . $option->courseendtimetext,
                    $stravailspaces));

                $tabledata[] = $row;
            }
        }
    }
    $table = new html_table();
    $table->attributes['class'] = 'box generalbox boxaligncenter boxwidthwide booking';
    $table->attributes['style'] = '';
    $table->data = array();
    $strselect = get_string("select", "booking");
    $strbooking = get_string("booking", "booking");
    if (strlen($booking->eventtype) > 0) {
        $strbooking = $booking->eventtype;
    }

    $strdate = '<a href="' . $sorturl . '">' . get_string("coursedate", "booking") . '</a>';
    $stravailability = get_string("availability", "booking");

    $table->head = array($strselect, $strbooking, $strdate, $stravailability);
    $table->align = array("left", "left", "left", "left");
    $table->rowclasses = $rowclasses;
    $table->data = $tabledata;

    echo (html_writer::table($table));
}

/**
 * Check if logged in user is in teachers db.
 * @return true if is assigned as teacher otherwise return false
 */
function booking_check_if_teacher($option, $user) {
    global $DB;

    $user = $DB->get_record('booking_teachers', array('bookingid' => $option->bookingid, 'userid' => $user->id, 'optionid' => $option->id));

    if ($user === FALSE) {
        return FALSE;
    } else {
        return TRUE;
    }
}

/**
 * Saves the booking for the user
 * @return true if booking was possible, false if meanwhile the booking got full
 */
function booking_user_submit_response($optionid, $booking, $user, $courseid, $cm) {
    global $DB;
    //$context = get_context_instance(CONTEXT_MODULE, $cm->id);
    $context = context_module::instance($cm->id);
    // check if optionid exists as real option
    if (!$DB->get_field('booking_options', 'id', array('id' => $optionid))) {
        return false;
    }
    if ($booking->option[$optionid]->limitanswers) {
        // retrieve all answers for this option ID
        $countanswers = Array();
        $answers[$optionid] = $DB->get_records("booking_answers", array("optionid" => $optionid));
        if ($answers[$optionid]) {
            $countanswers[$optionid] = 0;
            foreach ($answers[$optionid] as $a) {
                if (has_capability('mod/booking:choose', $context, $a->userid, false)) {
                    $countanswers[$optionid] ++;
                }
            }
        } else {
            $countanswers[$optionid] = 0;
        }
        $maxans[$optionid] = $booking->option[$optionid]->maxanswers + $booking->option[$optionid]->maxoverbooking;
        // if answers for one option are limited and total answers are not exceeded then
        if (!($booking->option[$optionid]->limitanswers && ($countanswers[$optionid] >= $maxans[$optionid]) )) {
            // check if actual answer is also already made by this user
            if (!($currentanswerid = $DB->get_field('booking_answers', 'id', array('userid' => $user->id, 'optionid' => $optionid)))) {
                $newanswer = new stdClass();
                $newanswer->bookingid = $booking->id;
                $newanswer->userid = $user->id;
                $newanswer->optionid = $optionid;
                $newanswer->timemodified = time();
                $newanswer->timecreated = time();
                if (!$DB->insert_record("booking_answers", $newanswer)) {
                    error("Could not register your booking because of a database error");
                }
                booking_check_enrol_user($booking->option[$optionid], $booking, $user->id);
            }
            //add_to_log($courseid, "booking", "choose", "view.php?id=$cm->id", $booking->id, $cm->id);
            $event = \mod_booking\event\bookingoption_booked::create(array(
                        'objectid' => $optionid,
                        'context' => context_module::instance($cm->id),
                        'relateduserid' => $user->id,
                        'other' => array('userid' => $user->id)
            ));
            $event->trigger();
            if ($booking->sendmail) {
                $eventdata = new stdClass();
                $eventdata->user = $user;
                $eventdata->booking = $booking;
                $eventdata->optionid = $optionid;
                $eventdata->cmid = $cm->id;
                booking_send_confirm_message($eventdata);
            }
            return true;
        } else { //check to see if current booking already selected - if not display error
            $optionname = $DB->get_field('booking_options', 'text', array('id' => $optionid));
            return false;
        }
    } else if (!($booking->option[$optionid]->limitanswers)) {
        if (!($currentanswerid = $DB->get_field('booking_answers', 'id', array('userid' => $user->id, 'optionid' => $optionid)))) {
            $newanswer = new stdClass();
            $newanswer->bookingid = $booking->id;
            $newanswer->userid = $user->id;
            $newanswer->optionid = $optionid;
            $newanswer->timemodified = time();
            $newanswer->timecreated = time();
            if (!$DB->insert_record("booking_answers", $newanswer)) {
                error("Could not register your booking because of a database error");
            }
            booking_check_enrol_user($booking->option[$optionid], $booking, $user->id);
        }
        //add_to_log($courseid, "booking", "choose", "view.php?id=$cm->id", $booking->id, $cm->id);
        $event = \mod_booking\event\bookingoption_booked::create(array(
                    'objectid' => $optionid,
                    'context' => context_module::instance($cm->id),
                    'relateduserid' => $user->id
        ));
        $event->trigger();
        if ($booking->sendmail) {
            $eventdata = new stdClass();
            $eventdata->user = $user;
            $eventdata->booking = $booking;
            $eventdata->optionid = $optionid;
            $eventdata->cmid = $cm->id;
            booking_send_confirm_message($eventdata);
        }
        return true;
    }
}

/**
 * Manualy enrol the user in the relevant course, if that setting is on and a
 * course has been specified.
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
    if (!$instances = $DB->get_records('enrol', array('enrol' => 'manual', 'courseid' => $option->courseid, 'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }

    if ($booking->addtogroup == 1) {
        if (!is_null($option->groupid) && ($option->groupid > 0)) {
            groups_add_member($option->groupid, $userid);
        }
    }

    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.	
}

/**
 * Automatically enrol the user in the relevant course, if that setting is on and a
 * course has been specified.
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
    if (!$instances = $DB->get_records('enrol', array('enrol' => 'manual', 'courseid' => $option->courseid, 'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }

    if ($booking->addtogroup == 1) {
        if (!is_null($option->groupid) && ($option->groupid > 0)) {
            groups_add_member($option->groupid, $userid);
        }
    }

    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.	
}

/**
 * Automatically unenrol the user from the relevant course, if that setting is on and a
 * course has been specified.
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
    if (!$instances = $DB->get_records('enrol', array('enrol' => 'manual', 'courseid' => $option->courseid, 'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
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

// this function is not yet implemented and needs to be changed a lot before using it
function booking_show_statistic() {
    global $DB;
    echo "<table cellpadding=\"5\" cellspacing=\"0\" class=\"results anonymous\">";
    echo "<tr>";

    foreach ($booking->option as $optionid => $option) {
        echo "<th class=\"col$count header\" scope=\"col\">";
        echo format_string($option->text);
        echo "</th>";

        $column[$optionid] = 0;
        if (isset($allresponses[$optionid])) {
            $column[$optionid] = count($allresponses[$optionid]);
            if ($column[$optionid] > $maxcolumn) {
                $maxcolumn = $column[$optionid];
            }
        } else {
            $column[$optionid] = 0;
        }
    }
    echo "</tr><tr>";

    $height = 0;


    $count = 1;
    foreach ($booking->option as $optionid => $option) {
        if ($maxcolumn) {
            $height = $COLUMN_HEIGHT * ((float) $column[$optionid] / (float) $maxcolumn);
        }
        echo "<td style=\"vertical-align:bottom\" align=\"center\" class=\"col$count data\">";
        echo "<img src=\"column.png\" height=\"$height\" width=\"49\" alt=\"\" />";
        echo "</td>";
        $count++;
    }
    echo "</tr><tr>";



    $count = 1;
    foreach ($booking->option as $optionid => $option) {
        echo "<td align=\"center\" class=\"col$count count\">";
        if ($booking->limitanswers) {
            echo get_string("taken", "booking") . ":";
            echo $column[$optionid] . '<br />';
            echo get_string("limit", "booking") . ":";
            $option = $GB->get_record("booking_options", array("id", $optionid));
            echo $option->maxanswers;
        } else {
            echo $column[$optionid];
            echo '<br />(' . format_float(((float) $column[$optionid] / (float) $totalresponsecount) * 100.0, 1) . '%)';
        }
        echo "</td>";
        $count++;
    }
    echo "</tr></table>";
}

/**
 * Outputs a confirm button on a separate page to confirm a booking.
 */
function booking_confirm_booking($optionid, $booking, $user, $cm, $url) {
    global $OUTPUT;
    echo $OUTPUT->header();
    $optionidarray['answer'] = $optionid;
    $optionidarray['confirm'] = 1;
    $optionidarray['sesskey'] = $user->sesskey;
    $optionidarray['id'] = $cm->id;
    $requestedcourse = "<br />" . $booking->option[$optionid]->text;
    if ($booking->option[$optionid]->coursestarttime != 0) {
        $requestedcourse .= "<br />" . $booking->option[$optionid]->coursestarttimetext . " - " . $booking->option[$optionid]->courseendtimetext;
    }
    $message = "<h2>" . get_string('confirmbookingoffollowing', 'booking') . "</h2>" . $requestedcourse;
    $message .= "<p><b>" . get_string('agreetobookingpolicy', 'booking') . ":</b></p>";
    $message .= "<p>" . $booking->bookingpolicy . "<p>";
    echo $OUTPUT->confirm($message, new moodle_url('/mod/booking/view.php', $optionidarray), $url);
    echo $OUTPUT->footer();
}

/**
 * deletes a single booking of a user if user cancels the booking, sends mail to bookingmanager and newbookeduser
 * @param $answer
 * @param $booking
 * @param $optionid
 * @param $newbookeduserid
 * @param $cmid
 * @return true if booking was deleted successfully, otherwise false
 */
function booking_delete_singlebooking($answer, $booking, $optionid, $newbookeduserid, $cmid) {
    global $USER, $DB;
    if (!$DB->delete_records('booking_answers', array('id' => $answer->id))) {
        return false;
    }
    if ($answer->userid == $USER->id) {
        $user = $USER;
    } else {
        $user = $DB->get_record('user', array('id' => $answer->userid));
    }
    /** log deletion of user * */
    $event = \mod_booking\event\booking_cancelled::create(array(
                'objectid' => $optionid,
                'context' => context_module::instance($cmid),
                'relateduserid' => $user->id,
                'other' => array('userid' => $user->id)
    ));
    $event->trigger();
    /** backward compatibility hack, if called from subscribeusers.php other booking object is used * */
    if (!isset($booking->option[$optionid])) {
        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $booking = booking_get_booking($cm);
    }
    booking_check_unenrol_user($booking->option[$optionid], $booking, $user->id);

    $params = booking_generate_email_params($booking, $booking->option[$optionid], $user, $cmid);
    $messagetext = get_string('deletedbookingmessage', 'booking', $params);
    if ($answer->userid == $USER->id) {
        // I canceled the booking
        $deletedbookingusermessage = booking_get_email_body($booking, 'userleave', 'userleavebookedmessage', $params);
        $subject = get_string('userleavebookedsubject', 'booking', $params);
    } else {
        // Booking manager canceled the booking
        $deletedbookingusermessage = booking_get_email_body($booking, 'deletedtext', 'deletedbookingmessage', $params);
        $subject = get_string('deletedbookingsubject', 'booking', $params);
    }
    $bookingmanager = $DB->get_record('user', array('username' => $booking->bookingmanager));
    $eventdata = new stdClass();

    if ($booking->sendmail) {
        // Generate ical attachment to go with the message.
        $attachname = '';
        $ical = new booking_ical($booking, $booking->option[$optionid], $user, $bookingmanager);
        if ($attachment = $ical->get_attachment(true)) {
            $attachname = $ical->get_name();
        }

        $messagehtml = text_to_html($deletedbookingusermessage, false, false, true);

        if (isset($booking->sendmailtobooker) && $booking->sendmailtobooker) {
            $eventdata->userto = $USER;
        } else {
            $eventdata->userto = $user;
        }

        $eventdata->userfrom = $bookingmanager;
        $eventdata->subject = $subject;
        $eventdata->messagetext = $deletedbookingusermessage;
        $eventdata->messagehtml = $messagehtml;
        $eventdata->attachment = $attachment;
        $eventdata->attachname = $attachname;
        events_trigger('booking_deleted', $eventdata);

        if ($booking->copymail) {
            $eventdata->userto = $bookingmanager;
            events_trigger('booking_deleted', $eventdata);
        }
    }

    if ($newbookeduserid) {

        $eventdata = new stdClass();

        booking_check_enrol_user($booking->option[$optionid], $booking, $newbookeduserid);
        if ($booking->sendmail == 1 || $booking->copymail) {
            $newbookeduser = $DB->get_record('user', array('id' => $newbookeduserid));
            $params = booking_generate_email_params($booking, $booking->option[$optionid], $newbookeduser, $cmid);
            $messagetextnewuser = booking_get_email_body($booking, 'statuschangetext', 'statuschangebookedmessage', $params);
            $messagehtml = text_to_html($messagetextnewuser, false, false, true);

            // Generate ical attachment to go with the message.
            $attachname = '';
            $ical = new booking_ical($booking, $booking->option[$optionid], $newbookeduser, $bookingmanager);
            if ($attachment = $ical->get_attachment()) {
                $attachname = $ical->get_name();
            }
            $eventdata->userto = $newbookeduser;
            $eventdata->userfrom = $bookingmanager;
            $eventdata->subject = get_string('statuschangebookedsubject', 'booking', $params);
            $eventdata->messagetext = $messagetextnewuser;
            $eventdata->messagehtml = $messagehtml;
            $eventdata->attachment = $attachment;
            $eventdata->attachname = $attachname;
            if ($booking->sendmail == 1) {
                events_trigger('booking_deleted', $eventdata);
            }
            if ($booking->copymail) {
                $eventdata->userto = $bookingmanager;
                events_trigger('booking_deleted', $eventdata);
            }
        }
    }
    return true;
}

// Add activity completion to user.
function booking_activitycompletion($selectedusers, $booking, $cmid, $optionid) {
    global $DB;

    $course = $DB->get_record('course', array('id' => $booking->course));
    $completion = new completion_info($course);

    $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);

    foreach ($selectedusers as $uid) {
        foreach ($uid as $ui) {
            $userData = $DB->get_record('booking_answers', array('optionid' => $optionid, 'userid' => $ui));

            if ($userData->completed == '1') {
                $userData->completed = '0';

                $DB->update_record('booking_answers', $userData);

                if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                    $completion->update_state($cm, COMPLETION_INCOMPLETE, $ui);
                }
            } else {
                $userData->completed = '1';

                $DB->update_record('booking_answers', $userData);

                if ($completion->is_enabled($cm) && $booking->enablecompletion) {
                    $completion->update_state($cm, COMPLETION_COMPLETE, $ui);
                }
            }
        }
    }
}

// Send mail to all teachers - pollurlteachers
function booking_sendpollurlteachers($booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnVal = true;

    $teachers = $DB->get_records("booking_teachers", array("optionid" => $optionid, 'bookingid' => $booking->id));

    foreach ($teachers as $tuser) {
        $userdata = $DB->get_record('user', array('id' => $tuser->userid));

        $params = booking_generate_email_params($booking, $booking->option[$optionid], $userdata, $cmid);

        $pollurlmessage = booking_get_email_body($booking, 'pollurlteacherstext', 'pollurlteacherstextmessage', $params);

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

        $returnVal = message_send($eventdata);
    }
    return $returnVal;
}

// Send mail to all users - pollurl
function booking_sendpollurl($attemptidsarray, $booking, $cmid, $optionid) {
    global $DB, $USER;

    $returnVal = true;

    $sender = $DB->get_record('user', array('username' => $booking->bookingmanager));

    foreach ($attemptidsarray as $tuser) {
        foreach ($tuser as $suser) {
            $tuser = $DB->get_record('user', array('id' => $suser));

            $params = booking_generate_email_params($booking, $booking->option[$optionid], $tuser, $cmid);

            $pollurlmessage = booking_get_email_body($booking, 'pollurltext', 'pollurltextmessage', $params);

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

            $returnVal = message_send($eventdata);
        }
    }
    return $returnVal;
}

// Send custom message
function booking_sendcustommessage($optionid, $subject, $message, $uids) {
    global $DB, $USER;

    $returnVal = true;

    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $booking = $DB->get_record('booking', array('id' => $option->bookingid));
    //$allusers = $DB->get_records('booking_answers', array('bookingid' => $option->bookingid, 'optionid' => $optionid));

    $cm = get_coursemodule_from_instance('booking', $booking->id);
    //foreach ($allusers as $record) {
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

        $returnVal = message_send($eventdata);
    }

    return $returnVal;
}

function booking_send_notification($optionid, $subject) {
    global $DB, $USER, $CFG;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    $returnVal = true;

    $option = $DB->get_record('booking_options', array('id' => $optionid));
    $booking = $DB->get_record('booking', array('id' => $option->bookingid));

    $cm = get_coursemodule_from_instance('booking', $booking->id);

    $bookinglist = booking_get_spreadsheet_data($booking, $cm);
    if (isset($bookinglist[$optionid])) {
        $sortedusers = booking_user_status($option, $bookinglist[$optionid]);
        $allusers = $sortedusers['booked'];

        $tags = new booking_tags($cm);
        $option = $tags->optionReplace($option);
        $booking = $tags->bookingReplace($booking);

        foreach ($allusers as $record) {
            $ruser = $DB->get_record('user', array('id' => $record->id));

            $params = booking_generate_email_params($booking, $option, $ruser, $cm->id);
            $pollurlmessage = booking_get_email_body($booking, 'notificationtext', 'notificationtextmessage', $params);

            $eventdata = new stdClass();
            $eventdata->modulename = 'booking';
            $eventdata->userfrom = $USER;
            $eventdata->userto = $ruser;
            $eventdata->subject = $subject;
            $eventdata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $pollurlmessage));
            $eventdata->fullmessageformat = FORMAT_HTML;
            $eventdata->fullmessagehtml = $pollurlmessage;
            $eventdata->smallmessage = '';
            $eventdata->component = 'mod_booking';
            $eventdata->name = 'bookingconfirmation';

            $returnVal = message_send($eventdata);
        }

        return $returnVal;
    } else {
        return FALSE;
    }
}

function booking_delete_responses($attemptidsarray, $booking, $cmid) {
    global $DB;
    if (!is_array($attemptidsarray) || empty($attemptidsarray)) {
        return false;
    }
    foreach ($attemptidsarray as $optionid => $userids) {
        if (!is_array($userids) || empty($userids)) {
            return false;
        }
        foreach ($userids as $num => $userid) {
            if (empty($userid)) {
                unset($userids[$num]);
            }
        }
        foreach ($userids as $userid) {
            $answer = $DB->get_record('booking_answers', array('bookingid' => $booking->id,
                'userid' => $userid,
                'optionid' => $optionid));
            $newbookeduser = booking_check_statuschange($optionid, $booking, $userid, $cmid);
            booking_delete_singlebooking($answer, $booking, $optionid, $newbookeduser, $cmid);
        }
    }
    return true;
}

function booking_delete_instance($id) {
    global $DB;
    // Given an ID of an instance of this module,
    // this function will permanently delete the instance
    // and any data that depends on it.

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
 * Returns the users with data in one booking
 * (users with records in booking_answers, students)
 *
 * @bookingid booking id of booking instance
 * @return array of students
 */
function booking_get_participants($bookingid) {
    global $CFG, $DB;
    //Get students
    $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
		FROM {user} u,
		{booking_answers} a
		WHERE a.bookingid = '$bookingid' and
		u.id = a.userid");
    //Return students array (it contains an array of unique users)
    return ($students);
}

function booking_get_option_text($booking, $id) {
    global $DB;
    // Returns text string which is the answer that matches the id
    if ($result = $DB->get_record("booking_options", array("id" => $id))) {
        return $result->text;
    } else {
        return get_string("notanswered", "booking");
    }
}

function booking_get_groupmodedata() {
    
}

/**
 * Gets the principal information of booking status and booking options
 * to be used by other functions
 * @param $bookingid id of the module
 * @param $sort which field use to sort options
 * @param $urlParams parameters for searching
 * @param $view if we need it for editing or viewing
 * @return object with $booking->option as an array for the booking option valus for each booking option
 */
function booking_get_booking($cm, $sort = '', $urlParams = array('searchText' => '', 'searchLocation' => '', 'searchInstitution' => ''), $view = TRUE) {
    global $CFG, $DB;
    require_once("$CFG->dirroot/mod/booking/locallib.php");

    if ($sort == '') {
        $sort = 'id';
    }

    $bookingid = $cm->instance;
    // Gets a full booking record
    $context = context_module::instance($cm->id);

    /// Initialise the returned array, which is a matrix:  $allresponses[responseid][userid] = responseobject
    $allresponses = array();
    /// bookinglist $bookinglist[optionid][sortnumber] = userobject;
    $bookinglist = array();

    /// First get all the users who have access here    
    $mainuserfields = user_picture::fields();
    $allresponses = get_users_by_capability($context, 'mod/booking:choose', $mainuserfields . ', u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);

    $bookingObject = new booking_options($cm->id, TRUE, $urlParams);

    if ($view) {
        $bookingObject->apply_tags();
    }

    $booking = $bookingObject->booking;
    $options = $bookingObject->options;
    if ($options) {
        $answers = $DB->get_records('booking_answers', array('bookingid' => $bookingid), 'id');

        foreach ($options as $option) {

            $booking->option[$option->id] = $option;

            if (!$option->coursestarttime == 0) {
                $booking->option[$option->id]->coursestarttimetext = userdate($option->coursestarttime, get_string('strftimedatetime'));
            } else {
                $booking->option[$option->id]->coursestarttimetext = get_string("starttimenotset", 'booking');
            }
            if (!$option->courseendtime == 0) {
                $booking->option[$option->id]->courseendtimetext = userdate($option->courseendtime, get_string('strftimedatetime'), '', false);
            } else {
                $booking->option[$option->id]->courseendtimetext = get_string("endtimenotset", 'booking');
            }
            // we have to change $taken is different from booking_show_results
            $answerstocount = array();
            if ($answers) {
                foreach ($answers as $answer) {
                    if ($answer->optionid == $option->id && isset($allresponses[$answer->userid])) {
                        $answerstocount[] = $answer;
                    }
                }
            }
            $taken = count($answerstocount);
            $totalavailable = $option->maxanswers + $option->maxoverbooking;
            if (!$option->limitanswers) {
                $booking->option[$option->id]->status = "available";
                $booking->option[$option->id]->taken = $taken;
                $booking->option[$option->id]->availspaces = "unlimited";
            } else {
                if ($taken < $option->maxanswers) {
                    $booking->option[$option->id]->status = "available";
                    $booking->option[$option->id]->availspaces = $option->maxanswers - $taken;
                    $booking->option[$option->id]->taken = $taken;
                    $booking->option[$option->id]->availwaitspaces = $option->maxoverbooking;
                } elseif ($taken >= $option->maxanswers && $taken < $totalavailable) {
                    $booking->option[$option->id]->status = "waitspaceavailable";
                    $booking->option[$option->id]->availspaces = 0;
                    $booking->option[$option->id]->taken = $option->maxanswers;
                    $booking->option[$option->id]->availwaitspaces = $option->maxoverbooking - ($taken - $option->maxanswers);
                } elseif ($taken >= $totalavailable) {
                    $booking->option[$option->id]->status = "full";
                    $booking->option[$option->id]->availspaces = 0;
                    $booking->option[$option->id]->taken = $option->maxanswers;
                    $booking->option[$option->id]->availwaitspaces = 0;
                }
            }
            if (time() > $booking->option[$option->id]->bookingclosingtime and $booking->option[$option->id]->bookingclosingtime != 0) {
                $booking->option[$option->id]->status = "closed";
            }
            if ($option->bookingclosingtime) {
                $booking->option[$option->id]->bookingclosingtime = userdate($option->bookingclosingtime, get_string('strftimedate'), '', false);
            } else {
                $booking->option[$option->id]->bookingclosingtime = false;
            }
        }
    }

    return $booking;
}

function booking_get_view_actions() {
    return array('view', 'view all', 'report');
}

function booking_get_post_actions() {
    return array('choose', 'choose again');
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the booking.
 * @param $mform form passed by reference
 */
function booking_reset_course_form_definition($mform) {
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
 * Actual implementation of the rest coures functionality, delete all the
 * booking responses for course $data->courseid.
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
        $status[] = array('component' => $componentstr, 'item' => get_string('removeresponses', 'booking'), 'error' => false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('booking', array('timeopen', 'timeclose'), $data->timeshift, $data->courseid);
        $status[] = array('component' => $componentstr, 'item' => get_string('datechanged'), 'error' => false);
    }
    return $status;
}

/**
 * Gives a list of booked users sorted in an array by booking option
 * @param $cm module id
 * @param $booking booking object
 * @return array sorted list by booking date of all users, booking option as key
 */
function booking_get_spreadsheet_data($booking, $cm) {
    global $CFG, $USER, $DB;
    $bookinglistsorted = array();
//	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
    $context = context_module::instance($cm->id);

    /// Initialise the returned array, which is a matrix:  $allresponses[responseid][userid] = responseobject
    $allresponses = array();
    /// bookinglist $bookinglist[optionid][sortnumber] = userobject;
    $bookinglist = array();

    /// First get all the users who have access here
    $mainuserfields = user_picture::fields();
    $allresponses = get_users_by_capability($context, 'mod/booking:choose', $mainuserfields . ', u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);
    //$allresponses = get_users_by_capability($context, 'mod/booking:choose', 'u.id, u.picture, u.firstname, u.lastname, u.idnumber, u.email', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);
    /// Get all the recorded responses for this booking
    $rawresponses = $DB->get_records('booking_answers', array('bookingid' => $booking->id), "optionid, timemodified ASC");
    $optionids = $DB->get_records_select('booking_options', "bookingid = $booking->id", array(), 'id', 'id');
    /// Use the responses to move users into the correct column
    $sortnumber = 1;
    if ($rawresponses) {
        foreach ($rawresponses as $response) {
            if (isset($allresponses[$response->userid])) {   // This person is enrolled and in correct group
                $bookinglist[$response->optionid][$sortnumber++] = $allresponses[$response->userid];
            }
        }
    }
    if (empty($bookinglist)) {
        unset($bookinglist);
    } else {
        foreach ($optionids as $optionid => $optionobject) {
            if (!empty($bookinglist[$optionid])) {
                $userperbookingoption = count($bookinglist[$optionid]);
                $i = 1;
                foreach ($bookinglist[$optionid] as $key => $value) {
                    unset($bookinglist[$optionid][$key]);
                    $bookinglistsorted[$optionid][$i++] = $value;
                }
            } else {
                unset($bookinglist[$optionid]);
            }
        }
    }
    return $bookinglistsorted;
}

/**
 * Divides all users in booked and waiting list users
 * @param $bookingoption bookingoption object
 * @param $allresponses all responses for that bookingoption
 * @return array of arrays with keys waitinglist and booked
 */
function booking_user_status($bookingoption, $allresponses) {
    $userarray['waitinglist'] = false;
    $userarray['booked'] = false;
    $i = 1;
    if (is_array($allresponses)) {
        foreach ($allresponses as $user) {
            if ($i <= $bookingoption->maxanswers || !$bookingoption->limitanswers) { //booked user
                $userarray['booked'][] = $user;
            } else if ($i <= $bookingoption->maxoverbooking + $bookingoption->maxanswers) { //waitlistusers;
                $userarray['waitinglist'][] = $user;
            }
            $i++;
        }
    }
    return $userarray;
}

/**
 * Event that sends confirmation notification after user successfully booked
 * TODO this should be rewritten for moodle 2.6 onwards
 *
 * @param object $eventdata data of user and users booking details
 * @return bool
 */
function booking_send_confirm_message($eventdata) {
    global $DB, $CFG, $USER;
    $cmid = $eventdata->cmid;
    $optionid = $eventdata->optionid;
    $user = $eventdata->user;

    // Used to store the ical attachment (if required)
    $attachname = '';
    $attachment = '';

    $bookingmanager = $DB->get_record('user', array('username' => $eventdata->booking->bookingmanager));
    $data = booking_generate_email_params($eventdata->booking, $eventdata->booking->option[$optionid], $user, $cmid);

    if ($data->status == get_string('booked', 'booking')) {
        $subject = get_string('confirmationsubject', 'booking', $data);
        $subjectmanager = get_string('confirmationsubjectbookingmanager', 'booking', $data);
        $message = booking_get_email_body($eventdata->booking, 'bookedtext', 'confirmationmessage', $data);

        // Generate ical attachment to go with the message.
        $ical = new booking_ical($eventdata->booking, $eventdata->booking->option[$optionid], $user, $bookingmanager);
        if ($attachment = $ical->get_attachment()) {
            $attachname = $ical->get_name();
        }
    } elseif ($data->status == get_string('onwaitinglist', 'booking')) {
        $subject = get_string('confirmationsubjectwaitinglist', 'booking', $data);
        $subjectmanager = get_string('confirmationsubjectwaitinglistmanager', 'booking', $data);
        $message = booking_get_email_body($eventdata->booking, 'waitingtext', 'confirmationmessagewaitinglist', $data);
    } else {
        $subject = "test";
        $subjectmanager = "tester";
        $message = "message";
    }
    $messagehtml = text_to_html($message, false, false, true);
    $errormessage = get_string('error:failedtosendconfirmation', 'booking', $data);
    $errormessagehtml = text_to_html($errormessage, false, false, true);
    $user->mailformat = 1;  // Always send HTML version as well
    //implementing message send, but prior to moodle 2.6, that function does not
    //accept attachments, so have to call email_to_user in that case
    $messagedata = new stdClass();
    $messagedata->userfrom = $bookingmanager;
    if ($eventdata->booking->sendmailtobooker) {
        $messagedata->userto = $USER;
    } else {
        $messagedata->userto = $user;
    }
    $messagedata->subject = $subject;
    $messagedata->messagetext = $message;
    $messagedata->messagehtml = $messagehtml;
    $messagedata->attachment = $attachment;
    $messagedata->attachname = $attachname;

    events_trigger('booking_confirmed', $messagedata);

    if ($eventdata->booking->copymail) {
        $messagedata->userto = $bookingmanager;
        $messagedata->subject = $subjectmanager;
        events_trigger('booking_confirmed', $messagedata);
    }
    return true;
}

/**
 * Sends mail via cron when user is deleted from booking
 *
 * @param object $eventdata data for email_to_user params
 */
function booking_booking_deleted($eventdata) {
    email_to_user($eventdata->userto, $eventdata->userfrom, $eventdata->subject, $eventdata->messagetext, $eventdata->messagehtml, $eventdata->attachment, $eventdata->attachname);
}

/**
 * Sends mail via cron when booking of user is confirmed
 *
 * @param object $eventdata data for email_to_user params
 */
function booking_booking_confirmed($eventdata) {
    email_to_user($eventdata->userto, $eventdata->userfrom, $eventdata->subject, $eventdata->messagetext, $eventdata->messagehtml, $eventdata->attachment, $eventdata->attachname);
}

function booking_pretty_duration($seconds) {
    $measures = array(
        'days' => 24 * 60 * 60,
        'hours' => 60 * 60,
        'minutes' => 60
    );
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

    return $params;
}

/**
 * Generate the email body based on the activity settings and the booking parameters
 * @param object $booking the booking activity object
 * @param string $fieldname the name of the field that contains the custom text
 * @param string $defaultname the name of the default string
 * @param object $params the booking details
 * @return string
 */
function booking_get_email_body($booking, $fieldname, $defaultname, $params) {
    if (empty($booking->$fieldname)) {
        return get_string($defaultname, 'mod_booking', $params);
    }
    $text = $booking->$fieldname;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

/**
 * Checks if user on waitinglist gets normal place if a user is deleted
 * @param $optionid id of booking option
 * @param $booking booking id
 * @param $cancelleduserid user id that was deleted form booking option
 * @param $cmid course module id
 * @return mixed false if no user gets from waitinglist to booked list or userid of user now on booked list
 */
function booking_check_statuschange($optionid, $booking, $cancelleduserid, $cmid) {
    global $DB;
    if (booking_get_user_status($cancelleduserid, $optionid, $booking->id, $cmid) !== get_string('booked', 'booking')) {
        return false;
    }
    //backward compatibility hack TODO: remove
    if (!isset($booking->option[$optionid])) {
        $option = $DB->get_record('booking_options', array('bookingid' => $booking->id,
            'id' => $optionid));
    } else {
        $option = $booking->option[$optionid];
    }
    if ($option->maxanswers == 0) {
        return false; // No limit on bookings => no waiting list to manage
    }
    $allresponses = $DB->get_records('booking_answers', array('bookingid' => $booking->id,
        'optionid' => $optionid), 'timemodified', 'userid');
    //$context  = get_context_instance(CONTEXT_MODULE,$cmid);
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
 * @param $userid to be checked
 * @return false if no redirect necessery true if necessary
 */
function booking_check_user_profile_fields($userid) {
    global $DB;
    $redirect = false;
    if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
        foreach ($categories as $category) {
            if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id", array(), 'sortorder ASC')) {
                // check first if *any* fields will be displayed and if there are required fields
                $requiredfields = array();
                $redirect = false;
                foreach ($fields as $field) {
                    if ($field->visible != 0 && $field->required == 1) {
                        if (!$userdata = $DB->get_field('user_info_data', 'data', array("userid" => $userid, "fieldid" => $field->id))) {
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
    $userids = $DB->get_fieldset_select('booking_answers', 'userid', 'bookingid = :bookingid AND optionid = :optionid', $params);
    foreach ($userids as $userid) {
        booking_check_unenrol_user($option, $booking, $userid); // Unenrol any users enroled via this option.
    }
    if (!$DB->delete_records("booking_answers", array("bookingid" => $booking->id, "optionid" => $optionid))) {
        $result = false;
    }

    // Delete calendar entry, if any
    $event->id = $DB->get_field('booking_options', 'calendarid', array('id' => $optionid));
    if ($event->id > 0) {
        // Delete event if exist
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

    // if user is "admin" fields are displayed regardless
    $update = has_capability('moodle/user:update', context_system::instance());

    if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
        foreach ($categories as $category) {
            if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id", array(), 'sortorder ASC')) {

                // check first if *any* fields will be displayed
                $display = false;
                foreach ($fields as $field) {
                    if ($field->visible != PROFILE_VISIBLE_NONE) {
                        $display = true;
                    }
                }

                // display the header and the fields
                if ($display or $update) {
                    $mform->addElement('header', 'category_' . $category->id, format_string($category->name));
                    foreach ($fields as $field) {
                        require_once($CFG->dirroot . '/user/profile/field/' . $field->datatype . '/field.class.php');
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
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
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
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $optionid
 */
function booking_optionid_unsubscribe($userid, $optionid) {
    global $DB;
    return ($DB->delete_records('booking_teachers', array('userid' => $userid, 'optionid' => $optionid)));
}

/**
 * Abstract class used by booking subscriber selection controls
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the forum this selector is being used for
     * @var int
     */
    protected $optionid = null;

    /**
     * The context of the forum this selector is being used for
     * @var object
     */
    protected $context = null;

    /**
     * The id of the current group
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
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
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $fields = $this->required_fields_sql('u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $params = array_merge($params, $eparams, $sortparams);

        $subscribers = $DB->get_records_sql("SELECT $fields
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
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_potential_subscriber_selector extends booking_subscriber_selector_base {

    /**
     * If set to true EVERYONE in this course is force subscribed to this booking
     * @var bool
     */
    protected $forcesubscribed = false;

    /**
     * Can be used to store existing subscribers so that they can be removed from
     * the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
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
     * Finds all potential users
     *
     * Potential subscribers are all enroled users who are not already subscribed.
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
                list($usertest, $userparams) = $DB->get_in_or_equal(
                        array_keys($existingids), SQL_PARAMS_NAMED, 'existing', false);
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
        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($params, $sortparams));

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
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this forum as force subscribed or not
     */
    public function set_force_subscribed($setting = true) {
        $this->forcesubscribed = true;
    }

}

/**
 * Returns list of user objects that are subscribed to this forum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param forum $forum the forum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the forum context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function booking_subscribed_teachers($course, $optionid, $id, $groupid = 0, $context = null, $fields = null) {
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

    // only active enrolled users or everybody on the frontpage
    list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
    $params['optionid'] = $optionid;
    $results = $DB->get_records_sql("SELECT $fields
		FROM {user} u
		JOIN ($esql) je ON je.id = u.id
		JOIN {booking_teachers} s ON s.userid = u.id
		WHERE s.optionid = :optionid
		ORDER BY u.email ASC", $params);

    // Guest user should never be subscribed to a forum.
    unset($results[$CFG->siteguest]);

    return $results;
}

?>
