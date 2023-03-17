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

/**
 * This file contains the definition for the renderable classes for bookingoption dates.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_module;
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use moodle_exception;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 * It is used to display a slightly configurable list of booked users for a given booking option.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view implements renderable, templatable {

    /** @var int $cmid */
    private $cmid = null;

    /** @var int $defaultoptionsort */
    private $defaultoptionsort = null;

    /** @var string $renderedactiveoptionstable the rendered active options table */
    private $renderedactiveoptionstable = null;

    /** @var string $renderedalloptionstable the rendered all options table */
    private $renderedalloptionstable = null;

    /** @var string $renderedmyoptionstable the rendered my options table */
    private $renderedmyoptionstable = null;

    /** @var string $renderedoptionsiteachtable the rendered table of options I teach */
    private $renderedoptionsiteachtable = null;

    /** @var string $renderedshowonlyonetable the rendered table of one specific option */
    private $renderedshowonlyonetable = null;

    /** @var string $renderedmyinstitutiontable the rendered table of all options of a specific institution */
    private $renderedmyinstitutiontable = null;

    /** @var string $myinstitutionname */
    private $myinstitutionname = null;

    /** @var string $showall */
    private $showall = null; // We kept this name for backwards compatibility!

    /** @var string $mybooking */
    private $mybooking = null; // We kept this name for backwards compatibility!

    /** @var string $myoptions */
    private $myoptions = null; // We kept this name for backwards compatibility!

    /** @var string $myinstitution */
    private $myinstitution = null; // We kept this name for backwards compatibility!

    /** @var string $showactive */
    private $showactive = null; // We kept this name for backwards compatibility!

    /** @var string $showonlyone */
    private $showonlyone = null; // We kept this name for backwards compatibility!

    /**
     * Constructor
     *
     * @param int $cmid
     * @param string $whichview
     * @param int $optionid
     */
    public function __construct(int $cmid, string $whichview = '', int $optionid = 0) {
        global $USER;

        $this->cmid = $cmid;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        // Default sort order from booking settings.
        $this->defaultoptionsort = $bookingsettings->defaultoptionsort;

        // If we do not have a whichview from URL, we use the default from instance settings.
        if (empty($whichview)) {
            if (!empty($bookingsettings->whichview)) {
                $whichview = $bookingsettings->whichview;
            } else {
                $whichview = 'showall'; // Fallback.
            }
        }
        $showviews = explode(',', $bookingsettings->showviews);

        // These params are used to determine the active tabs in the mustache template.
        switch ($whichview) {
            case 'showactive':
                $this->showactive = true;
                break;
            case 'showonlyone':
                if (!empty($optionid)) {
                    $this->showonlyone = true;
                    $this->renderedshowonlyonetable = $this->get_rendered_showonlyone_table($optionid);
                } else {
                    $this->showall = true;
                }
                break;
            case 'mybooking':
                $this->mybooking = true;
                break;
            case 'myoptions':
                $this->myoptions = true;
                break;
            case 'myinstitution':
                $this->myinstitution = true;
                break;
            case 'showall':
            default:
                $this->showall = true;
                break;
                // TODO: We need to change the default to the view set in instance settings later.
        }

        // Active options.
        if (in_array('showactive', $showviews)) {
            $this->renderedactiveoptionstable = $this->get_rendered_active_options_table();
        }

        // All options.
        if (in_array('showall', $showviews)) {
            $this->renderedalloptionstable = $this->get_rendered_all_options_table();
        }

        // My bookings.
        if (in_array('mybooking', $showviews)) {
            $this->renderedmyoptionstable = $this->get_rendered_my_booked_options_table();
        }

        // Options I teach.
        if (in_array('myoptions', $showviews) && booking_check_if_teacher()) {
            $this->renderedoptionsiteachtable = $this->get_rendered_table_for_teacher($USER->id, false, true, true);
        }

        // Only the booking options of my institution.
        if (in_array('myinstitution', $showviews) && !empty($USER->institution)) {
            $this->myinstitutionname = $USER->institution;
            $this->renderedmyinstitutiontable = $this->get_rendered_myinstitution_table($USER->institution);
        }
    }

    /**
     * Render table for all booking options.
     * @return string the rendered table
     */
    public function get_rendered_all_options_table() {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $allbookingoptionstable = new bookingoptions_wbtable('allbookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $allbookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($allbookingoptionstable, true, true, true);

        $out = $allbookingoptionstable->outhtml($booking->get_pagination_setting(), true);

        return $out;
    }

    /**
     * Render table for active booking options.
     * @return string the rendered table
     */
    public function get_rendered_active_options_table() {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $activebookingoptionstable = new bookingoptions_wbtable('activebookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        $additionalwhere = '((courseendtime > :timenow OR courseendtime = 0) AND status = 0)';

        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [],
                $wherearray, null, STATUSPARAM_BOOKED, $additionalwhere);
        $params['timenow'] = time();
        $activebookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($activebookingoptionstable, false, true, true);

        $out = $activebookingoptionstable->outhtml($booking->get_pagination_setting(), true);

        return $out;
    }

    /**
     * Render table for my own booked options.
     * @return string the rendered table
     */
    public function get_rendered_my_booked_options_table() {
        global $USER;

        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $mybookingoptionstable = new bookingoptions_wbtable('mybookingoptionstable', $booking);

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray, $USER->id);
        $mybookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($mybookingoptionstable, false, true, true);

        $out = $mybookingoptionstable->outhtml($booking->get_pagination_setting(), true);

        return $out;
    }

    /**
     * Render table all options a specified teacher is teaching.
     * @param int $teacherid
     * @param bool $tfilter turn on filter in wunderbyte table
     * @param bool $tsearch turn on search in wunderbyte table
     * @param bool $tsort turn on sorting in wunderbyte table
     * @return string the rendered table
     */
    public function get_rendered_table_for_teacher(int $teacherid,
        bool $tfilter = true, bool $tsearch = true, bool $tsort = true) {
        $cmid = $this->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $teacheroptionstable = new bookingoptions_wbtable('teacheroptionstable', $booking);

        $wherearray = [
            'bookingid' => (int)$booking->id,
            'teacherobjects' => '%"id":' . $teacherid . ',%',
        ];
        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $teacheroptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($teacheroptionstable, $tfilter, $tsearch, $tsort);

        $out = $teacheroptionstable->outhtml($booking->get_pagination_setting(), true);

        return $out;
    }

    /**
     * Render table for one specific booked option.
     * @param int $optionid
     * @return string the rendered table
     */
    public function get_rendered_showonlyone_table(int $optionid) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $showonlyonetable = new bookingoptions_wbtable('showonlyonetable', $booking);

        $wherearray = [
            'bookingid' => (int) $booking->id,
            'id' => $optionid,
        ];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $showonlyonetable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($showonlyonetable, false, false, false);

        $out = $showonlyonetable->outhtml(1, true);

        return $out;
    }

    /**
     * Render table for all options with a specific institution.
     * @param string $institution
     * @return string the rendered table
     */
    public function get_rendered_myinstitution_table(string $institution) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $myinstitutiontable = new bookingoptions_wbtable('myinstitutiontable', $booking);

        $wherearray = [
            'bookingid' => (int) $booking->id,
            'institution' => $institution,
        ];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $myinstitutiontable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($myinstitutiontable, true, true, true);

        $out = $myinstitutiontable->outhtml($booking->get_pagination_setting(), true);

        return $out;
    }

    /**
     * Helper function to set the default layout for the table (list view).
     * @param wunderbyte_table $wbtable reference to the table class that should be initialized
     * @param bool $filter
     * @param bool $search
     * @param bool $sort
     */
    private function wbtable_initialize_list_layout(wunderbyte_table &$wbtable,
        bool $filter = true, bool $search = true, bool $sort = true) {

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($this->cmid);
        $optionsfields = explode(',', $bookingsettings->optionsfields);

        // Set default sort order.
        switch ($this->defaultoptionsort) {
            case 'titleprefix':
                $wbtable->sortable(true, 'titleprefix', SORT_ASC);
                break;
            case 'coursestarttime':
                // Show newest first.
                $wbtable->sortable(true, 'coursestarttime', SORT_DESC);
                break;
            case 'location':
                if (in_array('location', $optionsfields)) {
                    $wbtable->sortable(true, 'location', SORT_ASC);
                } else {
                    $wbtable->sortable(true, 'text', SORT_ASC); // Fallback.
                }
                break;
            case 'institution':
                if (in_array('institution', $optionsfields)) {
                    $wbtable->sortable(true, 'institution', SORT_ASC);
                } else {
                    $wbtable->sortable(true, 'text', SORT_ASC); // Fallback.
                }
                break;
            case 'text':
            default:
                $wbtable->sortable(true, 'text', SORT_ASC);
                break;
        }

        // Activate sorting.
        $wbtable->cardsort = true;

        // Without defining sorting won't work!
        $wbtable->define_columns(['titleprefix', 'coursestarttime']);

        $columnsleftside = [];
        $columnsleftside[] = 'invisibleoption';
        $columnsleftside[] = 'text';
        $columnsleftside[] = 'action';
        if (in_array('teacher', $optionsfields)) {
            $columnsleftside[] = 'teacher';
        }
        if (in_array('statusdescription', $optionsfields)) {
            $columnsleftside[] = 'statusdescription';
        }
        if (in_array('description', $optionsfields)) {
            $columnsleftside[] = 'description';
        }

        $wbtable->add_subcolumns('leftside', $columnsleftside);

        $columnsfooter = [];
        $columnsfooter[] = 'bookings';
        if (in_array('minanswers', $optionsfields)) {
            $columnsfooter[] = 'minanswers';
        }
        if (in_array('dayofweektime', $optionsfields)) {
            $columnsfooter[] = 'dayofweektime';
        }
        if (in_array('location', $optionsfields)) {
            $columnsfooter[] = 'location';
        }
        if (in_array('institution', $optionsfields)) {
            $columnsfooter[] = 'institution';
        }
        if (in_array('showdates', $optionsfields)) {
            $columnsfooter[] = 'showdates';
        }
        $columnsfooter[] = 'comments';

        $wbtable->add_subcolumns('footer', $columnsfooter);
        $wbtable->add_subcolumns('rightside', ['booknow', 'course', 'progressbar', 'ratings']);

        $wbtable->add_classes_to_subcolumns('leftside', ['columnkeyclass' => 'd-none']);
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['columnvalueclass' => 'booking-option-info-invisible'],
            ['invisibleoption']
        );
        $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left m-0 mb-1 h5'], ['text']);
        $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-right'], ['action']);
        if (in_array('teacher', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left font-size-sm'], ['teacher']);
        }
        $wbtable->add_classes_to_subcolumns('footer', ['columnkeyclass' => 'd-none']);
        if (in_array('dayofweektime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
                ['dayofweektime']);
            $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-clock-o fa-fw text-gray
                font-size-sm'], ['dayofweektime']);
        }
        if (in_array('showdates', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left pr-2 text-gray font-size-sm'],
                ['showdates']);
        }
        if (in_array('location', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
                ['location']);
            $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-map-marker fa-fw text-gray
                font-size-sm'], ['location']);
        }
        if (in_array('institution', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
                ['institution']);
            $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-building-o fa-fw text-gray
                font-size-sm'], ['institution']);
        }
        $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['bookings']);
        $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-ticket fa-fw text-gray
            font-size-sm'], ['bookings']);
        if (in_array('minanswers', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer', ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
                ['minanswers']);
            $wbtable->add_classes_to_subcolumns('footer', ['columniclassbefore' => 'fa fa-arrow-up fa-fw text-gray
                font-size-sm'], ['minanswers']);
        }
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'text-right'], ['booknow']);
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'text-left mt-1 text-gray font-size-sm'],
            ['progressbar']);
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'mt-1'],
            ['ratings']);

        // Override naming for columns.
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_text', 'booking')],
            ['text']
        );
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_teacher', 'booking')],
            ['teacher']
        );
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $wbtable->is_downloading('', 'List of booking options'); */

        // Header column.
        $wbtable->define_header_column('text');

        $wbtable->pageable(true);
        $wbtable->stickyheader = true;
        $wbtable->showcountlabel = false;
        $wbtable->showreloadbutton = false;

        // Only admins can download.
        if (has_capability('mod/booking:updatebooking', context_module::instance($this->cmid))) {
            $baseurl = new moodle_url('/mod/booking/download.php');
            $wbtable->define_baseurl($baseurl);
            $wbtable->showdownloadbutton = true;
        }

        $wbtable->define_cache('mod_booking', 'bookingoptionstable');

        if ($search) {
            $fulltextsearchcolumns = [];
            $fulltextsearchcolumns[] = 'titleprefix';
            $fulltextsearchcolumns[] = 'text';
            if (in_array('description', $optionsfields)) {
                $fulltextsearchcolumns[] = 'description';
            }
            if (in_array('location', $optionsfields)) {
                $fulltextsearchcolumns[] = 'location';
            }
            if (in_array('institution', $optionsfields)) {
                $fulltextsearchcolumns[] = 'institution';
            }
            if (in_array('teacher', $optionsfields)) {
                $fulltextsearchcolumns[] = 'teacherobjects';
            }
            $wbtable->define_fulltextsearchcolumns($fulltextsearchcolumns);
        }

        if ($filter) {
            $filtercolumns = [];
            if (in_array('teacher', $optionsfields)) {
                $filtercolumns['teacherobjects'] = [
                    'localizedname' => get_string('teachers', 'mod_booking'),
                    'jsonattribute' => 'name',
                ];
            }
            if (in_array('location', $optionsfields)) {
                $filtercolumns['location'] = [
                    'localizedname' => get_string('location', 'mod_booking'),
                ];
            }
            if (in_array('institution', $optionsfields)) {
                $filtercolumns['institution'] = [
                    'localizedname' => get_string('institution', 'mod_booking'),
                ];
            }
            $wbtable->define_filtercolumns($filtercolumns);
        }

        if ($sort) {
            $sortablecolumns = [];
            $sortablecolumns['coursestarttime'] = get_string('optiondatestart', 'mod_booking');
            $sortablecolumns['titleprefix'] = get_string('titleprefix', 'mod_booking');
            $sortablecolumns['text'] = get_string('bookingoptionnamewithoutprefix', 'mod_booking');
            if (in_array('location', $optionsfields)) {
                $sortablecolumns['location'] = get_string('location', 'mod_booking');
            }
            if (in_array('institution', $optionsfields)) {
                $sortablecolumns['institution'] = get_string('institution', 'mod_booking');
            }
            $wbtable->define_sortablecolumns($sortablecolumns);
        }

        $wbtable->tabletemplate = 'mod_booking/table_list';
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return [
            'alloptionstable' => $this->renderedalloptionstable,
            'activeoptionstable' => $this->renderedactiveoptionstable,
            'myoptionstable' => $this->renderedmyoptionstable,
            'optionsiteachtable' => $this->renderedoptionsiteachtable,
            'showonlyonetable' => $this->renderedshowonlyonetable,
            'myinstitutiontable' => $this->renderedmyinstitutiontable,
            'showonlyone' => $this->showonlyone,
            'showactive' => $this->showactive,
            'showall' => $this->showall,
            'mybooking' => $this->mybooking, // My booked options. We kept the name for backward compatibility.
            'myoptions' => $this->myoptions, // Options I teach. We kept the name for backward compatibility.
            'myinstitution' => $this->myinstitution,
            'myinstitutionname' => $this->myinstitutionname,
        ];
    }
}
