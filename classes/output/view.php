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

use coding_exception;
use context_module;
use context_system;
use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\elective;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use moodle_exception;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 *
 * It is used to display a slightly configurable list of booked users for a given booking option.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view implements renderable, templatable {

    /** @var int $cmid course module id */
    private $cmid = null;

    /** @var int $bookingid id of the booking instance */
    private $bookingid = null;

    /** @var int $defaultoptionsort */
    private $defaultoptionsort = null;

    /** @var int $defaultsortorder */
    private $defaultsortorder = null;

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

    /** @var string $renderedvisibleoptionstable the rendered table of all options which are visible */
    private $renderedvisibleoptionstable = null;

    /** @var string $renderedinvisibleoptionstable the rendered table of all options which are invisible */
    private $renderedinvisibleoptionstable = null;

    /** @var string $renderedfieldofstudyoptionstable the rendered table of all options from my field of study */
    private $renderedfieldofstudyoptionstable = null;

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

    /** @var string $showvisible */
    private $showvisible = null;

    /** @var string $showinvisible */
    private $showinvisible = null;

    /** @var string $showinvisible */
    private $showfieldofstudy = null;

    /** @var string $elective */
    private $renderelectivetable = null;

    /** @var array $elective */
    private $electivemodal = null;

    /** @var bool $showheaderimage */
    private $showheaderimage = null;

    /** @var string $headerimageposition */
    private $headerimageposition = null;

    /**
     * Constructor
     *
     * @param int $cmid
     * @param string $whichview
     * @param int $optionid
     * @param bool $onlywhichview
     */
    public function __construct(int $cmid, string $whichview = '', int $optionid = 0, bool $onlywhichview = false) {
        global $USER, $PAGE;

        $this->cmid = $cmid;

        $context = context_system::instance();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $this->bookingid = $bookingsettings->id;

        // Default sort column and sort order from booking settings.
        $this->defaultoptionsort = $bookingsettings->defaultoptionsort;
        $this->defaultsortorder = $bookingsettings->defaultsortorder;

        // If we do not have a whichview from URL, we use the default from instance settings.
        if (empty($whichview)) {
            if (!empty($bookingsettings->whichview)) {
                $whichview = $bookingsettings->whichview;
                $showviews = explode(',', $bookingsettings->showviews);
            } else {
                $whichview = 'showall'; // Fallback.
            }
        }

        if ($onlywhichview) {
            $showviews = [$whichview];
        } else {
            $showviews = explode(',', $bookingsettings->showviews);
        }

        // These params are used to determine the active tabs in the mustache template.
        switch ($whichview) {
            case 'showactive':
                $this->showactive = true;
                break;
            case 'showonlyone':
                if (!empty($optionid)) {
                    $this->showonlyone = true;
                    $this->renderedshowonlyonetable = $this->get_rendered_showonlyone_table($optionid);
                    return;
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
            case 'showvisible':
                // Tab will only be shown to users with the 'canseeinvisibleoptions' capability.
                // For participants we use the "showall" table as they will only see visible options anyway.
                if (has_capability('mod/booking:canseeinvisibleoptions', $context)) {
                    $this->showvisible = true;
                }
                break;
            case 'showinvisible':
                // Tab will only be shown to users with the 'canseeinvisibleoptions' capability.
                if (has_capability('mod/booking:canseeinvisibleoptions', $context)) {
                    $this->showinvisible = true;
                }
                break;
            case 'showfieldofstudy':
                $this->showfieldofstudy = true;
                break;
            case 'shownothing':
                // Don't do anything.
                $showviews = [];
                break;
            case 'showall':
            default:
                $this->showall = true;
                break;
        }

        if (!empty($bookingsettings->iselective)) {
            list($tablestring, $rawdata) = $this->get_rendered_elective_table();

            $this->renderelectivetable = $tablestring;
            $modal = new elective_modal($bookingsettings, $rawdata);
            $this->electivemodal = $modal->return_as_array();

            // Get booking settings.
            $booking = singleton_service::get_instance_of_booking_settings_by_cmid($bookingsettings->cmid);

            $this->electivemodal['maxcredits'] = $booking->maxcredits;
            $this->electivemodal['creditsleft'] = elective::return_credits_left($booking);
            $this->electivemodal['isteacherorderforced'] = empty($booking->enforceteacherorder) ? false : true;

            $PAGE->requires->js_call_amd('mod_booking/elective-sorting', 'electiveSorting');

            return;
        }

        // All options.
        if (in_array('showall', $showviews)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'showall';
            $this->renderedalloptionstable = $this->get_rendered_all_options_table($lazy);
        }

        // Active options.
        if (in_array('showactive', $showviews)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'showactive';
            $this->renderedactiveoptionstable = $this->get_rendered_active_options_table($lazy);
        }

        // My bookings.
        if (in_array('mybooking', $showviews)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'mybooking';
            $this->renderedmyoptionstable = $this->get_rendered_my_booked_options_table($lazy);
        }

        // Options I teach.
        if (in_array('myoptions', $showviews) && booking_check_if_teacher()) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'myoptions';
            $this->renderedoptionsiteachtable = $this->get_rendered_table_for_teacher($USER->id, false, true, true, $lazy);
        }

        // Only the booking options of my institution.
        if (in_array('myinstitution', $showviews) && !empty($USER->institution)) {
            $this->myinstitutionname = $USER->institution;
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'myinstitution';
            $this->renderedmyinstitutiontable = $this->get_rendered_myinstitution_table($USER->institution, $lazy);
        }

        // Only show visible options.
        if (in_array('showvisible', $showviews) && has_capability('mod/booking:canseeinvisibleoptions', $context)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'showvisible';
            $this->renderedvisibleoptionstable = $this->get_rendered_visible_options_table($lazy);
        }

        // Only show invisible options.
        if (in_array('showinvisible', $showviews) && has_capability('mod/booking:canseeinvisibleoptions', $context)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'showinvisible';
            $this->renderedinvisibleoptionstable = $this->get_rendered_invisible_options_table($lazy);
        }

        // Field of study options.
        if (in_array('showfieldofstudy', $showviews)) {
            // If we show this table first, we don't load it lazy.
            $lazy = $whichview !== 'showfieldofstudy';
            $this->renderedfieldofstudyoptionstable
                = format_text('[fieldofstudyoptions sortby="coursestarttime" sortorder="asc"]');
        }
    }

    /**
     * Render table for elective.
     * @return array the rendered table
     */
    public function get_rendered_elective_table(): array {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $allbookingoptionstable = new bookingoptions_wbtable("cmid_{$cmid} electivetable");

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $allbookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($allbookingoptionstable, true, true, true);

        $out = $allbookingoptionstable->outhtml($booking->get_pagination_setting(), true);

        return [$out, $allbookingoptionstable->rawdata];
    }

    /**
     * Render table for all booking options.
     * @param bool $lazy
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_rendered_all_options_table($lazy = false): string {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $allbookingoptionstable = new bookingoptions_wbtable("cmid_{$cmid} allbookingoptionstable");

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $allbookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($allbookingoptionstable, true, true, true);

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $allbookingoptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $allbookingoptionstable->outhtml($booking->get_pagination_setting(), true);
        }

        return $out;
    }

    /**
     * Render table for active booking options.
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_active_options_table($lazy = false) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $activebookingoptionstable = new bookingoptions_wbtable("cmid_{$cmid} activebookingoptionstable");

        $wherearray = ['bookingid' => (int)$booking->id];
        $additionalwhere = '((courseendtime > :timenow OR courseendtime = 0) AND status = 0)';

        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [],
                $wherearray, null, [MOD_BOOKING_STATUSPARAM_BOOKED], $additionalwhere);

        // Timenow is today at at 00.00.
        // The test is on courseendtime, if it has finished not already yesterday.
        $params['timenow'] = strtotime('today 00:00');
        $activebookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($activebookingoptionstable, true, true, true);

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $activebookingoptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $activebookingoptionstable->outhtml($booking->get_pagination_setting(), true);
        }
        return $out;
    }

    /**
     * Render table for my own booked options.
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_my_booked_options_table($lazy = false) {
        global $USER;

        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $mybookingoptionstable = new bookingoptions_wbtable("cmid_{$cmid}_userid_{$USER->id} mybookingoptionstable");

        $wherearray = ['bookingid' => (int)$booking->id];
        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray, $USER->id);
        $mybookingoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($mybookingoptionstable, true, true, true);

        // For mybookingstable we need to apply a different cache, because it changes with every booking of a user.
        $mybookingoptionstable->define_cache('mod_booking', 'mybookingoptionstable');

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $mybookingoptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $mybookingoptionstable->outhtml($booking->get_pagination_setting(), true);
        }

        return $out;
    }

    /**
     * Render table all options a specified teacher is teaching.
     * @param int $teacherid
     * @param bool $tfilter turn on filter in wunderbyte table
     * @param bool $tsearch turn on search in wunderbyte table
     * @param bool $tsort turn on sorting in wunderbyte table
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_table_for_teacher(int $teacherid,
        bool $tfilter = true, bool $tsearch = true, bool $tsort = true, $lazy = false) {
        $cmid = $this->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $teacheroptionstable = new bookingoptions_wbtable("cmid_{$cmid}_teacherid_{$teacherid} teacheroptionstable");

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

        $teacheroptionstable->showreloadbutton = false; // No reload button on teacher pages.
        $teacheroptionstable->requirelogin = false; // Teacher pages need to be accessible without login.

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $teacheroptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $teacheroptionstable->outhtml($booking->get_pagination_setting(), true);
        }

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
        $showonlyonetable = new bookingoptions_wbtable("cmid_{$cmid}_optionid_{$optionid} showonlyonetable");

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
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_myinstitution_table(string $institution, $lazy = false) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $myinstitutiontable = new bookingoptions_wbtable("cmid_{$cmid} myinstitutiontable");

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

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $myinstitutiontable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $myinstitutiontable->outhtml($booking->get_pagination_setting(), true);
        }

        return $out;
    }

    /**
     * Render table for all options which are visible.
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_visible_options_table($lazy = false) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $visibleoptionstable = new bookingoptions_wbtable("cmid_{$cmid} visibleoptionstable");

        $wherearray = [
            'bookingid' => (int) $booking->id,
            'invisible' => 0,
        ];
        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $visibleoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($visibleoptionstable, true, true, true);

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $visibleoptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $visibleoptionstable->outhtml($booking->get_pagination_setting(), true);
        }

        return $out;
    }

    /**
     * Render table for all options which are invisible.
     * @param bool $lazy for lazy-loading
     * @return string the rendered table
     */
    public function get_rendered_invisible_options_table($lazy = false) {
        $cmid = $this->cmid;

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Create the table.
        $invisibleoptionstable = new bookingoptions_wbtable("cmid_{$cmid} invisibleoptionstable");

        $wherearray = [
            'bookingid' => (int) $booking->id,
            'invisible' => 1,
        ];
        list($fields, $from, $where, $params, $filter) =
            booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $invisibleoptionstable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $this->wbtable_initialize_list_layout($invisibleoptionstable, true, true, true);

        if ($lazy) {
            list($idstring, $encodedtable, $out)
                = $invisibleoptionstable->lazyouthtml($booking->get_pagination_setting(), true);
        } else {
            $out = $invisibleoptionstable->outhtml($booking->get_pagination_setting(), true);
        }

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

        $sortorder = $bookingsettings->defaultsortorder === "desc" ? SORT_DESC : SORT_ASC;

        // Set default sort order.
        switch ($this->defaultoptionsort) {
            case 'titleprefix':
                $wbtable->sortable(true, 'titleprefix', $sortorder);
                break;
            case 'coursestarttime':
                // Show newest first.
                $wbtable->sortable(true, 'coursestarttime', $sortorder);
                break;
            case 'location':
                if (in_array('location', $optionsfields)) {
                    $wbtable->sortable(true, 'location', $sortorder);
                } else {
                    $wbtable->sortable(true, 'text', $sortorder); // Fallback.
                }
                break;
            case 'institution':
                if (in_array('institution', $optionsfields)) {
                    $wbtable->sortable(true, 'institution', $sortorder);
                } else {
                    $wbtable->sortable(true, 'text', $sortorder); // Fallback.
                }
                break;
            case 'text':
            default:
                $wbtable->sortable(true, 'text', $sortorder);
                break;
        }

        // Only admins can download.
        if (has_capability('mod/booking:updatebooking', context_module::instance($this->cmid))) {
            $baseurl = new moodle_url('/mod/booking/download.php', ['cmid' => $this->cmid]);
            $wbtable->define_baseurl($baseurl);
            $wbtable->showdownloadbutton = true;
        }

        // Get view param from JSON of booking instance settings.
        $viewparam = (int)booking::get_value_of_json_by_key($bookingsettings->id, 'viewparam');
        if (empty($viewparam)) {
            $viewparam = MOD_BOOKING_VIEW_PARAM_LIST; // List view is the default view.
        }

        self::apply_standard_params_for_bookingtable($wbtable, $optionsfields, $filter, $search, $sort, true, true, $viewparam);
    }


    /**
     * This standard functions sets important params for booking options.
     * It should be kept generic to be usable on the view as well as in shortcodes etc.
     * @param wunderbyte_table $wbtable
     * @param array $optionsfields
     * @param bool $filter
     * @param bool $search
     * @param bool $sort
     * @param bool $reload
     * @param bool $filterinactive
     * @param int $viewparam list view or card view
     * @return void
     * @throws moodle_exception
     * @throws coding_exception
     */
    public static function apply_standard_params_for_bookingtable(
        wunderbyte_table &$wbtable,
        $optionsfields = [],
        bool $filter = true,
        bool $search = true,
        bool $sort = true,
        bool $reload = true,
        bool $filterinactive = true,
        int $viewparam = MOD_BOOKING_VIEW_PARAM_LIST) {
        // Activate sorting.
        $wbtable->cardsort = true;

        // Without defining sorting won't work!
        $wbtable->define_columns(['titleprefix', 'coursestarttime', 'courseendtime']);

        // Switch view type (cards view or list view).
        switch ($viewparam) {
            case MOD_BOOKING_VIEW_PARAM_CARDS:
                self::generate_table_for_cards($wbtable, $optionsfields);
                break;
            case MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT:
                $wbtable->set_template_data('showheaderimage', true);
                $wbtable->set_template_data('headerimageleft', true);
                self::generate_table_for_list($wbtable, $optionsfields);
                break;
            case MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT:
                $wbtable->set_template_data('showheaderimage', true);
                $wbtable->set_template_data('headerimageright', true);
                self::generate_table_for_list($wbtable, $optionsfields);
                break;
            case MOD_BOOKING_VIEW_PARAM_LIST:
            default:
                self::generate_table_for_list($wbtable, $optionsfields);
                break;
        }

        // Header column.
        $wbtable->define_header_column('text');

        $wbtable->pageable(true);
        $wbtable->stickyheader = true;
        $wbtable->showcountlabel = true;
        $wbtable->showreloadbutton = $reload;

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
            if (in_array('teacher', $optionsfields)) {

                $standardfilter = new standardfilter('teacherobjects', get_string('teachers', 'mod_booking'));
                $standardfilter->add_options(['jsonattribute' => 'name']);
                $wbtable->add_filter($standardfilter);
            }
            if (in_array('location', $optionsfields)) {

                $standardfilter = new standardfilter('location', get_string('location', 'mod_booking'));
                $wbtable->add_filter($standardfilter);
            }
            if (in_array('institution', $optionsfields)) {

                $standardfilter = new standardfilter('institution', get_string('institution', 'mod_booking'));
                $wbtable->add_filter($standardfilter);
            }

            $datepicker = new datepicker(
                'coursestarttime',
                get_string('timefilter:coursetime', 'mod_booking'),
                'courseendtime'
            );
            $datepicker->add_options(
                'in between',
                '<',
                get_string('apply_filter', 'local_wunderbyte_table'),
                'now',
                'now + 1 year'
            );
            $wbtable->add_filter($datepicker);

            $datepicker = new datepicker(
                'bookingopeningtime',
                get_string('timefilter:bookingtime', 'mod_booking'),
                'bookingclosingtime'
            );
            $datepicker->add_options(
                'in between',
                '<',
                get_string('apply_filter', 'local_wunderbyte_table'),
                'now',
                'now + 1 year'
            );

            $wbtable->add_filter($datepicker);
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
            if (in_array('bookingopeningtime', $optionsfields)) {
                $sortablecolumns['bookingopeningtime'] = get_string('bookingopeningtime', 'mod_booking');
            }
            if (in_array('bookingclosingtime', $optionsfields)) {
                $sortablecolumns['bookingclosingtime'] = get_string('bookingclosingtime', 'mod_booking');
            }
            $wbtable->define_sortablecolumns($sortablecolumns);
        }

        // Let's collapse filters per default.
        $wbtable->filteronloadinactive = $filterinactive;
    }

    /**
     * Helper function to generate cards table.
     * @param wunderbyte_table $wbtable reference to table instance
     * @param array $optionsfields
     * @return void
     */
    public static function generate_table_for_cards(wunderbyte_table &$wbtable, array $optionsfields) {

        // We define it here so we can pass it with the mustache template.
        $wbtable->add_subcolumns('optionid', ['id']);
        $wbtable->add_subcolumns('cardimage', ['image']);
        $wbtable->add_classes_to_subcolumns('cardimage', ['columnvalueclass' => 'w-100'], ['image']);
        $wbtable->add_subcolumns('optioninvisible', ['invisibleoption']);

        // 1. Card body.
        $cardbody = ['coursestarttime', 'courseendtime'];
        $cardbody[] = 'action';
        $cardbody[] = 'invisibleoption';
        $cardbody[] = 'text';
        if (in_array('teacher', $optionsfields)) {
            $cardbody[] = 'teacher';
        }
        if (in_array('description', $optionsfields)) {
            $cardbody[] = 'description';
        }
        if (in_array('statusdescription', $optionsfields)) {
            $cardbody[] = 'statusdescription';
        }
        if (in_array('attachment', $optionsfields)) {
            $cardbody[] = 'attachment';
        }

        $wbtable->add_subcolumns('cardbody', $cardbody);
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnkeyclass' => 'd-none']);
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnvalueclass' => 'd-none'], ['coursestarttime', 'courseendtime']);
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnvalueclass' => 'float-right'], ['action']);
        $wbtable->add_classes_to_subcolumns(
            'cardbody',
            ['columnvalueclass' => 'text-center booking-option-info-invisible'],
            ['invisibleoption']
        );
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnvalueclass' => 'h5'], ['text']);
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnvalueclass' => 'd-block pt-1'], ['description']);
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnvalueclass' => 'd-block pt-1'], ['statusdescription']);
        if (in_array('attachment', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardbody',
                ['columnvalueclass' => 'd-block pt-1'], ['attachment']);
        }
        $wbtable->add_classes_to_subcolumns('cardbody', ['columnalt' => get_string('teacher', 'mod_booking')], ['teacher']);

        // 2. Cardlist.
        $cardlist = [];
        $cardlist[] = 'bookings';
        if (in_array('minanswers', $optionsfields)) {
            $cardlist[] = 'minanswers';
        }
        if (in_array('dayofweektime', $optionsfields)) {
            $cardlist[] = 'dayofweektime';
        }
        if (in_array('location', $optionsfields)) {
            $cardlist[] = 'location';
        }
        if (in_array('institution', $optionsfields)) {
            $cardlist[] = 'institution';
        }
        if (in_array('responsiblecontact', $optionsfields)) {
            $cardlist[] = 'responsiblecontact';
        }
        if (in_array('bookingopeningtime', $optionsfields)) {
            $cardlist[] = 'bookingopeningtime';
        }
        if (in_array('bookingclosingtime', $optionsfields)) {
            $cardlist[] = 'bookingclosingtime';
        }
        if (in_array('showdates', $optionsfields)) {
            $cardlist[] = 'showdates';
        }
        $cardlist[] = 'comments';

        $wbtable->add_subcolumns('cardlist', $cardlist);
        $wbtable->add_classes_to_subcolumns('cardlist', ['columnkeyclass' => 'd-none']);

        if (in_array('dayofweektime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left text-gray pr-2'],
                ['dayofweektime']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-clock-o fa-fw text-gray'],
                ['dayofweektime']);
        }
        if (in_array('responsiblecontact', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left pr-2 text-gray'],
                ['responsiblecontact']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-user fa-fw text-gray'],
                ['responsiblecontact']);
        }
        if (in_array('bookingopeningtime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left pr-2 text-gray d-block'],
                ['bookingopeningtime']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-forward fa-fw text-gray'],
                ['bookingopeningtime']);
        }
        if (in_array('bookingclosingtime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left pr-2 text-gray d-block'],
                ['bookingclosingtime']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-step-forward fa-fw text-gray'],
                ['bookingclosingtime']);
        }
        if (in_array('showdates', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left pr-2 text-gray'],
                ['showdates']);
        }
        if (in_array('location', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left text-gray  pr-2'],
                ['location']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-map-marker fa-fw text-gray'],
                ['location']);
        }
        if (in_array('institution', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left text-gray  pr-2'],
                ['institution']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-building-o fa-fw text-gray'],
                ['institution']);
        }
        $wbtable->add_classes_to_subcolumns('cardlist',
            ['columnclass' => 'text-left text-gray pr-2'],
            ['bookings']);
        $wbtable->add_classes_to_subcolumns('cardlist',
            ['columniclassbefore' => 'fa fa-ticket fa-fw text-gray'],
            ['bookings']);
        if (in_array('minanswers', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columnclass' => 'text-left text-gray pr-2'],
                ['minanswers']);
            $wbtable->add_classes_to_subcolumns('cardlist',
                ['columniclassbefore' => 'fa fa-arrow-up fa-fw text-gray'],
                ['minanswers']);
        }

        // 3. Cardfooter.
        $wbtable->add_subcolumns('cardfooter', ['booknow', 'course', 'progressbar', 'ratings']);
        $wbtable->add_classes_to_subcolumns('cardfooter', ['columnkeyclass' => 'd-none']);
        $wbtable->add_classes_to_subcolumns('cardfooter', ['columnclass' => 'text-right'], ['booknow']);
        $wbtable->add_classes_to_subcolumns('cardfooter',
            ['columnclass' => 'text-left mt-1 text-gray'],
            ['progressbar']);
        $wbtable->add_classes_to_subcolumns('cardfooter', ['columnclass' => 'mt-1'], ['ratings']);
        $wbtable->add_classes_to_subcolumns('cardfooter', ['columnclass' => 'theme-text-color bold '], ['price']);

        // Override naming for columns.
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheadertext', 'booking')],
            ['text']
        );
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheaderteacher', 'booking')],
            ['teacher']
        );

        // Additional descriptions.
        $wbtable->add_classes_to_subcolumns('cardlist', ['columnalt' => get_string('location', 'mod_booking')],
            ['location']);
        $wbtable->add_classes_to_subcolumns('cardlist', ['columnalt' => get_string('dayofweektime', 'mod_booking')],
            ['dayofweektime']);
        $wbtable->add_classes_to_subcolumns('cardlist', ['columnalt' => get_string('bookings', 'mod_booking')],
            ['bookings']);
        $wbtable->add_classes_to_subcolumns('cardimage', ['cardimagealt' => get_string('bookingoptionimage', 'mod_booking')],
            ['image']);

        // At last, we set the correct template!
        $wbtable->tabletemplate = 'mod_booking/table_cards';

    }

    /**
     * Helper function to generate list table.
     * @param wunderbyte_table $wbtable reference to table instance
     * @param array $optionsfields
     * @return void
     */
    public static function generate_table_for_list(wunderbyte_table &$wbtable, array $optionsfields) {
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
        if (in_array('attachment', $optionsfields)) {
            $columnsleftside[] = 'attachment';
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
        if (in_array('responsiblecontact', $optionsfields)) {
            $columnsfooter[] = 'responsiblecontact';
        }
        if (in_array('bookingopeningtime', $optionsfields)) {
            $columnsfooter[] = 'bookingopeningtime';
        }
        if (in_array('bookingclosingtime', $optionsfields)) {
            $columnsfooter[] = 'bookingclosingtime';
        }
        if (in_array('showdates', $optionsfields)) {
            $columnsfooter[] = 'showdates';
        }
        $columnsfooter[] = 'comments';

        $wbtable->add_subcolumns('footer', $columnsfooter);
        $wbtable->add_subcolumns('rightside', ['booknow', 'course', 'progressbar', 'ratings']);

        // Add header image.
        $wbtable->add_subcolumns('headerimage', ['image']);
        $wbtable->add_classes_to_subcolumns('headerimage', ['columnvalueclass' => 'w-100'], ['image']);
        $wbtable->add_classes_to_subcolumns('headerimage', ['headerimagealt' => get_string('bookingoptionimage', 'mod_booking')],
            ['image']);

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
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
                ['dayofweektime']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-clock-o fa-fw text-gray font-size-sm'],
                ['dayofweektime']);
        }
        if (in_array('responsiblecontact', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left pr-2 text-gray font-size-sm'],
                ['responsiblecontact']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-user fa-fw text-gray font-size-sm'],
                ['responsiblecontact']);
        }
        if (in_array('bookingopeningtime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left pr-2 text-gray font-size-sm d-block'],
                ['bookingopeningtime']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-forward fa-fw text-gray font-size-sm'],
                ['bookingopeningtime']);
        }
        if (in_array('bookingclosingtime', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left pr-2 text-gray font-size-sm d-block'],
                ['bookingclosingtime']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-step-forward fa-fw text-gray font-size-sm'],
                ['bookingclosingtime']);
        }
        if (in_array('showdates', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left pr-2 text-gray font-size-sm'],
                ['showdates']);
        }
        if (in_array('location', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
                ['location']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-map-marker fa-fw text-gray font-size-sm'],
                ['location']);
        }
        if (in_array('institution', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left text-gray  pr-2 font-size-sm'],
                ['institution']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-building-o fa-fw text-gray font-size-sm'],
                ['institution']);
        }
        $wbtable->add_classes_to_subcolumns('footer',
            ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
            ['bookings']);
        $wbtable->add_classes_to_subcolumns('footer',
            ['columniclassbefore' => 'fa fa-ticket fa-fw text-gray font-size-sm'],
            ['bookings']);
        if (in_array('minanswers', $optionsfields)) {
            $wbtable->add_classes_to_subcolumns('footer',
                ['columnclass' => 'text-left text-gray pr-2 font-size-sm'],
                ['minanswers']);
            $wbtable->add_classes_to_subcolumns('footer',
                ['columniclassbefore' => 'fa fa-arrow-up fa-fw text-gray font-size-sm'],
                ['minanswers']);
        }
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'text-right'], ['booknow']);
        $wbtable->add_classes_to_subcolumns('rightside',
            ['columnclass' => 'text-left mt-1 text-gray font-size-sm'],
            ['progressbar']);
        $wbtable->add_classes_to_subcolumns('rightside', ['columnclass' => 'mt-1'], ['ratings']);

        // Override naming for columns.
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheadertext', 'booking')],
            ['text']
        );
        $wbtable->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheaderteacher', 'booking')],
            ['teacher']
        );

        // At last, we set the correct template!
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
            'visibleoptionstable' => $this->renderedvisibleoptionstable,
            'invisibleoptionstable' => $this->renderedinvisibleoptionstable,
            'fieldofstudytable' => $this->renderedfieldofstudyoptionstable,
            'electivetable' => $this->renderelectivetable,
            'showonlyone' => $this->showonlyone,
            'showactive' => $this->showactive,
            'showall' => $this->showall,
            'mybooking' => $this->mybooking, // My booked options. We kept the name for backward compatibility.
            'myoptions' => $this->myoptions, // Options I teach. We kept the name for backward compatibility.
            'myinstitution' => $this->myinstitution,
            'myinstitutionname' => $this->myinstitutionname,
            'showvisible' => $this->showvisible,
            'showinvisible' => $this->showinvisible,
            'showfieldofstudy' => $this->showfieldofstudy,
            'elective' => empty($this->renderelectivetable) ? false : $this->electivemodal,
            'showheaderimage' => $this->showheaderimage,
            'headerimageposition' => $this->headerimageposition,
        ];
    }
}
