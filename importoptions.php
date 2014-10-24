<?php

/**
 * Import options or just add new users from CSV
 *
 * @package   Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("lib.php");
require_once('importoptions_form.php');

$id = required_param('id', PARAM_INT);                 // Course Module ID

$url = new moodle_url('/mod/booking/importoptions.php', array('id' => $id));
$urlRedirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    print_error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm, '')) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importoptions_form($url);

//Form processing and displaying is done here
if ($mform->is_cancelled()) {
    //Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die;
} else if ($fromform = $mform->get_data()) {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

    $csvfile = $mform->get_file_content('csvfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvArr = array();
    foreach ($lines as $line) {
        $csvArr[] = str_getcsv($line);
    }
        
    // Check if CSV is ok
      
    if ($csvArr[0][0] == 'name' && $csvArr[0][1] == 'startdate' && $csvArr[0][2] == 'enddate' && $csvArr[0][3] == 'institution' && $csvArr[0][4] == 'institutionaddress' && $csvArr[0][5] == 'teacheremail' && $csvArr[0][6] == 'useremail' && $csvArr[0][7] == 'finished') {
        array_shift($csvArr);
        
        foreach ($csvArr as $line) {
            if(count($line) == 8) {
                
                $user = FALSE;
                $teacher = FALSE;
                $booking_option = FALSE;
                $startDate = 0;
                $endDate = 0;
                
                $booking_option_name = $booking->name;
                
                if (strlen(trim($line[1])) > 0) {
                    $startDate = date_create_from_format($fromform->dateparseformat, $line[1]);
                }

                if (strlen(trim($line[2])) > 0) {
                    $endDate = date_create_from_format($fromform->dateparseformat, $line[2]);
                }
                
                if (strlen(trim($line[5])) > 0) {
                    $teacher = $DB->get_record('user', array('email' => $line[5]));                    
                }
                
                if (strlen(trim($line[6])) > 0) {
                    $user = $DB->get_record('user', array('email' => $line[6]));                    
                }
                
                if (strlen(trim($line[0])) > 0) {
                    $booking_option_name = $line[0];
                }              
                
                $strTimestamp = $startDate->getTimestamp();
                $nameText = mysql_real_escape_string($booking_option_name);
                $addressText = mysql_real_escape_string($line[4]);
                
                $booking_option = $DB->get_record_select('booking_options', "address LIKE '$addressText' AND text LIKE '$nameText' AND bookingid = $booking->id AND coursestarttime = $strTimestamp");
                
                if (empty($booking_option)) {
                    $bookingObject = new stdClass();
                    $bookingObject->bookingid = $booking->id;
                    $bookingObject->text = $booking_option_name;
                    $bookingObject->description = '';
                    $bookingObject->maxanswers = 0;
                    $bookingObject->maxoverbooking = 0;
                    $bookingObject->courseid = $booking->course;
                    $bookingObject->coursestarttime = $startDate->getTimestamp();
                    $bookingObject->courseendtime = $endDate->getTimestamp();
                    $bookingObject->institution = $line[3];
                    $bookingObject->address = $line[4];
                    
                    $bid = $DB->insert_record('booking_options', $bookingObject, TRUE); 
                    
                    $bookingObject->id = $bid;
                    $booking_option = $bookingObject;
                }
                
                if ($teacher) {                    
                    $getUser = $DB->get_record('booking_teachers', array('bookingid' => $booking->id, 'userid' => $teacher->id, 'optionid' => $booking_option->id));
                    
                    if ($getUser === FALSE) {
                        $newTeacher = new stdClass();
                        $newTeacher->bookingid = $booking->id;
                        $newTeacher->userid = $teacher->id;
                        $newTeacher->optionid = $booking_option->id;
                        
                        $DB->insert_record('booking_teachers', $newTeacher, TRUE); 
                    }
                } else {
                    echo $OUTPUT->notification(get_string('noteacherfound', 'booking') . $line[5]);
                }
                
                if ($user) {
                    $getUser = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $user->id, 'optionid' => $booking_option->id));
                    
                    if ($getUser === FALSE) {
                        $newUser = new stdClass();
                        $newUser->bookingid = $booking->id;
                        $newUser->userid = $user->id;
                        $newUser->optionid = $booking_option->id;
                        $newUser->completed = $line[7];
                        
                        $DB->insert_record('booking_answers', $newUser, TRUE); 
                    }
                } else {
                    echo $OUTPUT->notification(get_string('nouserfound', 'booking') . $line[6]);
                }
            }
        }
        
        echo $OUTPUT->box(get_string('importfinished', 'booking'));
        
    } else {
        // Not ok, write error!
        echo $OUTPUT->notification(get_string('wrongfile', 'booking'));
    }
    
    //In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');


    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    //displays the form
    $mform->display();
}

echo $OUTPUT->footer();
