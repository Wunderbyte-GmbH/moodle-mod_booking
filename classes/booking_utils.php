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

defined('MOODLE_INTERNAL') || die();

use html_writer;
use mod_booking\utils\wb_payment;
use moodle_url;
use stdClass;

/**
 * Utils
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
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
     * @param stdClass $booking
     * @return stdClass data to be sent via mail
     */
    public function generate_params(stdClass $booking, stdClass $option = null) {
        global $DB, $CFG;

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
                $params->pollurl = $booking->pollurl;
            }
            if (!empty($option->pollurlteachers)) {
                $params->pollurlteachers = $option->pollurlteachers;
            } else {
                $params->pollurlteachers = $booking->pollurlteachers;
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

        $delete = '';
        $availabibility = '';
        $button = '';
        $booked = '';
        $manage = '';
        $inpast = $values->courseendtime && ($values->courseendtime < time());

        $baseurl = $CFG->wwwroot;

        $underlimit = ($values->maxperuser == 0);
        $underlimit = $underlimit || ($values->bookinggetuserbookingcount < $values->maxperuser);
        if (!$values->limitanswers) {
            $availabibility = "available";
        } else if (($values->waiting + $values->booked) >= ($values->maxanswers + $values->maxoverbooking)) {
            $availabibility = "full";
        }

        if (time() > $values->bookingclosingtime and $values->bookingclosingtime != 0) {
            $availabibility = "closed";
        }

        // I'm booked or not.
        if ($values->iambooked) {
            if ($values->allowupdate and $availabibility != 'closed' and $values->completed != 1) {

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
                $booked .= '<div class="btn alert-success col-ap-bookedpast">' . get_string('bookedpast', 'booking') . $completed . $status .
                        '</div>';
            } else {
                $booked .= '<div class="btn alert-success col-ap-booked">' . get_string('booked', 'booking') . $completed . $status . '</div>';
            }
        } else {
            if (!$coursepage) {
                $buttonoptions = array('answer' => $values->id, 'id' => $booking->cm->id,
                        'sesskey' => $USER->sesskey);
                if (empty($this->booking->settings->bookingpolicy)) {
                    $buttonoptions['confirm'] = 1;
                }
                $buttonmethod = 'post';
            } else {
                $buttonmethod = 'get';
                $buttonoptions = array('id' => $booking->cm->id, 'action' => 'showonlyone',
                        'whichview' => 'showonlyone',
                        'optionid' => $values->id);
            }


            $url = new moodle_url($baseurl . '/mod/booking/view.php', $buttonoptions);

            $button = '<div class="col-ap-booknow">' . $OUTPUT->single_button($url,
                    (empty($values->btnbooknowname) ? get_string('booknow', 'booking') : $values->btnbooknowname),
                    $buttonmethod) . '</div>';
        }

        if (($values->limitanswers && ($availabibility == "full")) || ($availabibility == "closed") || !$underlimit ||
                $values->disablebookingusers) {
            $button = '';
        }

        if ($values->cancancelbook == 0 && $values->courseendtime > 0 &&
                $values->courseendtime < time()) {
            $button = '';
            $delete = '';
        }

        if (!empty($this->booking->settings->banusernames)) {
            $disabledusernames = explode(',', $this->booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = '';
                }
            }
        }

        // Check if user has right to book.
        if (!has_capability('mod/booking:choose', $context, $USER->id, false)) {
            $button = '<div class="col-ap-norighttobook">' . get_string('norighttobook', 'booking') . "</div><br/>";
        }

        // We only run this if we are not on coursepage
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

        if (!$coursepage) {
            $limit = "<div class='col-ap-unlimited'>" . get_string("unlimited", 'booking') . "</div>";
        } else {
            $limit = '';
        }

        if (!$values->limitanswers) {
            return $button . $booked . $delete . $limit . $manage;
        } else {
            $places = new places($values->maxanswers, $values->availableplaces, $values->maxoverbooking,
                    $values->maxoverbooking - $values->waiting);

            // Add string when no booking is possible.
            if (strlen($button . $delete . $booked) == 0) {
                $button = get_string('pleasereturnlater', 'booking');
            }

            $availableplaces = "<div class='col-ap-availableplaces'>" .
                get_string("availableplaces", "booking", $places) . "</div>";

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
                // If waiting list info text are not active, show the actual numbers instead.
                $waitingplaces = "<div class='col-ap-waitingplacesavailable'>" .
                    get_string("waitingplacesavailable", "booking", $places) . "</div>";
            }

            return $button . $booked . $delete . $availableplaces . $waitingplaces . $manage;
        }
    }

    /**
     * Function to return false if user has not yet the right to access conference
     * Returns the link if the user has the right
     * time before course start is hardcoded to 15 minutes
     *
     * @param $cmid
     * @param $optionid
     * @param $userid
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function show_conference_link($bookingoption, $userid, $sessionid = null) {

        global $DB;

        // First check if user is really booked.
        if ($bookingoption->iambooked != 1) {
                return false;
        }

        $now = time();
        $openingtime = strtotime("+15 minutes", $now);

        if (!$sessionid) {
            $start = $bookingoption->option->coursestarttime;
            $end = $bookingoption->option->courseendtime;
        } else {
            $start = $bookingoption->sessions[$sessionid]->coursestarttime;
            $end = $bookingoption->sessions[$sessionid]->courseendtime;
        }

        // If now plus 15 minutes is smaller than coursestarttime, we return the link.
        if ($start < $openingtime
            && $end > $now) {
            return true;
        } else {
            // If we return false here, we first have to calculate secondstostart
            $delta = $start - $now;

            if ($delta < 0) {
                $this->secondspassed = - $delta;
            } else {
                $this->secondstostart = $delta;
            }
            return false;
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

        $current = userdate($start, get_string('strftimedate'));
        $previous = userdate($end, get_string('strftimedate'));

        if ($current == $previous) {
            $starttime = userdate($start, get_string('strftimedaydatetime'));
            $endtime = userdate($end, get_string('strftimetime'));
        } else {
            $starttime = userdate($start, get_string('strftimedaydatetime'));
            $endtime = '<br>' . userdate($end, get_string('strftimedaydatetime'));
        }

        return "$starttime - $endtime";
    }

    /**
     * Helperfunction to return array with name - value items for mustache templates
     * $fields must be records from booking_customfields
     * @param $fields
     * @return array
     */
    public function return_array_of_customfields($bookingoption, $fields, $sessionid = 0, $descriptionparam = 0) {
        $returnarray = [];
        foreach ($fields as $field) {
            if ($value = $this->render_customfield_data($bookingoption, $field, $sessionid, $descriptionparam)) {
                $returnarray[] = $value;
            }
        }
        return $returnarray;
    }

    /** This function is meant to return the right name and value array for custom fields.
     * This is the place to return buttons etc. for special name, keys, like teams-meeting or zoom meeting.
     * @param $field
     */
    private function render_customfield_data($bookingoption, $field, $sessionid = 0, $descriptionparam = 0) {

        switch ($field->cfgname) {
            case 'ZoomMeeting':
            case 'BigBlueButtonMeeting':
            case 'TeamsMeeting':
                // If the session is not yet about to begin, we show placeholder
                return $this->render_meeting_fields($bookingoption, $sessionid, $field, $descriptionparam);
            default:
                return [
                    'name' => "$field->cfgname: ",
                    'value' => $field->value
                ];
        }
    }


    private function render_meeting_fields($bookingoption, $sessionid, $field, $descriptionparam) {
        global $USER, $CFG;

        $baseurl = $CFG->wwwroot;

        switch ($descriptionparam) {

            case DESCRIPTION_WEBSITE:
                // We don't want to show these Buttons at all if the user is not booked.
                if ($bookingoption->iambooked == 0) {
                    return null;
                } else {
                    // We are booked on the web site, we check if we show the real link.
                    if (!$this->show_conference_link($bookingoption, $USER->id, $sessionid)) {
                        // User is booked, if the user is booked, but event not yet open, we show placeholder with time to start.
                        return [
                                'name' => null,
                                'value' => get_string('linknotavailableyet', 'mod_booking')
                        ];
                    }
                    // User is booked and event open, we return the button with the link to access, this is for the website.
                    return [
                            'name' => null,
                            'value' => "<a href=$field->value class='btn btn-info'>$field->cfgname</a>"
                    ];
                };
            case DESCRIPTION_CALENDAR:
                // Calendar is static, so we don't have to check for booked or not.
                // In all cases, we return the Teams-Button, going by the link.php.
                if ($bookingoption->iambooked != 0) {
                    // User is booked, we show a button (for Moodle calendar ie).
                    $cm = $bookingoption->booking->cm;
                    $link = new moodle_url($baseurl . '/mod/booking/link.php',
                            array('id' => $cm->id,
                                    'optionid' => $bookingoption->optionid,
                                    'action' => 'join',
                                    'sessionid' => $sessionid,
                                    'fieldid' => $field->id
                            ));
                    return [
                            'name' => null,
                            'value' => "<a href=$link class='btn btn-info'>$field->cfgname</a>"
                    ];
                } else {
                    return null;
                }
            case DESCRIPTION_ICAL:
                // User is booked, for ical no button but link only.
                // For ical, we don't check for booked as it's always booked only.
                $cm = $bookingoption->booking->cm;
                $link = new moodle_url($baseurl . '/mod/booking/link.php',
                        array('id' => $cm->id,
                                'optionid' => $bookingoption->optionid,
                                'action' => 'join',
                                'sessionid' => $sessionid,
                                'fieldid' => $field->id
                        ));
                $link = $link->out(false);
                return [
                        'name' => null,
                        'value' => "$field->cfgname: $link"
                ];
            case DESCRIPTION_MAIL:
                // For the mail placeholder {bookingdetails} no button but link only.
                // However, we can use HTML links in mails.
                $cm = $bookingoption->booking->cm;
                $link = new moodle_url($baseurl . '/mod/booking/link.php',
                    array('id' => $cm->id,
                        'optionid' => $bookingoption->optionid,
                        'action' => 'join',
                        'sessionid' => $sessionid,
                        'fieldid' => $field->id
                    ));
                $link = $link->out(false);
                return [
                    'name' => null,
                    'value' => "$field->cfgname: <a href='$link' target='_blank'>$link</a>"
                ];
        }
    }

    /**
     * Function to define reaction on changes of booking options and its sessions.
     * @param $option
     * @param $changes
     * @throws \coding_exception
     */
    public function react_on_changes($cmid, $context, $optionid, $changes) {
        global $DB, $USER;
        $bo = new booking_option($cmid, $optionid);

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

        // We trigger the event only if we have real changes OR if we set the calendar entry to 1
        if (count($changes) > 0 || $addtocalendar == 1) {
            // Also, we need to trigger the bookingoption_updated event, in order to update calendar entries.
            $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $optionid,
                    'userid' => $USER->id));
            $event->trigger();
        }
    }

    /**
     * Helper function for mustache template to return array with datestring and customfields
     * @param $bookingoption
     * @return array
     * @throws \dml_exception
     */
    public function return_array_of_sessions($bookingoption, $bookingevent = null, $descriptionparam = 0, $withcustomfields = false) {

        global $DB;

        // If we didn't set a $bookingevent (record from booking_optiondates) we retrieve all of them for this option.
        // Else, we check if there are sessions.
        // If not, we just use normal coursestart & endtime.
        if ($bookingevent) {
            $sessions = [$bookingevent];
        } else if ($bookingoption->sessions) {
            $sessions = $bookingoption->sessions;
        } else {

            $session = new stdClass();
            $session->id = 1;
            $session->coursestarttime = $bookingoption->option->coursestarttime;
            $session->courseendtime = $bookingoption->option->courseendtime;
            $sessions = [$session];
        }
        $returnitem = [];

        if (count($sessions) > 0) {
            foreach ($sessions as $session) {

                $returnsession = [];
                // Filter the matchin customfields.
                $fields = $DB->get_records('booking_customfields', array(
                        'optionid' => $bookingoption->optionid,
                        'optiondateid' => $session->id
                ));

                // We show this only if timevalues are not 0.
                if ($session->coursestarttime != 0 && $session->courseendtime != 0) {
                    $returnsession['datestring'] = $this->return_string_from_dates($session->coursestarttime, $session->courseendtime);
                    // customfields can only be displayed in combination with timevalues.
                    if ($withcustomfields) {
                        $returnsession['customfields'] = $this->return_array_of_customfields($bookingoption, $fields, $session->id, $descriptionparam);
                    }
                }
                if ($returnsession) {
                    $returnitem[] = $returnsession;
                }

            }
        } else {
            $returnitem[] = [
                    'datesstring' => $this->return_string_from_dates(
                            $bookingoption->option->coursestarttime,
                            $bookingoption->option->courseendtime)
            ];
        }


        return $returnitem;
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
    function booking_option_get_changes($oldoption, $newoption) {
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
        } else return [];
    }

    /**
     * Helper function to return an array containing all relevant session update changes.
     *
     * @param $oldoptiondate stdClass the original session object
     * @param $newoptiondate stdClass the new session object
     * @return array an array containing the changes that have been made
     */
    function booking_optiondate_get_changes($oldoptiondate, $newoptiondate) {
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
            } else return false;
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
            } else return false;
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


}