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
     * If there are no eventnames specified, we will get all of them for this plugin.
     *
     * @param int $id
     * @param array $eventnames
     */
    public function __construct(int $id = 0, array $eventnames = []) {

        global $DB;

        [$select, $from, $where, $filter, $params] = booking::return_sql_for_event_logs('mod_booking', $eventnames, $id);

        $tablenamestring = "eventlogtable" . $id . implode('-', $eventnames);

        $tablename = md5($tablenamestring);

        $table = new event_log_table($tablename);

        $table->set_filter_sql($select, $from, $where, $filter, $params);

        $columnsarray = [
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

        $table->showrowcountselect = true;

        $table->filteronloadinactive = true;

        $table->define_baseurl(new moodle_url('/mod/booking/downloads/download.php'));

        [$idstring, $tablecachehash, $html] = $table->lazyouthtml(10, true);
        $this->eventstable = $html;
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
        ];
    }
}
