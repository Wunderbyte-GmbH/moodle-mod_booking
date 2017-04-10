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
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/tcpdf/tcpdf.php');


/**
 * Abstract class used by booking subscriber selection controls
 *
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_user_selector_base extends user_selector_base {

    /**
     * The id of the booking this selector is being used for
     *
     * @var int
     */
    protected $bookingid = null;

    /**
     * The id of the current option
     *
     * @var int
     */
    protected $optionid = null;

    /**
     * The potential users array
     *
     * @var array
     */
    public $potentialusers = null;

    public $bookedvisibleusers = null;

    /**
     * Constructor method
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $this->maxusersperpage = 50;
        parent::__construct($name, $options);

        if (isset($options['bookingid'])) {
            $this->bookingid = $options['bookingid'];
        }
        if (isset($options['potentialusers'])) {
            $this->potentialusers = $options['potentialusers'];
        }
        if (isset($options['optionid'])) {
            $this->optionid = $options['optionid'];
        }
    }

    protected function get_options() {
        $options = parent::get_options();
        $options['file'] = 'mod/booking/locallib.php';
        $options['bookingid'] = $this->bookingid;
        $options['potentialusers'] = $this->potentialusers;
        $options['optionid'] = $this->optionid;
        // Add our custom options to the $options array.
        return $options;
    }

    /**
     * Sets the existing subscribers
     *
     * @param array $users
     */
    public function set_potential_users(array $users) {
        $this->potentialusers = $users;
    }
}


/**
 * User selector for booking other users
 */
class booking_potential_user_selector extends booking_user_selector_base {

    public $potentialusers;

    public $options;

    public function __construct($name, $options) {
        $this->potentialusers = $options['potentialusers'];
        $this->options = $options;

        parent::__construct($name, $options);
    }

    public function find_users($search) {
        global $DB, $USER;

        $fields = "SELECT " . $this->required_fields_sql("u");

        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');
        list($esql, $params) = get_enrolled_sql($this->options['accesscontext'], null, null, true);

        $option = new stdClass();
        $option->id = $this->options['optionid'];
        $option->bookingid = $this->options['bookingid'];

        if (booking_check_if_teacher($option) && !has_capability(
                'mod/booking:readallinstitutionusers', $this->options['accesscontext'])) {

            $institution = $DB->get_record('booking_options',
                    array('id' => $this->options['optionid']));

            $searchparams['onlyinstitution'] = $institution->institution;
            $searchcondition .= ' AND u.institution LIKE :onlyinstitution';
        }

        $sql = " FROM {user} u
        WHERE $searchcondition
        AND u.id IN (SELECT nnn.id FROM ($esql) AS nnn WHERE nnn.id > 1)
        AND u.id NOT IN (SELECT ba.userid FROM {booking_answers} ba WHERE ba.optionid = {$this->options['optionid']})";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql,
                    array_merge($searchparams, $params));
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($searchparams, $params, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($search) {
            $groupname = get_string('enrolledusersmatching', 'enrol', $search);
        } else {
            $groupname = get_string('enrolledusers', 'enrol');
        }

        return array($groupname => $availableusers);
    }
}


/**
 * User selector control for removing booked users
 *
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_existing_user_selector extends booking_user_selector_base {

    public $potentialusers;

    public $options;

    public function __construct($name, $options) {
        $this->potentialusers = $options['potentialusers'];
        $this->options = $options;

        parent::__construct($name, $options);
    }

    /**
     * Finds all booked users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB, $USER;

        // only active enrolled or everybody on the frontpage
        $fields = "SELECT " . $this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!empty($this->potentialusers)) {
            $subscriberssql = implode(',', array_keys($this->potentialusers));
        } else {
            return array();
        }

        $option = new stdClass();
        $option->id = $this->options['optionid'];
        $option->bookingid = $this->options['bookingid'];

        if (booking_check_if_teacher($option) && !has_capability(
                'mod/booking:readallinstitutionusers', $this->options['accesscontext'])) {

            $institution = $DB->get_record('booking_options',
                    array('id' => $this->options['optionid']));

            $searchparams['onlyinstitution'] = $institution->institution;
            $searchcondition .= ' AND u.institution LIKE :onlyinstitution';
        }

        $sql = " FROM {user} u
                        WHERE u.id IN ($subscriberssql) AND
                        $searchcondition
                        ";

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $searchparams);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($searchparams, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        return array(get_string("booked", 'booking') => $availableusers);
    }
}


/**
 * Utils
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_utils {

    private function pretty_duration($seconds) {
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
     * @return stdClass data to be sent via mail
     */
    public function generate_params(stdClass $booking, stdClass $option = null) {
        global $DB;

        $params = new stdClass();

        $params->duration = $booking->duration;
        $params->eventtype = $booking->eventtype;

        if (!is_null($option)) {

            $teacher = $DB->get_records('booking_teachers', array('optionid' => $option->id));

            $i = 1;

            foreach ($teacher as $value) {

                $user = $DB->get_record('user', array('id' => $value->userid),
                        'firstname, lastname', IGNORE_MULTIPLE);
                $params->{"teacher" . $i} = $user->firstname . ' ' . $user->lastname;

                $i++;
            }

            if (isset($params->teacher1)) {
                $params->teacher = $params->teacher1;
            } else {
                $params->teacher = '';
            }

            $timeformat = get_string('strftimetime');
            $dateformat = get_string('strftimedate');

            $duration = '';
            if ($option->coursestarttime && $option->courseendtime) {
                $seconds = $option->courseendtime - $option->coursestarttime;
                $duration = $this->pretty_duration($seconds);
            }
            $courselink = '';
            if ($option->courseid) {
                $courselink = new moodle_url('/course/view.php', array('id' => $option->courseid));
                $courselink = html_writer::link($courselink, $courselink->out());
            }

            $params->title = s($option->text);
            $params->starttime = $option->coursestarttime ? userdate($option->coursestarttime,
                    $timeformat) : '';
            $params->endtime = $option->courseendtime ? userdate($option->courseendtime,
                    $timeformat) : '';
            $params->startdate = $option->coursestarttime ? userdate($option->coursestarttime,
                    $dateformat) : '';
            $params->enddate = $option->courseendtime ? userdate($option->courseendtime,
                    $dateformat) : '';
            $params->courselink = $courselink;
            $params->location = $option->location;
            $params->institution = $option->institution;
            $params->address = $option->address;
            $params->pollstartdate = $option->coursestarttime ? userdate(
                    (int) $option->coursestarttime, get_string('pollstrftimedate', 'booking'), '',
                    false) : '';
            if (!empty($option->pollurl)) {
                $params->pollurl = $option->pollurl;
            } else {
                $params->pollurl = $booking->pollurl;
            }
            if (!empty($option->pollurlteachers)) {
                $params->pollurlteachers = $option->pollurlteachers;
            } else {
                $params->pollurlteachers = $booking->pollurlteachers;
            }

            $val = '';
            if (!is_null($option->times)) {
                $additionaltimes = explode(',', $option->times);
                foreach ($additionaltimes as $t) {
                    $slot = explode('-', $t);
                    $tempdate = new stdClass();
                    $tempdate->leftdate = userdate($slot[0], get_string('leftdate', 'booking'));
                    $tempdate->righttdate = userdate($slot[1], get_string('righttdate', 'booking'));
                    $val .= get_string('leftandrightdate', 'booking', $tempdate) . '<br>';
                }
            }

            $params->times = $val;
        }

        return $params;
    }

    /**
     * Generate the email body based on the activity settings and the booking parameters
     *
     * @param object $booking the booking activity object
     * @param string $fieldname the name of the field that contains the custom text
     * @param object $params the booking details
     * @return string
     */
    public function get_body($booking, $fieldname, $params, $urlencode = false) {
        $text = $booking->$fieldname;
        foreach ($params as $name => $value) {
            if ($urlencode) {
                $text = str_replace('{' . $name . '}', urlencode($value), $text);
            } else {
                $text = str_replace('{' . $name . '}', $value, $text);
            }
        }
        return $text;
    }

    /**
     * Create or update new group and return id of group.
     *
     * @param object $booking
     * @param object $option
     * @return int
     */
    public function group($bookingtmp = null, $optiontmp = null) {
        global $DB;

        $booking = clone $bookingtmp;
        $option = clone $optiontmp;

        if ($booking->addtogroup == 1 && $option->courseid > 0) {

            $cm = get_coursemodule_from_instance('booking', $booking->id);
            $url = new moodle_url('/mod/booking/view.php', array('id' => $cm->id));

            $tags = new booking_tags($cm);
            $booking = $tags->booking_replace($booking);
            $option = $tags->option_replace($option);
            $newgroupdata = new stdClass();
            $newgroupdata->courseid = $option->courseid;
            $newgroupdata->name = $booking->name . ' - ' . $option->text;
            $newgroupdata->description = $booking->name . ' - ' . $option->text;
            $newgroupdata->descriptionformat = FORMAT_HTML;
            // If group name already exists, do not create it a second time, it should be unique.
            if ($groupid = groups_get_group_by_name($newgroupdata->courseid, $newgroupdata->name) && !isset($option->id)) {
                throw new moodle_exception('groupexists', 'booking', $url->out());
            }

            if (isset($option->id)) {
                $groupid = $DB->get_field('booking_options', 'groupid', array('id' => $option->id));

                if (!is_null($groupid) && ($groupid > 0)) {
                    $newgroupdata->id = $groupid;
                }

                if (isset($newgroupdata->id)) {
                    groups_update_group($newgroupdata);
                    return $newgroupdata->id;
                } else {
                    return groups_create_group($newgroupdata);
                }
            } else {
                // New option, optionid not yet available.
                return groups_create_group($newgroupdata);
            }
        } else {
            return 0;
        }
    }
}


/**
 * Tags templates
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_tags {

    public $cm;

    public $tags;

    public $replaces;

    public $optionschangetext = array('text', 'description', 'location', 'institution', 'address');

    public $bookingchangetext = array('name', 'intro', 'bookingpolicy', 'bookedtext', 'notifyemail',
        'waitingtext', 'statuschangetext', 'deletedtext', 'duration', 'organizatorname',
        'pollurltext', 'eventtype', 'notificationtext', 'userleave', 'pollurlteacherstext');

    private $option;

    public function __construct($cm) {
        global $DB;

        $this->cm = $cm;
        $this->tags = $DB->get_records('booking_tags', array('courseid' => $this->cm->course));
        $this->replaces = $this->prepare_replaces();
    }

    public function get_all_tags() {
        return $this->tags;
    }

    private function prepare_replaces() {
        $keys = array();
        $values = array();

        foreach ($this->tags as $tag) {
            $keys[] = "[{$tag->tag}]";
            $values[] = $tag->text;
        }

        return array('keys' => $keys, 'values' => $values);
    }

    public function get_replaces() {
        return $this->replaces;
    }

    public function tag_replaces($text) {
        return str_replace($this->replaces['keys'], $this->replaces['values'], $text);
    }

    public function booking_replace($bookingtmp = null) {
        $booking = clone $bookingtmp;
        foreach ($booking as $key => $value) {
            if (in_array($key, $this->bookingchangetext)) {
                $booking->{$key} = $this->tag_replaces($booking->{$key});
            }
        }

        return $booking;
    }

    public function option_replace($option = null) {
        $this->option = clone $option;
        foreach ($this->option as $key => $value) {
            if (in_array($key, $this->optionschangetext)) {
                $this->option->{$key} = $this->tag_replaces($this->option->{$key});
            }
        }

        return $this->option;
    }
}

/**
 * Outputs a confirm button on a separate page to confirm a booking.
 */
function booking_confirm_booking($optionid, $user, $cm, $url) {
    global $OUTPUT;
    echo $OUTPUT->header();

    $option = new \mod_booking\booking_option($cm->id, $optionid, array(), 0, 0, false);

    $optionidarray['answer'] = $optionid;
    $optionidarray['confirm'] = 1;
    $optionidarray['sesskey'] = $user->sesskey;
    $optionidarray['id'] = $cm->id;
    $requestedcourse = "<br />" . $option->option->text;
    if ($option->option->coursestarttime != 0) {
        $requestedcourse .= "<br />" .
                 userdate($option->option->coursestarttime, get_string('strftimedatetime')) . " - " .
                 userdate($option->option->courseendtime, get_string('strftimedatetime'));
    }
    $message = "<h2>" . get_string('confirmbookingoffollowing', 'booking') . "</h2>" .
             $requestedcourse;
    $message .= "<p><b>" . get_string('agreetobookingpolicy', 'booking') . ":</b></p>";
    $message .= "<p>" . $option->booking->bookingpolicy . "<p>";
    echo $OUTPUT->confirm($message, new moodle_url('/mod/booking/view.php', $optionidarray), $url);
    echo $OUTPUT->footer();
}

// Update option start and end datetime - when you add session time
function booking_updatestartenddate($optionid) {
    GLOBAL $DB;

    $result = $DB->get_record_sql(
            'SELECT MIN(coursestarttime) AS coursestarttime, MAX(courseendtime) AS courseendtime FROM {booking_optiondates} WHERE optionid = ?',
            array($optionid));

    $save = new stdClass();
    $save->id = $optionid;

    if (is_null($result->coursestarttime)) {
        $save->coursestarttime = 0;
        $save->courseendtime = 0;
    } else {
        $save->coursestarttime = $result->coursestarttime;
        $save->courseendtime = $result->courseendtime;
    }

    $DB->update_record("booking_options", $save);
}


class mypdf extends TCPDF {

    /**
     *
     * @param unknown $h
     * @return boolean
     */
    public function go_to_newline($h) {
        return $this->checkPageBreak($h, '', true);
    }
}

function booking_download_sign_in_sheet(mod_booking\booking_option $bookingdata = null) {
    global $CFG, $DB;

    $users = $DB->get_records_sql(
            'SELECT u.id, u.firstname, u.lastname
            FROM {booking_answers} ba
            LEFT JOIN {user} u ON u.id = ba.userid
            WHERE ba.optionid = ? ORDER BY u.lastname ASC',
            array($bookingdata->option->id));

    $teachers = array();

    foreach ($bookingdata->option->teachers as $value) {
        $teachers[] = "{$value->firstname} {$value->lastname}";
    }

    $times = get_string('datenotset', 'booking');

    if ($bookingdata->option->coursestarttime == 0) {
        $times = get_string('datenotset', 'booking');
    } else {
        if (is_null($bookingdata->option->times)) {
            $times = userdate($bookingdata->option->coursestarttime) . " -" .
                     userdate($bookingdata->option->courseendtime);
        } else {
            $val = array();
            $times = explode(',', $bookingdata->option->times);
            foreach ($times as $time) {
                $slot = explode('-', $time);
                $tmpdate = new stdClass();
                $tmpdate->leftdate = userdate($slot[0], get_string('leftdate', 'booking'));
                $tmpdate->righttdate = userdate($slot[1], get_string('righttdate', 'booking'));

                $val[] = get_string('leftandrightdate', 'booking', $tmpdate);
            }

            $times = implode(", ", $val);
        }
    }

    $pdf = new mypdf(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_LEFT);
    $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->setFontSubsetting(false);

    $pdf->AddPage();
    $pdf = booking_set_pdf_font($pdf, $bookingdata, $teachers, $times);

    foreach ($users as $user) {
        if ($pdf->go_to_newline(12)) {
            $pdf = booking_set_pdf_font($pdf, $bookingdata, $teachers, $times);
        }
        $pdf->Cell((210 - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / 2, 12,
                $user->lastname . ", " . $user->firstname, 1, 0, '', 0);
        $pdf->Cell(0, 12, "", 1, 1, '', 0);
    }

    $pdf->Output($bookingdata->option->text . '.pdf', 'D');
}
/**
 * Set font for pdf via tcpdf
 *
 * @param mypdf $pdf
 * @param mod_booking\booking_option $bookingdata
 * @param array $teachers
 * @param string $times
 * @return mypdf
 */
function booking_set_pdf_font(mypdf $pdf, mod_booking\booking_option $bookingdata, array $teachers, $times) {
    $pdf->SetFont(PDF_FONT_NAME_MAIN, '', 14);
    $pdf->MultiCell(0, 0, $bookingdata->option->text, 0, 1, '', 1);
    $pdf->Ln();

    $pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
    $pdf->Cell(0, 0, get_string('teachers', 'booking') . implode(', ', $teachers), 0, 1, '',
            0);
    $pdf->Ln();

    $pdf->MultiCell($pdf->GetStringWidth(get_string('pdfdate', 'booking')) + 5, 0,
            get_string('pdfdate', 'booking'), 0, 1, '', 0);
    $pdf->MultiCell(0, 0, $times, 0, 1, '', 1);

    $pdf->Cell(0, 0, get_string('pdflocation', 'booking') . $bookingdata->option->address,
            0, 1, '', 0);

    $pdf->Cell(0, 0, get_string('pdfroom', 'booking') . $bookingdata->option->location, 0,
            1, '', 0);
    $pdf->Ln();

    $pdf->Cell($pdf->GetStringWidth(get_string('pdftodaydate', 'booking')) + 1, 0,
            get_string('pdftodaydate', 'booking'), 0, 0, '', 0);
    $pdf->Cell(100, 0, "", "B", 1, '', 0);
    $pdf->Ln();

    $pdf->SetFont(PDF_FONT_NAME_MAIN, 'B', 12);
    $pdf->Cell((210 - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / 2, 0,
            get_string('pdfstudentname', 'booking'), 1, 0, '', 0);
    $pdf->Cell(0, 0, get_string('pdfsignature', 'booking'), 1, 1, '', 0);
    $pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
    return $pdf;
}
