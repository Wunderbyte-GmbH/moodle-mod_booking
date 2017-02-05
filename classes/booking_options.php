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


/**
 * Manage the view of all booking options General methods for all options
 *
 * @param cmid int coursemodule id
 */
class booking_options extends booking {

    /**
     *
     * @var array of users booked and on waitinglist $allbookedusers[optionid][sortnumber]->userobject no user data is stored in the object only id
     *      and booking option related data such as
     */
    public $allbookedusers = array();

    /** @var array key: optionid numberofbookingsperoption */
    public $numberofbookingsperoption;

    /** @var array: config objects of options id as key */
    public $options = array();

    /** @var boolean verify booked users against canbook users yes/no */
    protected $checkcanbookusers = true;

    private $action = "showonlyactive";

    /** @var array of users filters */
    public $filters = array();
    // Pagination
    public $page = 0;

    public $perpage = 0;

    public $sort = ' ORDER BY bo.coursestarttime ASC';

    public function __construct($cmid, $checkcanbookusers = true,
            $urlparams = array('searchtext' => '', 'searchlocation' => '', 'searchinstitution' => '', 'searchname' => '', 'searchsurname' => ''), $page = 0, $perpage = 0) {
        parent::__construct($cmid);
        $this->checkcanbookusers = $checkcanbookusers;
        $this->filters = $urlparams;
        $this->page = $page;
        $this->perpage = $perpage;
        if (isset($this->filters['sort']) && $this->filters['sort'] === 1) {
            $this->sort = ' ORDER BY bo.coursestarttime DESC';
        }
        // Call only when needed TODO
        $this->set_booked_visible_users();
        $this->add_additional_info();
    }

    public function get_url_params() {
        $bu = new \booking_utils();
        $params = $bu->generate_params($this->booking);
        $this->booking->pollurl = $bu->get_body($this->booking, 'pollurl', $params);
        $this->booking->pollurlteachers = $bu->get_body($this->booking, 'pollurlteachers', $params);
    }

    private function q_params() {
        global $USER, $DB;
        $args = array();

        $conditions = " bo.bookingid = :bookingid ";
        $args['bookingid'] = $this->id;

        if (!empty($this->filters['searchtext'])) {

            $tags = $DB->get_records_sql('SELECT * FROM {booking_tags} WHERE text LIKE :text',
                    array('text' => '%' . $this->filters['searchtext'] . '%'));

            if (!empty($tags)) {
                $conditions .= " AND (bo.text LIKE :text ";
                $args['text'] = '%' . $this->filters['searchtext'] . '%';

                foreach ($tags as $tag) {
                    $conditions .= " OR bo.text LIKE :tag{$tag->id} ";
                    $args["tag{$tag->id}"] = '%[' . $tag->tag . ']%';
                }

                $conditions .= " ) ";
            } else {
                $conditions .= " AND bo.text LIKE :text ";
                $args['text'] = '%' . $this->filters['searchtext'] . '%';
            }
        }

        if (!empty($this->filters['searchlocation'])) {
            $conditions .= " AND bo.location LIKE :location ";
            $args['location'] = '%' . $this->filters['searchlocation'] . '%';
        }

        if (!empty($this->filters['searchinstitution'])) {
            $conditions .= " AND bo.institution LIKE :institution ";
            $args['institution'] = '%' . $this->filters['searchinstitution'] . '%';
        }

        if (!empty($this->filters['coursestarttime'])) {
            $conditions .= ' AND (coursestarttime = 0 OR coursestarttime  > :coursestarttime)';
            $args['coursestarttime'] = $this->filters['coursestarttime'];
        }

        if (!empty($this->filters['searchname'])) {
            $conditions .= " AND (u.firstname LIKE :searchname OR ut.firstname LIKE :searchnamet) ";
            $args['searchname'] = '%' . $this->filters['searchname'] . '%';
            $args['searchnamet'] = '%' . $this->filters['searchname'] . '%';
        }

        if (!empty($this->filters['searchsurname'])) {
            $conditions .= " AND (u.lastname LIKE :searchsurname OR ut.lastname LIKE :searchsurnamet) ";
            $args['searchsurname'] = '%' . $this->filters['searchsurname'] . '%';
            $args['searchsurnamet'] = '%' . $this->filters['searchsurname'] . '%';
        }

        if (isset($this->filters['whichview'])) {
            switch ($this->filters['whichview']) {
                case 'mybooking':
                    $conditions .= " AND ba.userid = " . $USER->id . " ";
                    break;

                case 'showall':
                    break;

                case 'showonlyone':
                    $conditions .= " AND bo.id = :optionid ";
                    $args['optionid'] = $this->filters['optionid'];
                    break;

                case 'showactive':
                    $conditions .= " AND (bo.courseendtime > " . time() .
                             " OR bo.courseendtime = 0) ";
                    break;

                default:
                    break;
            }
        }

        $sql = " FROM {booking_options} AS bo LEFT JOIN {booking_teachers} AS bt ON bt.optionid = bo.id LEFT JOIN {user} AS ut ON bt.userid = ut.id LEFT JOIN {booking_answers} AS ba ON bo.id = ba.optionid LEFT JOIN {user} AS u ON ba.userid = u.id WHERE {$conditions} {$this->sort}";

        return array('sql' => $sql, 'args' => $args);
    }

    public function apply_tags() {
        parent::apply_tags();

        $tags = new \booking_tags($this->cm);

        foreach ($this->options as $key => $value) {
            $this->options[$key] = $tags->option_replace($this->options[$key]);
        }
    }

    // Count, how man options...for pagination.
    public function count() {
        global $DB;

        $options = $this->q_params();
        $count = $DB->get_record_sql('SELECT COUNT(DISTINCT bo.id) AS count ' . $options['sql'],
                $options['args']);

        return (int) $count->count;
    }

    // Add additional info to options (status, availspaces, taken, ...)
    private function add_additional_info() {
        global $DB;

        $answers = $DB->get_records('booking_answers', array('bookingid' => $this->id), 'id');
        $allresponses = array();
        $mainuserfields = \user_picture::fields('u', null);
        $allresponses = get_users_by_capability($this->context, 'mod/booking:choose',
                $mainuserfields . ', u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true,
                true);

        foreach ($this->options as $option) {

            $count = $DB->get_record_sql(
                    'SELECT COUNT(*) AS count FROM {booking_answers} WHERE optionid = :optionid',
                    array('optionid' => $option->id));
            $option->count = (int) $count->count;

            if (!$option->coursestarttime == 0) {
                $option->coursestarttimetext = userdate($option->coursestarttime,
                        get_string('strftimedatetime'));
            } else {
                $option->coursestarttimetext = get_string("starttimenotset", 'booking');
            }

            if (!$option->courseendtime == 0) {
                $option->courseendtimetext = userdate($option->courseendtime,
                        get_string('strftimedatetime'), '', false);
            } else {
                $option->courseendtimetext = get_string("endtimenotset", 'booking');
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
                $option->status = "available";
                $option->taken = $taken;
                $option->availspaces = "unlimited";
            } else {
                if ($taken < $option->maxanswers) {
                    $option->status = "available";
                    $option->availspaces = $option->maxanswers - $taken;
                    $option->taken = $taken;
                    $option->availwaitspaces = $option->maxoverbooking;
                } elseif ($taken >= $option->maxanswers && $taken < $totalavailable) {
                    $option->status = "waitspaceavailable";
                    $option->availspaces = 0;
                    $option->taken = $option->maxanswers;
                    $option->availwaitspaces = $option->maxoverbooking -
                             ($taken - $option->maxanswers);
                } elseif ($taken >= $totalavailable) {
                    $option->status = "full";
                    $option->availspaces = 0;
                    $option->taken = $option->maxanswers;
                    $option->availwaitspaces = 0;
                }
            }
            if (time() > $option->bookingclosingtime and $option->bookingclosingtime != 0) {
                $option->status = "closed";
            }
            if ($option->bookingclosingtime) {
                $option->bookingclosingtime = userdate($option->bookingclosingtime,
                        get_string('strftimedate'), '', false);
            } else {
                $option->bookingclosingtime = false;
            }
        }
    }

    /**
     * sorts booking options in booked users and waitinglist users adds the status to userobject
     */
    public function sort_bookings() {
        if (!empty($this->allbookedusers) && !empty($this->options)) {
            foreach ($this->options as $option) {
                if (!empty($this->allbookedusers[$option->id])) {
                    foreach ($this->allbookedusers[$option->id] as $rank => $userobject) {
                        $statusinfo = new stdClass();
                        $statusinfo->bookingcmid = $this->cm->id;
                        if (!$option->limitanswers) {
                            $statusinfo->booked = 'booked';
                            $userobject->status[$option->id] = $statusinfo;
                        } else {
                            if ($userobject->waitinglist) {
                                $statusinfo->booked = 'waitinglist';
                                $userobject->status[$option->id] = $statusinfo;
                            } else {
                                $statusinfo->booked = 'booked';
                                $userobject->status[$option->id] = $statusinfo;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns all bookings of $USER with status
     *
     * @return array of [bookingid][optionid] = userobjects:
     */
    public function get_my_bookings() {
        global $USER;
        $mybookings = array();
        if (!empty($this->allbookedusers) && !empty($this->options)) {
            foreach ($this->options as $optionid => $option) {
                if (!empty($this->allbookedusers[$option->id])) {
                    foreach ($this->allbookedusers[$option->id] as $userobject) {
                        if ($userobject->id == $USER->id) {
                            $userobject->status[$option->id]->coursename = $this->course->fullname;
                            $userobject->status[$option->id]->courseid = $this->course->id;
                            $userobject->status[$option->id]->bookingtitle = $this->booking->name;
                            $userobject->status[$option->id]->bookingoptiontitle = $this->options[$option->id]->text;
                            $mybookings[$optionid] = $userobject;
                        }
                    }
                }
            }
        }
        return $mybookings;
    }

    public static function booking_set_visiblefalse(&$item1, $key) {
        $item1->bookingvisible = false;
    }

    /**
     * sets $user->bookingvisible to true or false dependant on group member status and access all group capability
     */
    public function set_booked_visible_users() {
        if (!empty($this->allbookedusers)) {
            if ($this->course->groupmode == 0 ||
                     has_capability('moodle/site:accessallgroups', $this->context)) {
                foreach ($this->allbookedusers as $optionid => $optionusers) {
                    if (isset($user->status[$optionid])) {
                        foreach ($optionusers as $user) {
                            $user->status[$optionid]->bookingvisible = true;
                        }
                    }
                }
            } else if (!empty($this->groupmembers)) {
                foreach ($this->allbookedusers as $optionid => $bookedusers) {
                    foreach ($bookedusers as $user) {
                        if (in_array($user->id, array_keys($this->groupmembers))) {
                            $user->status[$optionid]->bookingvisible = true;
                        } else {
                            $user->status[$optionid]->bookingvisible = false;
                        }
                    }
                }
            } else {
                // empty -> all invisible
                foreach ($this->allbookedusers as $optionid => $optionusers) {
                    foreach ($optionusers as $user) {
                        $user->status[$optionid]->bookingvisible = false;
                    }
                }
            }
        }
    }
}
