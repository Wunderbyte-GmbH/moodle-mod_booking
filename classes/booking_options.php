<?php

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
            $urlParams = array('searchText' => '', 'searchLocation' => '', 'searchInstitution' => '', 'searchName' => '', 'searchSurname' => ''), $page = 0, $perpage = 0, $fetchOptions = true) {
        parent::__construct($cmid);
        $this->checkcanbookusers = $checkcanbookusers;
        $this->filters = $urlParams;
        $this->page = $page;
        $this->perpage = $perpage;
        if (isset($this->filters['sort']) && $this->filters['sort'] === 1) {
            $this->sort = ' ORDER BY bo.coursestarttime DESC';
        }

        if ($fetchOptions) {
            $this->fill_options();
            $this->get_options_data();
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

        if (!empty($this->filters['searchText'])) {

            $tags = $DB->get_records_sql('SELECT * FROM {booking_tags} WHERE text LIKE :text',
                    array('text' => '%' . $this->filters['searchText'] . '%'));

            if (!empty($tags)) {
                $conditions .= " AND (bo.text LIKE :text ";
                $args['text'] = '%' . $this->filters['searchText'] . '%';

                foreach ($tags as $tag) {
                    $conditions .= " OR bo.text LIKE :tag{$tag->id} ";
                    $args["tag{$tag->id}"] = '%[' . $tag->tag . ']%';
                }

                $conditions .= " ) ";
            } else {
                $conditions .= " AND bo.text LIKE :text ";
                $args['text'] = '%' . $this->filters['searchText'] . '%';
            }
        }

        if (!empty($this->filters['searchLocation'])) {
            $conditions .= " AND bo.location LIKE :location ";
            $args['location'] = '%' . $this->filters['searchLocation'] . '%';
        }

        if (!empty($this->filters['searchInstitution'])) {
            $conditions .= " AND bo.institution LIKE :institution ";
            $args['institution'] = '%' . $this->filters['searchInstitution'] . '%';
        }

        if (!empty($this->filters['coursestarttime'])) {
            $conditions .= ' AND (coursestarttime = 0 OR coursestarttime  > :coursestarttime)';
            $args['coursestarttime'] = $this->filters['coursestarttime'];
        }

        if (!empty($this->filters['searchName'])) {
            $conditions .= " AND (u.firstname LIKE :searchname OR ut.firstname LIKE :searchnamet) ";
            $args['searchname'] = '%' . $this->filters['searchName'] . '%';
            $args['searchnamet'] = '%' . $this->filters['searchName'] . '%';
        }

        if (!empty($this->filters['searchSurname'])) {
            $conditions .= " AND (u.lastname LIKE :searchsurname OR ut.lastname LIKE :searchsurnamet) ";
            $args['searchsurname'] = '%' . $this->filters['searchSurname'] . '%';
            $args['searchsurnamet'] = '%' . $this->filters['searchSurname'] . '%';
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

    private function fill_options() {
        global $DB;

        $options = $this->q_params();
        $this->options = $DB->get_records_sql(
                "SELECT DISTINCT bo.* " . $options['sql'], $options['args'],
                $this->perpage * $this->page, $this->perpage);
        if (!empty($this->options)) {
            list($inoptionssql, $params) = $DB->get_in_or_equal(array_keys($this->options));
            $timessql = 'SELECT bod.id AS dateid, bo.id AS optionid, ' .
                     $DB->sql_concat('bod.coursestarttime', "'-'", 'bod.courseendtime') . ' AS times
                   FROM {booking_optiondates} bod, {booking_options} bo
                   WHERE bo.id = bod.optionid
                   AND bo.id ' . $inoptionssql . '
                   ORDER BY bod.coursestarttime ASC';
            $times = $DB->get_records_sql($timessql, $params);

            if (!empty($times)) {
                foreach ($times as $time) {
                    if (empty($optiontimes[$time->optionid])) {
                        $optiontimes[$time->optionid] = $time->times;
                    } else {
                        $optiontimes[$time->optionid] .= ", " . $time->times;
                    }
                }
                if (!empty($optiontimes)) {
                    foreach ($optiontimes as $key => $time) {
                        $this->options[$key]->times = $time;
                    }
                }
            }
        }
    }

    public function apply_tags() {
        parent::apply_tags();

        $tags = new \booking_tags($this->cm);

        foreach ($this->options as $key => $value) {
            $this->options[$key] = $tags->optionReplace($this->options[$key]);
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
        $mainuserfields = \user_picture::fields('u', NULL);
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
     * Gives a list of booked users sorted in an array by booking option former get_spreadsheet_data
     *
     * @return void
     */
    public function get_options_data() {
        global $DB;

        $context = $this->context;
        // bookinglist $bookinglist[optionid][sortnumber] = userobject;
        $bookinglist = array();
        $optionids = array();
        $totalbookings = array();

        // /TODO from 2.6 on use get_all_user_name_fields() instead of user_picture
        $mainuserfields = \user_picture::fields('u', null);
        $sql = "SELECT ba.id as answerid, $mainuserfields, ba.optionid, ba.bookingid, ba.userid, ba.timemodified, ba.completed, ba.timecreated, ba.waitinglist
        FROM {booking_answers} ba
        JOIN {user} u
        ON ba.userid = u.id
        WHERE u.deleted = 0
        AND ba.bookingid = ?
        ORDER BY ba.optionid, ba.timemodified ASC";
        $rawresponses = $DB->get_records_sql($sql, array($this->id));
        if ($rawresponses) {
            if ($this->checkcanbookusers) {
                if (empty($this->canbookusers)) {
                    $this->get_canbook_userids();
                }
                foreach ($rawresponses as $answerid => $userobject) {
                    $sortedusers[$userobject->id] = $userobject;
                }
                $validresponses = array_intersect_key($sortedusers, $this->canbookusers);
            } else {
                $validresponses = $rawresponses;
            }
            foreach ($validresponses as $response) {
                if (isset($this->options[$response->optionid])) {
                    $bookinglist[$response->optionid][] = $response;
                    $optionids[$response->optionid] = $response->optionid;
                }
            }
            foreach ($optionids as $optionid) {
                $totalbookings[$optionid] = count($bookinglist[$optionid]);
            }
        }
        $this->allbookedusers = $bookinglist;
        $this->sort_bookings();
        $this->numberofbookingsperoption = $totalbookings;
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
