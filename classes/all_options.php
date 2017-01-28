<?php

/**
 * Display all options.
 *
 * @package    mod_booking
 * @copyright  2016 Andraž Prinčič <atletek@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

class all_options extends table_sql {

    var $booking = null;
    var $cm = null;
    var $context = null;

    function __construct($uniqueid, $booking, $cm, $context) {
        parent::__construct($uniqueid);

        $this->collapsible(true);
        $this->sortable(true);
        $this->pageable(true);
        $this->booking = $booking;
        $this->cm = $cm;
        $this->context = $context;
    }
    
    function col_id($values) {
        global $OUTPUT;
        
        $ret = "";
        
        if (has_capability('mod/booking:updatebooking', $this->context)) {
            $ret .= \html_writer::link(new moodle_url('/mod/booking/editoptions.php', array('id' => $this->cm->id, 'optionid' => $values->id)), \html_writer::empty_tag('img', array('class' => 'icon', 'src' => $OUTPUT->pix_url('t/edit'), 'alt' => get_string('updatebooking', 'mod_booking'))));
        }
        
        if (has_capability('mod/booking:updatebooking', $this->context)) {
            $ret .= \html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $this->cm->id, 'optionid' => $values->id, 'action' => 'deletebookingoption', 'sesskey' => sesskey())), \html_writer::empty_tag('img', array('class' => 'icon', 'src' => $OUTPUT->pix_url('t/delete'), 'alt' => get_string('deletebookingoption', 'mod_booking'))));
        }
        
        if ($values->iambooked) {
            $ret .= html_writer::link(new moodle_url('/mod/booking/viewconfirmation.php', array('id' => $this->cm->id, 'optionid' => $values->id)), \html_writer::empty_tag('img', array('class' => 'icon', 'src' => $OUTPUT->pix_url('i/report'), 'alt' =>  get_string('bookedtext', 'mod_booking'))) , array('target' => '_blank'));
        }
        
        return $ret;
    }

    function col_coursestarttime($values) {
        if ($this->is_downloading()) {
            if ($values->coursestarttime == 0) {
                return '';
            } else {                                 
                return userdate($values->coursestarttime, get_string('strftimedatetime'));
            }
        }

        if ($values->coursestarttime == 0) {
            return get_string('datenotset', 'booking');
        } else {
            if (is_null($values->times)) {
                return userdate($values->coursestarttime) . " -<br>" . userdate($values->courseendtime);
            } else {
                $val = '';
                $times = explode(',', $values->times);
                foreach ($times as $time) {
                    $slot = explode('-', $time);
                    $tmpDate = new stdClass();
                    $tmpDate->leftdate = userdate($slot[0], get_string('leftdate', 'booking'));
                    $tmpDate->righttdate = userdate($slot[1], get_string('righttdate', 'booking'));

                    $val .= get_string('leftandrightdate', 'booking', $tmpDate) . '<br>';
                }
                                
                return $val;
            }            
        }
    }

    function col_courseendtime($values) {
        if ($values->courseendtime == 0) {
            return '';
        } else {
            return userdate($values->courseendtime, get_string('strftimedatetime'));
        }
    }

    function col_text($values) {
        $output = '';
        $output .= html_writer::tag('h4',$values->text);

        if (strlen($values->address) > 0) {
            $output .= html_writer::empty_tag('br');
            $output .= $values->address;
        }

        if (strlen($values->location) > 0) {
            $output .= html_writer::empty_tag('br');
            $output .= get_string('location', "mod_booking") . ': ' . $values->location;
        }
        if (strlen($values->institution) > 0) {
            $output .= html_writer::empty_tag('br');
            $output .= get_string('institution', "mod_booking") . ': ' . $values->institution;
        }
        
        if(!empty($values->description)){
            $output .= html_writer::div($values->description, 'description');
        }
        
        
        $output .= (!empty($values->teachers) ? " <br />" . (empty($this->booking->booking->lblteachname) ? get_string('teachers', 'booking') : $this->booking->booking->lblteachname) . "" . $values->teachers : '');
        
        return  $output;
    }

    function  col_description ($values){    
        $output = '';
        if(!empty($values->description)){
            $output .= html_writer::div($values->description, 'courseinfo');
        }
        return $output;
    }
    
    function col_maxanswers($values) {
        global $OUTPUT, $USER;

        $delete = '';
        $status = '';
        $button = '';
        $booked = '';
        $inpast = $values->courseendtime && ($values->courseendtime < time());

        $underlimit = ($values->maxperuser == 0);
        $underlimit = $underlimit || ($values->bookinggetuserbookingcount < $values->maxperuser);

        if (!$values->limitanswers) {
            $status = "available";
            ;
        } else {
            if (($values->waiting + $values->booked) >= ($values->maxanswers + $values->maxoverbooking)) {
                $status = "full";
            }
        }

        if (time() > $values->bookingclosingtime and $values->bookingclosingtime != 0) {
            $status = "closed";
        }

        // I'm booked?
        if ($values->iambooked) {
            if ($values->allowupdate and $status != 'closed') {
                $buttonoptions = array('id' => $this->cm->id, 'action' => 'delbooking', 'optionid' => $values->id, 'sesskey' => $USER->sesskey);
                $url = new moodle_url('view.php', $buttonoptions);
                $delete = $OUTPUT->single_button($url, (empty($values->btncancelname) ? get_string('cancelbooking', 'booking') : $values->btncancelname), 'post');
            } else {
                $button = "";
            }

            if ($values->waitinglist) {
                $booked = get_string('onwaitinglist', 'booking') . '<br>';
            } else {
                if ($inpast) {
                    $booked = get_string('bookedpast', 'booking') . '<br>';
                } else {
                    //$booked = get_string('booked', 'booking');
                }
            }
        } else {
            $buttonoptions = array('answer' => $values->id, 'id' => $this->cm->id, 'sesskey' => $USER->sesskey);

                if (empty($this->booking->booking->bookingpolicy)) {
                    $buttonoptions['confirm'] = 1;
                }

            $url = new moodle_url('view.php', $buttonoptions);
            $url->params(array('answer' => $values->id));
            $button = $OUTPUT->single_button($url, (empty($values->btnbooknowname) ? get_string('booknow', 'booking') : $values->btnbooknowname), 'post');
        }

        if (($values->limitanswers && ($status == "full")) || ($status == "closed") || !$underlimit) {
            $button = '';
        }

        if ($values->cancancelbook == 0 && $values->courseendtime > 0 && $values->courseendtime < time()) {
            $button = '';
            $delete = '';
        }

        // Dont display button Book now if it's disabled
        if ($values->disablebookingusers) {
            $button = '';
        }
        
        if (!empty($this->booking->booking->banusernames)) {
            $disabledusernames = explode(',', $this->booking->booking->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = '';
                }
            }
        }

        // check if user ist logged in
        if (!has_capability('mod/booking:choose', $this->context, $USER->id, false)) { //don't show booking button if the logged in user is the guest user.            
            $button = get_string('havetologin', 'booking') . "<br />";
        }

        if (has_capability('mod/booking:readresponses', $this->context) || $values->isteacher) {
            $numberofresponses = $values->waiting + $values->booked;
            $manage = "<br><a href=\"report.php?id={$this->cm->id}&optionid={$values->id}\">" . get_string("viewallresponses", "booking", $numberofresponses) . "</a>";
        } else {
            $manage = "";
        }

        if (!$values->limitanswers) {
            return $button . $delete . $booked . get_string("unlimited", 'booking') . $manage;
        } else {
            
            $places = new stdClass();
            $places->maxanswers = $values->maxanswers;
            $places->available = $values->maxanswers - $values->booked;
            $places->maxoverbooking = $values->maxoverbooking;
            $places->overbookingavailable = $values->maxoverbooking - $values->waiting;
            
            return $button . $delete . $booked . get_string("placesavailable", "booking", $places) . "<br />" . get_string("waitingplacesavailable", "booking", $places) . $manage;
        }
    }

    /**
     * This function is called for each data row to allow processing of
     * columns which do not have a *_cols function.
     * @return string return processed value. Return NULL if no change has
     *     been made.
     */
    function other_cols($colname, $value) {
        if (substr($colname, 0, 4) === "cust") {
            $tmp = explode('|', $value->{$colname});

            if (!$tmp) {
                return '';
            }

            if (count($tmp) == 2) {
                if ($tmp[0] == 'datetime') {
                    return userdate($tmp[1], get_string('strftimedate'));
                } else {
                    return $tmp[1];
                }
            } else {
                return '';
            }
        }
    }

    function wrap_html_start() {
        
    }

    function wrap_html_finish() {
        echo "<hr>";
    }
    
    /**
     * Count the number of records. This has to be done after query_db was called!!!
     * @return number of records found
     */
    function count_records (){
        global $DB;
        if(!empty($this->countsql)){
            return $DB->count_records_sql($this->countsql, $this->countparams);
        } 
        return 0;
    }

}
