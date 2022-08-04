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

namespace mod_booking;

use html_writer;
use mod_booking\utils\wb_payment;
use moodle_url;
use stdClass;

/**
 * Booking utils.
 *
 * @package mod_booking
 * @copyright 2014 Andraž Prinčič, 2021 onwards - Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_utils {

    /**
     * @var int|null
     */
    public $secondstostart = null;

    /**
     * @var int|null
     */
    public $secondspassed = null;

    /**
     * @var stdClass
     */
    public $booking = null;

    /**
     * @var stdClass
     */
    public $bookingoption = null;

    public function __construct($booking = null, $bookingoption = null) {

        if ($booking) {
            $this->booking = $booking;
        }
        if ($bookingoption) {
            $this->bookingoption = $bookingoption;
        }

    }

    public function get_pretty_duration($seconds) {
        return $this->pretty_duration($seconds);
    }

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
     * @param stdClass $settings
     * @param stdClass $option
     * @return stdClass data to be sent via mail
     */
    public function generate_params(stdClass $settings, stdClass $option = null): stdClass {
        global $DB, $CFG;

        $params = new stdClass();

        $params->duration = $settings->duration;
        $params->eventtype = $settings->eventtype;

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

            $timeformat = get_string('strftimetime', 'langconfig');
            $dateformat = get_string('strftimedate', 'langconfig');

            $duration = '';
            if ($option->coursestarttime && $option->courseendtime) {
                $seconds = $option->courseendtime - $option->coursestarttime;
                $duration = $this->pretty_duration($seconds);
            }
            $courselink = '';
            if ($option->courseid) {
                $baseurl = $CFG->wwwroot;
                $courselink = new moodle_url($baseurl . '/course/view.php', array('id' => $option->courseid));
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
                $params->pollurl = $settings->pollurl;
            }
            if (!empty($option->pollurlteachers)) {
                $params->pollurlteachers = $option->pollurlteachers;
            } else {
                $params->pollurlteachers = $settings->pollurlteachers;
            }

            $val = '';
            if (!empty($option->optiontimes)) {
                $additionaltimes = explode(',', $option->optiontimes);
                if (!empty($additionaltimes)) {
                    foreach ($additionaltimes as $t) {
                        $slot = explode('-', $t);
                        $tmpdate = new stdClass();
                        $tmpdate->leftdate = userdate($slot[0],
                                get_string('strftimedatetime', 'langconfig'));
                        $tmpdate->righttdate = userdate($slot[1],
                                get_string('strftimetime', 'langconfig'));
                        $val .= get_string('leftandrightdate', 'booking', $tmpdate) . '<br>';
                    }
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
     * Function to generate booking button, moved here from all_options to make it available also on coursepage and elsewhere
     * @param $cm
     * @param $booking
     * @return string
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function return_button_based_on_record($booking, $context, $values, $coursepage = false) {
        global $OUTPUT, $USER, $CFG;
        $this->booking = $booking;
        $delete = '';
        $availability = '';
        $button = '';
        $bookbutton = '';
        $booked = '';
        $manage = '';
        $inpast = $values->courseendtime && ($values->courseendtime < time());

        $baseurl = $CFG->wwwroot;

        $underlimit = ($values->maxperuser == 0);
        $underlimit = $underlimit || ($values->bookinggetuserbookingcount < $values->maxperuser);
        if (!$values->limitanswers) {
            $availability = "available";
        } else if (($values->waiting + $values->booked) >= ($values->maxanswers + $values->maxoverbooking)) {
            $availability = "full";
        }

        if (time() > $values->bookingclosingtime and $values->bookingclosingtime != 0) {
            $availability = "closed";
        }

        // I'm booked or not.
        if ($values->iambooked) {
            if ($values->allowupdate and $availability != 'closed' and $values->completed != 1) {

                if (!$coursepage) {
                    $buttonoptions = array('id' => $booking->cm->id, 'action' => 'delbooking',
                            'optionid' => $values->id, 'sesskey' => $USER->sesskey);
                    $buttonmethod = 'post';
                } else {
                    $buttonoptions = array('id' => $booking->cm->id, 'action' => 'showonlyone',
                            'whichview' => 'showonlyone',
                            'optionid' => $values->id, 'sesskey' => $USER->sesskey);
                    $buttonmethod = 'get';
                }
                $url = new moodle_url($baseurl . '/mod/booking/view.php', $buttonoptions);
                $delete = '<div class="col-ap-cancelbooking">' . $OUTPUT->single_button($url,
                        (empty($values->btncancelname) ? get_string('cancelbooking', 'booking') : $values->btncancelname),
                        $buttonmethod) . '</div>';

                if ($values->coursestarttime > 0 && $values->allowupdatedays > 0) {
                    if (time() > strtotime("-{$values->allowupdatedays} day", $values->coursestarttime)) {
                        $delete = "";
                    }
                }
            }

            if (!empty($values->completed)) {
                $completed = '<div class="col-ap-completed">' . get_string('completed', 'mod_booking') .
                        '<span class="fa fa-check float-right"> </span> </div>';
            } else {
                $completed = '';
            }

            if (!empty($values->status)) {
                $status = '<div class="col-ap-presence">' . get_string('presence', 'mod_booking') .
                        '<span class="badge badge-default float-right">' . $this->col_status($values) . '</span> </div>';
            } else {
                $status = '';
            }
            if ($values->waitinglist) {
                $booked .= '<div class="btn alert-info col-ap-onwaitinglist">' . get_string('onwaitinglist', 'booking') . '</div>';
            } else if ($inpast) {
                $booked .= '<div class="btn alert-success col-ap-bookedpast">' . get_string('bookedpast', 'booking')
                    . $completed . $status . '</div>';
            } else {
                $booked .= '<div class="btn alert-success col-ap-booked">' . get_string('booked', 'booking')
                    . $completed . $status . '</div>';
            }
        } else {
            if (!$coursepage) {
                $buttonoptions = array('answer' => $values->id, 'id' => $booking->cm->id,
                        'sesskey' => $USER->sesskey);
                if (empty($this->booking->settings->bookingpolicy)) {
                    $buttonoptions['confirm'] = 1;
                }
                $buttonmethod = 'post';
            }

            // If elective settings are NOT selected...
            if (!($this->booking->is_elective())) {
                $buttonmethod = 'post';
                if (isset($_GET['whichview'])) {
                    $buttonoptions['whichview'] = $_GET['whichview'];
                }
                $buttonoptions['optionid'] = $values->id;
                $url = new moodle_url($baseurl . '/mod/booking/view.php', $buttonoptions);
                $bookbutton = '<div class="col-ap-booknow">' . $OUTPUT->single_button($url,
                                (empty($values->btnbooknowname) ? get_string('booknow', 'booking') : $values->btnbooknowname),
                                $buttonmethod) . '</div>';
            }

            // If elective settings are selected...
            if ($this->booking->is_elective()) {
                if (isset($_GET['whichview'])) {
                    $buttonoptions['whichview'] = $_GET['whichview'];
                }
                $buttonoptions['optionid'] = $values->id;

                if (!isset($_GET['list'])
                    || (!$electivesarray = json_decode($_GET['list']))) {
                    $electivesarray = [];
                    $listorder = '[]';
                } else {
                    $listorder = $_GET['list'];
                }
                $buttonoptions['list'] = $listorder;
                $buttonoptions['answer'] = $values->id;
                // Create URL for the buttons and add an anchor, so we can jump to it later on.
                $anchor = 'btnanswer' . $values->id;
                $url = new moodle_url('view.php', $buttonoptions, $anchor);

                // Check if already selected.
                // Show the select button if the elective was not already selected.
                if ((!in_array($buttonoptions['answer'], $electivesarray))) {
                    // Add an id and use an anchor# to jump to active selection.
                    $button = html_writer::link($url, get_string('electiveselectbtn', 'booking'),
                    [ 'class' => 'btn btn-info', 'id' => 'btnanswer' . $values->id]);
                } else {
                    // Else, show a deselect button.
                    // Add an id and use an anchor# to jump to active selection.
                    $button = html_writer::link($url, get_string('electivedeselectbtn', 'booking'),
                    ['class' => 'btn btn-danger', 'id' => 'btnanswer' . $values->id]);
                }

            }

        }

        if (($values->limitanswers && ($availability == "full")) || ($availability == "closed") || !$underlimit ||
                $values->disablebookingusers) {
            $button = '';
            $bookbutton = '';

        }

        if ($values->cancancelbook == 0 && $values->courseendtime > 0 &&
                $values->courseendtime < time()) {
            $button = '';
            $bookbutton = '';
            $delete = '';
        }

        if (!empty($this->booking->settings->banusernames)) {
            $disabledusernames = explode(',', $this->booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = '';
                    $bookbutton = '';
                }
            }
        }

        // Check if user has right to book.
        if (!has_capability('mod/booking:choose', $context, $USER->id, false)) {
            $button = '<div class="col-ap-norighttobook">' . get_string('norighttobook', 'booking') . "</div><br/>";
            $bookbutton = '<div class="col-ap-norighttobook">' . get_string('norighttobook', 'booking') . "</div><br/>";
        }

        // We only run this if we are not on coursepage.
        if (!$coursepage) {
            if (has_capability('mod/booking:readresponses', $context) || $values->isteacher) {
                if (groups_get_activity_groupmode($booking->cm) == SEPARATEGROUPS
                        AND !has_capability('moodle/site:accessallgroups', \context_course::instance($this->booking->course->id))) {
                    $numberofresponses = $values->allbookedsamegroup;
                } else {
                    $numberofresponses = $values->waiting + $values->booked;
                }
                $manage = "<br><a href=\"report.php?id={$booking->cm->id}&optionid={$values->id}\">" .
                        get_string("viewallresponses", "booking", $numberofresponses) . "</a>";
            }
            if ($booking->settings->ratings > 0) {
                $manage .= '<div><select class="starrating" id="rate' . $values->id .
                        '" data-current-rating="' . $values->myrating . '" data-itemid="' .
                        $values->id . '">
  <option value="1">1</option><option value="2">2</option><option value="3">3</option>
  <option value="4">4</option><option value="5">5</option></select></div>';
                if (has_capability('mod/booking:readresponses', $context) || $values->isteacher) {
                    $manage .= get_string('aggregateavg', 'rating') . ' ' . number_format(
                                    (float) $values->rating, 2, '.', '') . " ({$values->ratingcount})";
                }
            }
        }

        if ($values->courseendtime > 0 &&  $values->courseendtime < time()) {
            $limit = get_string('eventalreadyover', 'booking');
        } else if (!$coursepage) {
            $limit = "<div class='col-ap-unlimited'>" . get_string("unlimited", 'booking') . "</div>";
        } else {
            $limit = '';
        }

        if (!$values->limitanswers) {
            return $bookbutton . $button . $booked . $delete . $limit . $manage;
        } else {
            $places = new places($values->maxanswers, $values->availableplaces, $values->maxoverbooking,
                    $values->maxoverbooking - $values->waiting);

            // If the event lies in the past do not show availability of waiting list info texts at all.
            if ($values->courseendtime > 0 &&  $values->courseendtime < time()) {
                $availableplaces = get_string("eventalreadyover", "booking");
                $waitingplaces = "";
            } else if (($values->limitanswers && ($availability == "full")) || ($availability == "closed") || !$underlimit ||
                $values->disablebookingusers) {
                $availableplaces = get_string("nobookingpossible", "booking");
                $waitingplaces = "";
            } else {
                // Check if a PRO license is active and the checkbox for booking places info texts in plugin config is activated.
                if (wb_payment::is_currently_valid_licensekey()
                    && get_config('booking', 'bookingplacesinfotexts')
                    && $places->maxanswers != 0) {

                    $bookingplaceslowpercentage = get_config('booking', 'bookingplaceslowpercentage');
                    $actualpercentage = ($places->available / $places->maxanswers) * 100;

                    if ($places->available == 0) {
                        // No places left.
                        $availableplaces = "<div class='col-ap-availableplaces'>" .
                            get_string("bookingplacesfullmessage", "booking") . "</div>";
                    } else if ($actualpercentage <= $bookingplaceslowpercentage) {
                        // Only a few places left.
                        $availableplaces = "<div class='col-ap-availableplaces'>" .
                            get_string("bookingplaceslowmessage", "booking") . "</div>";
                    } else {
                        // Still enough places left.
                        $availableplaces = "<div class='col-ap-availableplaces'>" .
                            get_string("bookingplacesenoughmessage", "booking") . "</div>";
                    }
                } else {
                    if ($places->maxanswers != 0) {
                        // If booking places info texts are not active, show the actual numbers instead.
                        $availableplaces = "<div class='col-ap-availableplaces'>" .
                            get_string("availableplaces", "booking", $places) . "</div>";
                    } else {
                        // If maxanswers are set to 0, don't show anything.
                        $availableplaces = "";
                    }
                }

                // Check if a PRO license is active and the checkbox for waiting list info texts in plugin config is activated.
                if (wb_payment::is_currently_valid_licensekey()
                    && get_config('booking', 'waitinglistinfotexts')
                    && $places->maxoverbooking != 0) {

                    $waitinglistlowpercentage = get_config('booking', 'waitinglistlowpercentage');
                    $actualpercentage = ($places->overbookingavailable / $places->maxoverbooking) * 100;

                    if ($places->overbookingavailable == 0) {
                        // No places left.
                        $waitingplaces = "<div class='col-ap-waitingplacesavailable'>" .
                            get_string("waitinglistfullmessage", "booking") . "</div>";
                    } else if ($actualpercentage <= $waitinglistlowpercentage) {
                        // Only a few places left.
                        $waitingplaces = "<div class='col-ap-waitingplacesavailable'>" .
                            get_string("waitinglistlowmessage", "booking") . "</div>";
                    } else {
                        // Still enough places left.
                        $waitingplaces = "<div class='col-ap-waitingplacesavailable'>" .
                            get_string("waitinglistenoughmessage", "booking") . "</div>";
                    }
                } else {
                    if ($places->maxoverbooking != 0) {
                        // If waiting list info texts are not active, show the actual numbers instead.
                        $waitingplaces = "<div class='col-ap-waitingplacesavailable'>" .
                            get_string("waitingplacesavailable", "booking", $places) . "</div>";
                    } else {
                        // If there is no waiting list, don't show anything.
                        $waitingplaces = "";
                    }
                }
            }

            return $bookbutton . $button . $booked . $delete . $availableplaces . $waitingplaces . $manage;
        }
    }

    /**
     * Function to determine the way start and end date are displayed on course page
     * Also, if there are no dates set, we return an empty string
     * @param $start
     * @param $end
     * @return string
     */
    public function return_string_from_dates($start, $end) {

        // If start or end is 0, we return no dates.
        if ($start == 0 || $end == 0) {
            return '';
        }

        $current = userdate($start, get_string('strftimedate', 'langconfig'));
        $previous = userdate($end, get_string('strftimedate', 'langconfig'));

        if ($current == $previous) {
            $starttime = userdate($start, get_string('strftimedaydatetime', 'langconfig'));
            $endtime = userdate($end, get_string('strftimetime', 'langconfig'));
        } else {
            $starttime = userdate($start, get_string('strftimedaydatetime', 'langconfig'));
            $endtime = '<br>' . userdate($end, get_string('strftimedaydatetime', 'langconfig'));
        }

        return "$starttime - $endtime";
    }

    /**
     * Function to define reaction on changes of booking options and its sessions.
     * @param $option
     * @param $changes
     * @throws \coding_exception
     */
    public function react_on_changes($cmid, $context, $optionid, $changes) {
        global $DB, $USER;
        $bo = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        // If changes concern only the add to calendar_field, we don't want to send a mail.
        $index = null;
        $addtocalendar = 0;
        foreach ($changes as $key => $value) {
            if (isset($value['fieldname']) && $value['fieldname'] == 'addtocalendar') {
                $addtocalendar = $value['newvalue'];
                $index = $key;
            }
        }
        if ($index !== null) {
            array_splice($changes, $key, 1);
        }

        // If we still have changes, we can send the confirmation mail.
        if (count($changes) > 0
                && $bo->booking->settings->sendmail) {
            $bookinganswers = $bo->get_all_users_booked();
            if (!empty($bookinganswers)) {
                foreach ($bookinganswers as $bookinganswer) {
                    $bookeduser = $DB->get_record('user', ['id' => $bookinganswer->userid]);
                    $bo->send_confirm_message($bookeduser, true, $changes);
                }
            }
        }

        // Todo: We could delete all calendar entries of this option here, if addtocalendar is 0.
        // But we are not sure if it's a good idea.

        // We trigger the event only if we have real changes OR if we set the calendar entry to 1.
        if (count($changes) > 0 || $addtocalendar == 1) {
            // Also, we need to trigger the bookingoption_updated event, in order to update calendar entries.
            $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $optionid,
                    'userid' => $USER->id));
            $event->trigger();
        }
    }

    /**
     * Helper function to check if a booking option has associated sessions (optiondates).
     * @param $optionid int The id of a booking option.
     * @return bool
     * @throws \dml_exception
     */
    public static function booking_option_has_optiondates(int $optionid) {
        global $DB;
        $sql = "SELECT * FROM {booking_optiondates} WHERE optionid = :optionid";
        $sessions = $DB->get_records_sql($sql, ['optionid' => $optionid]);

        if (empty($sessions)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Helper function to return a string and arrays containing all relevant customfields update changes.
     * The string will be used to replace the {changes} placeholder in update mails.
     * The returned arrays will have the prepared stdclasses for update and insert in booking_customfields table.
     * @param $oldcustomfields
     * @param $newcustomfields
     */
    public function booking_customfields_get_changes($oldcustomfields, $data) {

        $updates = [];
        $inserts = [];
        $changes = [];
        $deletes = [];

        foreach ($data as $key => $value) {
            if (strpos($key, 'customfieldid') !== false) {

                $counter = (int)substr($key, -1);

                // First check if the field existed before.
                if ($value != 0 && $oldfield = $oldcustomfields[$value]) {

                    // If the delete checkbox has been set, add to deletes.
                    if ($data->{'deletecustomfield' . $counter} == 1) {
                        $deletes[] = $value; // The ID of the custom field that needs to be deleted.

                        // Also add to changes.
                        $changes[] = ['info' => get_string('changeinfocfdeleted', 'booking'),
                                      'oldname' => $oldfield->cfgname,
                                      'oldvalue' => $oldfield->value];

                        continue;
                    }

                    /* Custom field changes do not contain a 'fieldname' so they can easily be identified by the
                     * mustache template (bookingoption_changes.mustache). They always will contain 'newname' and
                     * 'newvalue'; 'oldname' and 'oldvalue' will only be included, if there has been a change. */

                    $haschange = false;
                    $currentchange = [];
                    // Check if the name of the custom field has changed.
                    if ($oldfield->cfgname != $data->{'customfieldname' . $counter}) {
                        $currentchange = array_merge($currentchange,
                            ['info' => get_string('changeinfocfchanged', 'booking'),
                             'oldname' => $oldfield->cfgname,
                             'newname' => $data->{'customfieldname' . $counter}]);
                        $haschange = true;
                    } else {
                        // Do not add the old name, if there has been no change.
                        $currentchange = array_merge($currentchange,
                            ['newname' => $data->{'customfieldname' . $counter}]);
                    }

                    // Check if the value of the custom field has changed.
                    if (!empty($data->{'customfieldvalue' . $counter}) &&
                        $oldfield->value != $data->{'customfieldvalue' . $counter}) {
                        $currentchange = array_merge($currentchange,
                            ['info' => get_string('changeinfocfchanged', 'booking'),
                             'oldvalue' => $oldfield->value,
                             'newvalue' => $data->{'customfieldvalue' . $counter}]);
                        $haschange = true;
                    } else {
                        // Do not add the old value, if there has been no change.
                        $currentchange = array_merge($currentchange,
                            ['newvalue' => $data->{'customfieldvalue' . $counter}]);
                    }

                    if ($haschange) {
                        // Also add optionid, sessionid and fieldid (needed to create link via link.php).
                        $currentchange = array_merge($currentchange,
                            ['customfieldid' => $value,
                             'optionid' => $this->bookingoption->option->id,
                             'optiondateid' => $data->optiondateid]);

                        // Add to changes.
                        $changes[] = $currentchange;

                        // Create custom field object and add to updates.
                        $customfield = new stdClass();
                        $customfield->id = $value;
                        $customfield->bookingid = $this->booking->id;
                        $customfield->optionid = $this->bookingoption->option->id;
                        $customfield->optiondateid = $data->optiondateid;
                        $customfield->cfgname = $data->{'customfieldname' . $counter};
                        $customfield->value = $data->{'customfieldvalue' . $counter};

                        $updates[] = $customfield;
                    }
                } else {
                    // Create new custom field and add to inserts.
                    if (!empty($this->booking) && !empty($this->bookingoption)) {
                        if (!empty($data->{'customfieldname' . $counter})) {
                            $customfield = new stdClass();
                            $customfield->bookingid = $this->booking->id;
                            $customfield->optionid = $this->bookingoption->option->id;
                            $customfield->optiondateid = $data->optiondateid;
                            $customfield->cfgname = $data->{'customfieldname' . $counter};
                            $customfield->value = $data->{'customfieldvalue' . $counter};

                            $inserts[] = $customfield;

                            // Also add to changes.
                            $changes[] = ['info' => get_string('changeinfocfadded', 'booking'),
                                          'newname' => $data->{'customfieldname' . $counter},
                                          'newvalue' => $data->{'customfieldvalue' . $counter},
                                          'optionid' => $this->bookingoption->option->id,
                                          'optiondateid' => $data->optiondateid];
                        }
                    }
                }
            }
        }

        return [
                'changes' => $changes,
                'inserts' => $inserts,
                'updates' => $updates,
                'deletes' => $deletes
        ];
    }

    /**
     * Helper function to return an array containing all relevant option update changes.
     *
     * @param $oldoption stdClass the original booking option object
     * @param $newoption stdClass the new booking option object
     * @return array an array containing the changes that have been made
     */
    public function booking_option_get_changes($oldoption, $newoption) {
        $returnarry = [];

        if (isset($oldoption->text)
                && $oldoption->text != $newoption->text) {
            $returnarry[] = [
                    'info' => get_string('bookingoptiontitle', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'bookingoptiontitle',
                    'oldvalue' => $oldoption->text,
                    'newvalue' => $newoption->text
            ];
        }
        if (isset($oldoption->coursestarttime)
                && $oldoption->coursestarttime != $newoption->coursestarttime) {
            $returnarry[] = [
                    'info' => get_string('coursestarttime', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'coursestarttime',
                    'oldvalue' => $oldoption->coursestarttime,
                    'newvalue' => $newoption->coursestarttime
            ];
        }
        if (isset($oldoption->courseendtime)
                && $oldoption->courseendtime != $newoption->courseendtime) {
            $returnarry[] = [
                    'info' => get_string('courseendtime', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'courseendtime',
                    'oldvalue' => $oldoption->courseendtime,
                    'newvalue' => $newoption->courseendtime
            ];
        }
        if (isset($oldoption->location)
                && $oldoption->location != $newoption->location) {
            $returnarry[] = [
                    'info' => get_string('location', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'location',
                    'oldvalue' => $oldoption->location,
                    'newvalue' => $newoption->location
            ];
        }
        if (isset($oldoption->institution)
                && $oldoption->institution != $newoption->institution) {
            $returnarry[] = [
                    'info' => get_string('institution', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'institution',
                    'oldvalue' => $oldoption->institution,
                    'newvalue' => $newoption->institution
            ];
        }
        if (isset($oldoption->address)
                && $oldoption->address != $newoption->address) {
            $returnarry[] = [
                    'info' => get_string('address', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'address',
                    'oldvalue' => $oldoption->address,
                    'newvalue' => $newoption->address
            ];
        }
        if (isset($oldoption->description)
                && $oldoption->description != $newoption->description) {
            $returnarry[] = [
                    'info' => get_string('description', 'booking') . get_string('changeinfochanged', 'booking'),
                    'fieldname' => 'description',
                    'oldvalue' => $oldoption->description,
                    'newvalue' => $newoption->description
            ];
        }
        // We have to check for changed "adtocalendar"-value, because we need to trigger update event (but not send mail).
        if (isset($oldoption->addtocalendar)
                && $oldoption->addtocalendar != $newoption->addtocalendar) {
            $returnarry[] = [
                    'fieldname' => 'addtocalendar',
                    'oldvalue' => $oldoption->addtocalendar,
                    'newvalue' => $newoption->addtocalendar
            ];
        }
        if (count($returnarry) > 0) {
            return $returnarry;
        } else {
            return [];
        }
    }

    /**
     * Helper function to return an array containing all relevant session update changes.
     *
     * @param $oldoptiondate stdClass the original session object
     * @param $newoptiondate stdClass the new session object
     * @return array an array containing the changes that have been made
     */
    public function booking_optiondate_get_changes($oldoptiondate, $newoptiondate) {
        $changes = [];

        if (isset($oldoptiondate->coursestarttime)
            && $oldoptiondate->coursestarttime != $newoptiondate->coursestarttime) {
            $changes[] = [
                'info' => get_string('coursestarttime', 'booking') . get_string('changeinfochanged', 'booking'),
                'fieldname' => 'coursestarttime',
                'oldvalue' => $oldoptiondate->coursestarttime,
                'newvalue' => $newoptiondate->coursestarttime
            ];
        }

        if (isset($oldoptiondate->courseendtime)
            && $oldoptiondate->courseendtime != $newoptiondate->courseendtime) {
            $changes[] = [
                'info' => get_string('courseendtime', 'booking') . get_string('changeinfochanged', 'booking'),
                'fieldname' => 'courseendtime',
                'oldvalue' => $oldoptiondate->courseendtime,
                'newvalue' => $newoptiondate->courseendtime
            ];
        }

        return [
            'changes' => $changes
        ];
    }

    private function col_status($values) {
        switch ($values->status) {
            case 1:
                return get_string('status_complete', 'booking');
            case 2:
                return get_string('status_incomplete', 'booking');
            case 3:
                return get_string('status_noshow', 'booking');
            case 4:
                return get_string('status_failed', 'booking');
            case 5:
                return get_string('status_unknown', 'booking');
            case 6:
                return get_string('status_attending', 'booking');
            case 0:
            default:
                return '';
        }
    }

    /**
     * Helper function to hide all option user events.
     * We need this if we switch from option to multisession.
     */
    public function booking_hide_option_userevents ($optionid) {
        global $DB;
        $userevents = $DB->get_records('booking_userevents', ['optionid' => $optionid, 'optiondateid' => null]);

        foreach ($userevents as $userevent) {
            if ($event = $DB->get_record('event', ['id' => $userevent->eventid])) {
                $event->visible = 0;
                $DB->update_record('event', $event);
            } else {
                return false;
            }
        }
    }

    /**
     * Helper function to show all option user events.
     * We need this if we switch from multisession to option.
     */
    public function booking_show_option_userevents ($optionid) {
        global $DB;
        $userevents = $DB->get_records('booking_userevents', ['optionid' => $optionid, 'optiondateid' => null]);

        foreach ($userevents as $userevent) {
            if ($event = $DB->get_record('event', ['id' => $userevent->eventid])) {
                $event->visible = 1;
                $DB->update_record('event', $event);
            } else {
                return false;
            }
        }
    }

    /**
     * Helper function to generate a subscription link to the Moodle calendar.
     * The calendar export time range can be set in Site_admin > Appearance > Calendar.
     * Use $eventparam to specify the event type to be exported (user events are the default).
     *
     * @param stdClass $user the user the calendar link is for
     * @param string $eventparam ('all' | 'categories' | 'courses' | 'groups' | 'user')
     * @return string the subscription link
     */
    public function booking_generate_calendar_subscription_link ($user, $eventparam = 'user') {
        $authtoken = $this->calendar_get_export_token($user);

        $linkparams = [
            'userid' => $user->id,
            'authtoken' => $authtoken,
            'preset_what' => $eventparam,
            'preset_time' => 'custom'
        ];
        $subscriptionlink = new moodle_url('/calendar/export_execute.php', $linkparams);

        return $subscriptionlink->__toString();
    }

    /**
     * Function to book cohort or group members(users).
     * Result as an object containing the following numbers:
     * - sumcohortmembers All cohort members that have been tried to subscribe.
     * - sumgroupmembers All group members that have been tried to subscribe.
     * - subscribedusers Users that were subscribed successfully.
     * - sumgroupmembers All group members that have been tried to subscribe.
     * - notenrolledusers Users that could not be subscribed because of missing course enrolment.
     * - notsubscribedusers Users that could not be subscribed for all reasons else.
     *
     * @param stdClass $fromform
     * @param booking_option $bookingoption
     * @param $context
     * @return stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function book_cohort_or_group_members(stdClass $fromform, booking_option $bookingoption, $context): stdClass {

        global $DB;

        // Create the return object.
        $result = new stdClass();

        $cohortmembersarray = [];
        $groupmembersarray = [];
        $notenrolledusersarray = [];
        $notsubscribedusersarray = [];
        $subscribedusersarray = [];

        $result->sumcohortmembers = 0;
        $result->sumgroupmembers = 0;
        $result->notenrolledusers = 0;
        $result->notsubscribedusers = 0;
        $result->subscribedusers = 0;

        // Part 1: Book cohort members.
        foreach ($fromform->cohortids as $cohortid) {

            // Retrieve all users of this cohort.
            $sql = "SELECT u.*
                    FROM {user} u
                    JOIN {cohort_members} cm
                    ON u.id = cm.userid
                    WHERE cm.cohortid = :cohortid";
            $cohortmembers = $DB->get_records_sql($sql, ['cohortid' => $cohortid]);
            $cohortmembersarray = array_merge($cohortmembersarray, $cohortmembers);

            // Verify if the editing user can see the cohorts.
            if (!cohort_get_cohort($cohortid, $context)) {
                // Members of cohorts with no permission.
                $notsubscribedusersarray = array_merge($notsubscribedusersarray, $cohortmembers);
                continue;
            }

            if (has_capability('mod/booking:subscribeusers', $context) or
                (booking_check_if_teacher($bookingoption->option))) {
                foreach ($cohortmembers as $user) {

                    // First, we only book users which are already subscribed to this course.
                    if (!is_enrolled($context, $user, null, true)) {
                        // Track users who were not subscribed because they were not enrolled in the course.
                        $notenrolledusersarray[] = $user;
                        continue;
                    }
                    if (!$bookingoption->user_submit_response($user)) {
                        // Track users where subscription failed because of different reasons.
                        $notsubscribedusersarray[] = $user;
                    } else {
                        // Track users with successful subscription.
                        $subscribedusersarray[] = $user;
                    }
                }
            }
        }

        // Part 2: Book group members.
        foreach ($fromform->groupids as $groupid) {

            // Retrieve all users of this group.
            $sql = "SELECT u.*
                    FROM {user} u
                    JOIN {groups_members} gm
                    ON u.id = gm.userid
                    WHERE gm.groupid = :groupid";
            $groupmembers = $DB->get_records_sql($sql, ['groupid' => $groupid]);
            $groupmembersarray = array_merge($groupmembersarray, $groupmembers);

            if (has_capability('mod/booking:subscribeusers', $context) or
                (booking_check_if_teacher($bookingoption->option))) {

                foreach ($groupmembers as $user) {
                    // First, we only book users which are already subscribed to this course.
                    if (!is_enrolled($context, $user, null, true)) {
                        // Track users who were not subscribed because they were not enrolled in the course.
                        $notenrolledusersarray[] = $user;
                        continue;
                    }
                    if (!$bookingoption->user_submit_response($user)) {
                        // Track users where subscription failed because of different reasons.
                        $notsubscribedusersarray[] = $user;
                    } else {
                        // Track users with successful subscription.
                        $subscribedusersarray[] = $user;
                    }
                }
            }
        }

        $result->sumcohortmembers = count(array_unique($cohortmembersarray , SORT_REGULAR));
        $result->sumgroupmembers = count(array_unique($groupmembersarray , SORT_REGULAR));
        $result->notenrolledusers = count(array_unique($notenrolledusersarray , SORT_REGULAR));
        $result->notsubscribedusers = count(array_unique($notsubscribedusersarray , SORT_REGULAR));
        $result->subscribedusers = count(array_unique($subscribedusersarray , SORT_REGULAR));

        return $result;
    }

    /**
     * Copied from core_calendar > lib.php.
     * Get the auth token for exporting the given user calendar.
     * @param stdClass $user The user to export the calendar for
     *
     * @return string The export token.
     */
    private function calendar_get_export_token(stdClass $user): string {
        global $CFG, $DB;
        return sha1($user->id . $DB->get_field('user', 'password', ['id' => $user->id]) . $CFG->calendar_exportsalt);
    }

    /**
     * Helper function to check if an option name (text) already exists within the same instance.
     * If it does, return a new option name containing the separator defined in plugin config
     * and a unique key (5 digits).
     * @param stdClass $option
     * @return string A unique booking option name within the instance.
     */
    public static function booking_option_get_unique_name(stdClass $option) {
        global $DB;

        $bookingid = $option->bookingid;
        $text = $option->text;
        $separator = get_config('booking', 'uniqueoptionnameseparator');

        if (strlen($separator) == 0 || strpos($text, $separator) == false) {
            $visiblename = $text;
        } else {
            list($visiblename, $key) = explode($separator, $text);
        }

        $sql = 'SELECT id, bookingid, text FROM {booking_options}
                WHERE bookingid = :bookingid AND text = :text';
        $params = ['bookingid' => $bookingid, 'text' => $text];

        // We only have an option id if it's an update.
        if (!empty($option->id)) {
            // Exclude the option itself and only look if there are other options with the same name.
            $sql .= ' AND id <> :optionid';
            $params['optionid'] = $option->id;
        }

        $duplicates = $DB->get_records_sql($sql, $params);

        if (empty($duplicates)) {
            // If the name is unique within the booking instance, we can return it unchanged.
            return $text;
        } else {
            $uniquetext = null; // Initialize.
            do {
                // This will be set to true as soon as we have a really unique name.
                $isreallyunique = false;

                $key = substr(str_shuffle(md5(microtime())), 0, 8);

                $uniquetext = $visiblename . $separator . $key;

                if (empty($DB->get_records('booking_options', ['bookingid' => $bookingid, 'text' => $uniquetext]))) {
                    $isreallyunique = true;
                }
            } while (!$isreallyunique);

            return $uniquetext;
        }
    }

    /**
     * Function to return bookingoptionname directly from DB, as opposed from displayname (without key) normally used.
     * @param object $data
     * @return false|mixed
     * @throws \dml_exception
     */
    public static function return_unique_bookingoption_name(object $data) {
        global $DB;
        return $DB->get_field('booking_options', 'text', array('id' => $data->id));
    }

    /**
     * Prepare an associative array of optionids, each with an according array of teacher names.
     * @param array $objectswithoptionids an array containing objects with optionids
     * @return array
     */
    public static function prepare_teachernames_arrays_for_optionids(array $objectswithoptionids) {

        global $DB;

        // Prepare arrays of teacher names of every option to reduce DB-queries.
        $list = [];
        $teachers = [];
        foreach ($objectswithoptionids as $objectentry) {
            $list[] = $objectentry->optionid;
            $teachers[$objectentry->optionid] = [];
        }

        if (!empty($list)) {
            list($insql, $inparams) = $DB->get_in_or_equal($list, SQL_PARAMS_NAMED, 'optionid_');

            $sql = "SELECT DISTINCT bt.id, bt.userid, u.firstname, u.lastname, u.username, bt.optionid
                    FROM {booking_teachers} bt
                    JOIN {user} u
                    ON bt.userid = u.id
                    WHERE bt.optionid $insql";

            if ($records = $DB->get_records_sql($sql, $inparams)) {
                foreach ($records as $record) {
                    $teachers[$record->optionid][] = $record->firstname . ' ' . $record->lastname;
                }
            }
        }

        return $teachers;
    }
}
