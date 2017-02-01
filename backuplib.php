<?php
// $Id: backuplib.php,v 1.11 2006/02/08 23:46:21 danmarsden Exp $
// This php script contains all the stuff to backup/restore
// booking mods
// This is the "graphical" structure of the booking mod:
//
// booking
// (CL,pk->id)----------|
// | |
// | |
// | |
// booking_options |
// (UL,pk->id, fk->bookingid) |
// | |
// | |
// | |
// booking_answers |
// (UL,pk->id, fk->bookingid, fk->optionid)
//
// Meaning: pk->primary key field of the table
// fk->foreign key to link with parent
// nt->nested field (recursive data)
// CL->course level info
// UL->user level info
// files->table may have files)
//
// -----------------------------------------------------------
function booking_backup_mods($bf, $preferences) {
    global $CFG;

    $status = true;

    // Iterate over booking table
    $bookings = get_records("booking", "course", $preferences->backup_course, "id");
    if ($bookings) {
        foreach ($bookings as $booking) {
            if (backup_mod_selected($preferences, 'booking', $booking->id)) {
                $status = booking_backup_one_mod($bf, $preferences, $booking);
            }
        }
    }
    return $status;
}

function booking_backup_one_mod($bf, $preferences, $booking) {
    global $CFG;

    if (is_numeric($booking)) {
        $booking = get_record('booking', 'id', $booking);
    }

    $status = true;

    // Start mod
    fwrite($bf, start_tag("MOD", 3, true));
    // Print booking data
    fwrite($bf, full_tag("ID", 4, false, $booking->id));
    fwrite($bf, full_tag("MODTYPE", 4, false, "booking"));
    fwrite($bf, full_tag("NAME", 4, false, $booking->name));
    fwrite($bf, full_tag("TEXT", 4, false, $booking->text));
    fwrite($bf, full_tag("FORMAT", 4, false, $booking->format));
    fwrite($bf, full_tag("BOOKINGMANAGER", 4, false, $booking->bookingmanager));
    fwrite($bf, full_tag("SENDMAIL", 4, false, $booking->sendmail));
    fwrite($bf, full_tag("COPYMAIL", 4, false, $booking->copymail));
    fwrite($bf, full_tag("ALLOWUPDATE", 4, false, $booking->allowupdate));
    fwrite($bf, full_tag("bookingpolicy", 4, false, $booking->bookingpolicy));
    fwrite($bf, full_tag("TIMEOPEN", 4, false, $booking->timeopen));
    fwrite($bf, full_tag("TIMECLOSE", 4, false, $booking->timeclose));
    fwrite($bf, full_tag("LIMITANSWERS", 4, false, $booking->limitanswers));
    fwrite($bf, full_tag("MAXANSWERS", 4, false, $booking->maxanswers));
    fwrite($bf, full_tag("MAXOVERBOOKING", 4, false, $booking->maxoverbooking));
    fwrite($bf, full_tag("TIMEMODIFIED", 4, false, $booking->timemodified));
    fwrite($bf, full_tag("DURATION", 4, false, $booking->duration));
    fwrite($bf, full_tag("POINTS", 4, false, $booking->points));
    fwrite($bf, full_tag("ORGANIZATORNAME", 4, false, $booking->organizatorname));
    fwrite($bf, full_tag("POLLURL", 4, false, $booking->pollurl));
    fwrite($bf, full_tag("ADDTOGROUP", 4, false, $booking->addtogroup));
    fwrite($bf, full_tag("CATEGORYID", 4, false, $booking->categoryid));
    fwrite($bf, full_tag("POLLURLTEXT", 4, false, $booking->pollurltext));
    fwrite($bf, full_tag("ADDITIONALFIELDS", 4, false, $booking->additionalfields));
    fwrite($bf, full_tag("EVENTTYPE", 4, false, $booking->eventtype));
    fwrite($bf, full_tag("NOTIFICATIONTEXT", 4, false, $booking->notificationtext));
    fwrite($bf, full_tag("USERLEAVE", 4, false, $booking->userleave));
    fwrite($bf, full_tag("ENABLECOMPLETION", 4, false, $booking->enablecompletion));
    fwrite($bf, full_tag("POLLURLTEACHERS", 4, false, $booking->pollurlteachers));
    fwrite($bf, full_tag("POLLURLTEACHERSTEXT", 4, false, $booking->pollurlteacherstext));
    fwrite($bf, full_tag("DAYSTONOTIFY", 4, false, $booking->daystonotify));
    fwrite($bf, full_tag("NOTIFYEMAIL", 4, false, $booking->notifyemail));

    // Now backup booking_options
    $status = backup_booking_options($bf, $preferences, $booking->id);

    // if we've selected to backup users info, then execute backup_booking_answers
    if (backup_userdata_selected($preferences, 'booking', $booking->id)) {
        $status = backup_booking_answers($bf, $preferences, $booking->id);
    }
    // End mod
    $status = fwrite($bf, end_tag("MOD", 3, true));

    return $status;
}

// Backup booking_answers contents (executed from booking_backup_mods)
function backup_booking_answers($bf, $preferences, $booking) {
    global $CFG;

    $status = true;

    $bookinganswers = get_records("booking_answers", "bookingid", $booking, "id");
    // If there is answers
    if ($bookinganswers) {
        // Write start tag
        $status = fwrite($bf, start_tag("ANSWERS", 4, true));
        // Iterate over each answer
        foreach ($bookinganswers as $choans) {
            // Start answer
            $status = fwrite($bf, start_tag("ANSWER", 5, true));
            // Print answer contents
            fwrite($bf, full_tag("ID", 6, false, $choans->id));
            fwrite($bf, full_tag("BOOKINGID", 6, false, $choans->bookingid));
            fwrite($bf, full_tag("USERID", 6, false, $choans->userid));
            fwrite($bf, full_tag("OPTIONID", 6, false, $choans->optionid));
            fwrite($bf, full_tag("TIMEMODIFIED", 6, false, $choans->timemodified));
            // End answer
            $status = fwrite($bf, end_tag("ANSWER", 5, true));
        }
        // Write end tag
        $status = fwrite($bf, end_tag("ANSWERS", 4, true));
    }
    return $status;
}

// backup booking_options contents (executed from booking_backup_mods)
function backup_booking_options($bf, $preferences, $booking) {
    global $CFG;

    $status = true;

    $booking_options = get_records("booking_options", "bookingid", $booking, "id");
    // If there is options
    if ($booking_options) {
        // Write start tag
        $status = fwrite($bf, start_tag("OPTIONS", 4, true));
        // Iterate over each answer
        foreach ($booking_options as $choopt) {
            // Start option
            $status = fwrite($bf, start_tag("OPTION", 5, true));
            // Print option contents
            fwrite($bf, full_tag("ID", 6, false, $choopt->id));
            fwrite($bf, full_tag("BOOKINGID", 6, false, $choopt->bookingid));
            fwrite($bf, full_tag("TEXT", 6, false, $choopt->text));
            fwrite($bf, full_tag("MAXANSWERS", 6, false, $choopt->maxanswers));
            fwrite($bf, full_tag("MAXOVERBOOKING", 6, false, $choopt->maxoverbooking));
            fwrite($bf, full_tag("BOOKINGCLOSINGTIME", 6, false, $choopt->bookingclosingtime));
            fwrite($bf, full_tag("COURSEID", 6, false, $choopt->courseid));
            fwrite($bf, full_tag("COURSESTARTTIME", 6, false, $choopt->coursestarttime));
            fwrite($bf, full_tag("COURSEENDTIME", 6, false, $choopt->courseendtime));
            fwrite($bf, full_tag("DESCRIPTION", 6, false, $choopt->description));
            fwrite($bf, full_tag("LIMITANSWERS", 6, false, $choopt->limitanswers));
            fwrite($bf, full_tag("TIMEMODIFIED", 6, false, $choopt->timemodified));
            fwrite($bf, full_tag("ADDTOCALENDAR", 6, false, $choopt->addtocalendar));
            fwrite($bf, full_tag("CALENDARID", 6, false, $choopt->calendarid));
            fwrite($bf, full_tag("POLLURL", 6, false, $choopt->pollurl));
            fwrite($bf, full_tag("GROUPID", 6, false, $choopt->groupid));
            fwrite($bf, full_tag("SENT", 6, false, $choopt->sent));
            fwrite($bf, full_tag("LOCATION", 6, false, $choopt->location));
            fwrite($bf, full_tag("INSTITUTION", 6, false, $choopt->institution));
            fwrite($bf, full_tag("ADDRESS", 6, false, $choopt->address));
            // End answer
            $status = fwrite($bf, end_tag("OPTION", 5, true));
        }
        // Write end tag
        $status = fwrite($bf, end_tag("OPTIONS", 4, true));
    }
    return $status;
}

// Return an array of info (name,value)
function booking_check_backup_mods($course, $userdata = false, $backup_unique_code, $instances = null) {
    if (!empty($instances) && is_array($instances) && count($instances)) {
        $info = array();
        foreach ($instances as $id => $instance) {
            $info += booking_check_backup_mods_instances($instance, $backup_unique_code);
        }
        return $info;
    }
    // First the course data
    $info[0][0] = get_string("modulenameplural", "booking");
    if ($ids = booking_ids($course)) {
        $info[0][1] = count($ids);
    } else {
        $info[0][1] = 0;
    }

    // Now, if requested, the user_data
    if ($userdata) {
        $info[1][0] = get_string("responses", "booking");
        if ($ids = booking_answer_ids_by_course($course)) {
            $info[1][1] = count($ids);
        } else {
            $info[1][1] = 0;
        }
    }
    return $info;
}

// Return an array of info (name,value)
function booking_check_backup_mods_instances($instance, $backupuniquecode) {
    // First the course data
    $info[$instance->id . '0'][0] = '<b>' . $instance->name . '</b>';
    $info[$instance->id . '0'][1] = '';

    // Now, if requested, the user_data
    if (!empty($instance->userdata)) {
        $info[$instance->id . '1'][0] = get_string("responses", "booking");
        if ($ids = booking_answer_ids_by_instance($instance->id)) {
            $info[$instance->id . '1'][1] = count($ids);
        } else {
            $info[$instance->id . '1'][1] = 0;
        }
    }
    return $info;
}

// Return a content encoded to support interactivities linking. Every module
// should have its own. They are called automatically from the backup procedure.
function booking_encode_content_links($content, $preferences) {
    global $CFG;

    $base = preg_quote($CFG->wwwroot, "/");

    // Link to the list of bookings
    $buscar = "/(" . $base . "\/mod\/booking\/index.php\?id\=)([0-9]+)/";
    $result = preg_replace($buscar, '$@BOOKINGINDEX*$2@$', $content);

    // Link to booking view by moduleid
    $buscar = "/(" . $base . "\/mod\/booking\/view.php\?id\=)([0-9]+)/";
    $result = preg_replace($buscar, '$@BOOKINGVIEWBYID*$2@$', $result);

    return $result;
}

// INTERNAL FUNCTIONS. BASED IN THE MOD STRUCTURE
// Returns an array of bookings id
function booking_ids($course) {
    global $CFG;

    return get_records_sql(
            "SELECT a.id, a.course
                                 FROM {$CFG->prefix}booking a
                                 WHERE a.course = '$course'");
}

// Returns an array of booking_answers id
function booking_answer_ids_by_course($course) {
    global $CFG;

    return get_records_sql(
            "SELECT s.id , s.bookingid
                                 FROM {$CFG->prefix}booking_answers s,
                                 {$CFG->prefix}booking a
                                 WHERE a.course = '$course' AND
                                       s.bookingid = a.id");
}

// Returns an array of booking_answers id
function booking_answer_ids_by_instance($instanceid) {
    global $CFG;

    return get_records_sql(
            "SELECT s.id , s.bookingid
                                 FROM {$CFG->prefix}booking_answers s
                                 WHERE s.bookingid = $instanceid");
}
