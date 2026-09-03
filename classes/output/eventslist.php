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
 * This file contains the definition for the renderable classes for column 'action'.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking;
use mod_booking\table\event_log_table;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'action'.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eventslist implements renderable, templatable {
    /**
     * The icon for events table.
     *
     * @var string
     */
    public $icon = '';

    /**
     * The title for events table.
     *
     * @var string
     */
    public $title = '';
    /**
     * Eventlist stores the events fetched from db.
     *
     * @var array
     */
    public $eventlist = [];

    /**
     * The rendered html events table.
     *
     * @var string
     */
    public $eventstable = '';

    /**
     * Hint informing the user that the list is limited to a time span.
     *
     * @var string
     */
    public $hint = '';

    /**
     * If there are no eventnames specified, we will get all of them for this plugin.
     *
     * @param int $id
     * @param array $eventnames
     * @param string $countlabel optional lang string identifier (in mod_booking) for the records count label
     * @param array $columns optional column definition [columnkey => header]; defaults to the generic user column
     * @param int $timecreatedfrom only show log entries created at or after this timestamp, 0 to show all
     * @param string $extrawhere optional additional where condition (starting with " AND "), e.g. to scope the events
     * @param array $extraparams params for $extrawhere
     */
    public function __construct(
        int $id = 0,
        array $eventnames = [],
        string $countlabel = '',
        array $columns = [],
        int $timecreatedfrom = 0,
        string $extrawhere = '',
        array $extraparams = []
    ) {

        global $DB;

        [$select, $from, $where, $filter, $params] =
            booking::return_sql_for_event_logs('mod_booking', $eventnames, $id, $timecreatedfrom);

        if (!empty($extrawhere)) {
            $where .= $extrawhere;
            $params = array_merge($params, $extraparams);
        }

        $tablenamestring = "eventlogtable" . $id . implode('-', $eventnames) . $timecreatedfrom
            . (!empty($extrawhere) ? md5($extrawhere . json_encode($extraparams)) : '');

        $tablename = md5($tablenamestring);

        $table = new event_log_table($tablename);

        $table->set_filter_sql($select, $from, $where, $filter, $params);

        // Allow callers to override the displayed columns (e.g. the messages report shows the
        // recipient instead of the generic "user" column). Falls back to the default set.
        $columnsarray = !empty($columns) ? $columns : [
            'userid' => get_string('user', 'core'),
            'eventname' => get_string('eventname', 'core'),
            'description' => get_string('description', 'core'),
            'timecreated' => get_string('timecreated', 'core'),
        ];
        $table->define_columns(array_keys($columnsarray));
        $table->define_headers(array_values($columnsarray));

        $table->define_sortablecolumns(array_keys($columnsarray));
        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;

        $table->tabletemplate = 'local_wunderbyte_table/twtable_list';
        $table->define_cache('mod_booking', 'eventlogtable');

        $table->pageable(true);

        $table->showcountlabel = true;

        if (!empty($countlabel)) {
            $table->define_countlabel($countlabel, 'mod_booking');
        }

        $table->showrowcountselect = true;

        $table->showreloadbutton = true;

        $table->filteronloadinactive = true;

        $table->define_baseurl(new moodle_url('/mod/booking/downloads/download.php'));

        [$idstring, $tablecachehash, $html] = $table->lazyouthtml(10, true);
        $this->eventstable = $html;

        if (!empty($timecreatedfrom)) {
            $months = self::get_configured_months();
            if (!empty($months)) {
                $url = new moodle_url(
                    '/admin/settings.php',
                    ['section' => 'modsettingbooking'],
                    'admin-eventslogtimefilter'
                );
                $this->hint = get_string('eventslogtimefilterhint', 'mod_booking', [
                    'months' => $months,
                    'url' => $url->out(false),
                ]);
            }
        }
    }

    /**
     * Return the number of months the event logs are limited to as configured in the plugin settings.
     *
     * @return int number of months, 0 if no limit is configured
     */
    public static function get_configured_months(): int {
        $months = get_config('booking', 'eventslogtimefilter');
        if ($months === false) {
            // Setting has not been saved yet, apply the default of 3 months.
            $months = 3;
        }
        return (int) $months;
    }

    /**
     * Return the timecreated cutoff for the event logs as configured in the plugin settings.
     *
     * @return int cutoff timestamp, 0 if no limit is configured
     */
    public static function get_timecreatedfrom(): int {
        $months = self::get_configured_months();
        if (empty($months)) {
            return 0;
        }
        // Round to midnight so the wunderbyte table cache keys stay stable within a day.
        return strtotime("-{$months} months", usergetmidnight(time()));
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return [
            'eventlist' => $this->eventlist,
            'eventstable' => $this->eventstable,
            'hint' => $this->hint,
        ];
    }
}
