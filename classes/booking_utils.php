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
    public $minutestostart = null;

    /**
     * @var int|null
     */
    public $minutespassed = null;


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
                $delete = $OUTPUT->single_button($url,
                        (empty($values->btncancelname) ? get_string('cancelbooking', 'booking') : $values->btncancelname),
                        $buttonmethod);

                if ($values->coursestarttime > 0 && $values->allowupdatedays > 0) {
                    if (time() > strtotime("-{$values->allowupdatedays} day", $values->coursestarttime)) {
                        $delete = "";
                    }
                }
            }

            if (!empty($values->completed)) {
                $completed = '<div class="">' . get_string('completed', 'mod_booking') .
                        '<span class="fa fa-check float-right"> </span> </div>';
            } else {
                $completed = '';
            }

            if (!empty($values->status)) {
                $status = '<div class="">' . get_string('presence', 'mod_booking') .
                        '<span class="badge badge-default float-right">' . $this->col_status($values) . '</span> </div>';
            } else {
                $status = '';
            }
            if ($values->waitinglist) {
                $booked .= '<div class="btn alert-info">' . get_string('onwaitinglist', 'booking') . '</div>';
            } else if ($inpast) {
                $booked .= '<div class="btn alert-success">' . get_string('bookedpast', 'booking') . $completed . $status .
                        '</div>';
            } else {
                $booked .= '<div class="btn alert-success">' . get_string('booked', 'booking') . $completed . $status . '</div>';
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

            $button = $OUTPUT->single_button($url,
                    (empty($values->btnbooknowname) ? get_string('booknow', 'booking') : $values->btnbooknowname),
                    $buttonmethod);
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
            $button = get_string('norighttobook', 'booking') . "<br />";
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
            $limit = "<div>" . get_string("unlimited", 'booking') . "</div>";
        } else {
            $limit = '';
        }


        if (!$values->limitanswers) {
            return $button . $delete . $booked . $limit . $manage;
        } else {
            $places = new places($values->maxanswers, $values->availableplaces, $values->maxoverbooking,
                    $values->maxoverbooking - $values->waiting);
            return $button . $delete . $booked . "<div>" . get_string("availableplaces", "booking", $places) .
                    "</div><div>" . get_string("waitingplacesavailable", "booking", $places) . "</div>" . $manage;
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

        if (!$DB->record_exists('booking_answers', array('optionid' => $bookingoption->optionid,
                                                                'userid' => $userid,
                                                                'waitinglist' => 0))) {
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
            // If we return false here, we first have to calculate minutestostart
            $delta = $start - $now;

            if ($delta < 0) {
                $this->minutespassed = - $delta / 60;
            } else {
                $this->minutestostart = $delta / 60;
            }
            return false;
        }
    }

    /**
     * Function to determine the way start and end date are displayed on course page
     * Also, if there are no dates set, we return an empty string
     * @param $optiondate
     * @return string
     */
    public function return_string_from_dates($start, $end) {

        // If start is 0, we return no dates
        if ($start == 0 || $end == 0) {
            return '';
        }
        $starttime = userdate($start, get_string('strftimedatetime'));
        $endtime = userdate($end, get_string('strftimetime'));

        return "$starttime - $endtime";
    }

    /**
     * Helperfunction to return array with name - value items for mustache templates
     * $fields must be records from booking_customfields
     * @param $fields
     * @return array
     */
    public function return_array_of_customfields($bookingoption, $fields, $sessionid = 0) {
        $returnarray = [];
        foreach ($fields as $field) {
            $returnarray[] = $this->render_customfield_data($bookingoption, $field, $sessionid);
        }
        return $returnarray;
    }

    /** This function is meant to return the right name and value array for custom fields.
     * This is the place to return buttons etc. for special name, keys, like teams-meeting or zoom meeting.
     * @param $field
     */
    private function render_customfield_data($bookingoption, $field, $sessionid = 0) {

        global $USER;

        switch ($field->cfgname) {
            case 'Teamsmeeting':
                // If the session is not yet about to begin, we show placeholder
                if (!$this->show_conference_link($bookingoption, $USER->id, $sessionid)) {
                    return get_string('linknotavailableyet', 'mod_booking');
                }
                return [
                        'name' => null,
                        'value' => "<a href=$field->value class='btn btn-info'>$field->cfgname</a>"
                ];
            case 'Zoommeeting':
                // If the session is not yet about to begin, we show placeholder
                if (!$this->show_conference_link($bookingoption, $USER->id, $sessionid)) {
                    return get_string('linknotavailableyet', 'mod_booking');
                }
                return [
                        'name' => null,
                        'value' => '<a href="' . $field->value . '" class="btn btn-warning">' . $field->cfgname . '</a>'
                ];
        }
        return [
                'name' => "$field->cfgname: ",
                'value' => $field->value
        ];
    }

    /**
     * Function to define reaction on changes of booking options and its sessions.
     * @param $option
     * @param $changes
     * @throws \coding_exception
     */
    public function react_on_changes($cmid, $context, $option, $changes) {
        global $DB, $USER;
        $bo = new booking_option($cmid, $option->id);
        // $bo->option->courseid = $option->courseid;
        if ($bo->booking->settings->sendmail) {
            $bookinganswers = $bo->get_all_users_booked();
            if (!empty($bookinganswers)) {
                foreach ($bookinganswers as $bookinganswer) {
                    $bookeduser = $DB->get_record('user', ['id' => $bookinganswer->userid]);
                    $bo->send_confirm_message($bookeduser, true, $changes);
                }
            }
        }

        // Also, we need to trigger the bookingoption_updated event, in order to update calendar entries.
        $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $option->id,
            'userid' => $USER->id));
        $event->trigger();
    }
}