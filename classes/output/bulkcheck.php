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
 * Renders the list of parked rule mails.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use local_wunderbyte_table\filters\types\standardfilter;
use mod_booking\local\bulk_check\bulk_check;
use mod_booking\table\bulk_check_table;
use renderable;
use renderer_base;
use templatable;

/**
 * Every mail the bulk check stopped, one per row, with a checkbox on each of them.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkcheck implements renderable, templatable {
    /** @var array Data for the template. */
    private array $data = [];

    /** @var bulk_check_table The table, kept for the tests. */
    private bulk_check_table $table;

    /**
     * Builds the table.
     *
     * @param int $ruleid Limits the list to one rule, zero shows all of them.
     */
    public function __construct(int $ruleid = 0) {
        $table = new bulk_check_table('mod_booking_bulk_check_parked');

        $columns = [
            'rulename' => get_string('bulkcheckrulecolumn', 'mod_booking'),
            'optionname' => get_string('bulkcheckoptioncolumn', 'mod_booking'),
            'recipient' => get_string('bulkcheckusercolumn', 'mod_booking'),
            'email' => get_string('bulkcheckemailcolumn', 'mod_booking'),
            'sendtime' => get_string('bulkcheckduecolumn', 'mod_booking'),
            'waiting' => get_string('bulkcheckoldestcolumn', 'mod_booking'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        // The shared action web service checks this before it dispatches to our handlers.
        $table->requirecapability = 'mod/booking:managebulkcheck';

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($ruleid);
        $table->set_sql($fields, $from, $where, $params);

        $table->add_filter(new standardfilter('rulename', get_string('bulkcheckrulecolumn', 'mod_booking')));
        $table->add_filter(new standardfilter('optionname', get_string('bulkcheckoptioncolumn', 'mod_booking')));
        $table->define_fulltextsearchcolumns(['rulename', 'optionname', 'recipient', 'email']);
        $table->define_sortablecolumns(['rulename', 'optionname', 'recipient', 'email', 'sendtime']);

        $table->sort_default_column = 'sendtime';
        $table->sort_default_order = SORT_ASC;

        $this->add_actionbuttons($table, $ruleid);

        // A cache of its own, so that a release can clear this list alone. It cannot be
        // switched off instead: the filters of a table are built through the cache without
        // asking whether there is one.
        $table->define_cache('mod_booking', 'bulkcheckmails');

        $table->pageable(true);
        $table->showrowcountselect = true;
        $table->showcountlabel = true;
        $table->showfilterontop = true;

        $this->table = $table;
        $this->data['parked'] = bulk_check::count_parked($ruleid);
        $this->data['renderedtable'] = $table->outhtml(25, false);
    }

    /**
     * The four buttons above and below the list.
     *
     * Two of them act on what is ticked, two on the whole scope of the page. The scope ones
     * cannot honour the filter, because the action web service is told neither the filter nor
     * the search, so their confirmation says what they really do.
     *
     * The id of every button is -1: that is what makes the library send one request with the
     * ticked ids in it, instead of ignoring them or firing one request per row.
     *
     * @param bulk_check_table $table
     * @param int $ruleid
     * @return void
     */
    private function add_actionbuttons(bulk_check_table $table, int $ruleid): void {
        $parked = bulk_check::count_parked($ruleid);

        $table->actionbuttons[] = [
            'label' => get_string('bulkchecksendselected', 'mod_booking'),
            'class' => 'btn btn-success btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-paper-plane',
            'arialabel' => 'releaseselected',
            'id' => -1,
            'methodname' => 'releaseselected',
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [
                'id' => -1,
                'titlestring' => 'bulkcheckreleasetitle',
                'bodystring' => 'bulkcheckreleasebody',
                'submitbuttonstring' => 'bulkcheckreleasesubmit',
                'component' => 'mod_booking',
                'labelcolumn' => 'recipient',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => get_string('bulkcheckdismissselected', 'mod_booking'),
            'class' => 'btn btn-danger btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-ban',
            'arialabel' => 'dismissselected',
            'id' => -1,
            'methodname' => 'dismissselected',
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [
                'id' => -1,
                'titlestring' => 'bulkcheckdismisstitle',
                'bodystring' => 'bulkcheckdismissbody',
                'submitbuttonstring' => 'bulkcheckdismisssubmit',
                'component' => 'mod_booking',
                'labelcolumn' => 'recipient',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => get_string('bulkcheckreleaseall', 'mod_booking', $parked),
            'class' => 'btn btn-outline-success btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-paper-plane',
            'arialabel' => 'releaseall',
            'id' => -1,
            'methodname' => 'releaseall',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => -1,
                'ruleid' => $ruleid,
                'titlestring' => 'bulkcheckreleasetitle',
                'bodystring' => 'bulkcheckreleaseallbody',
                'submitbuttonstring' => 'bulkcheckreleasesubmit',
                'component' => 'mod_booking',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => get_string('bulkcheckdismissall', 'mod_booking', $parked),
            'class' => 'btn btn-outline-danger btn-sm',
            'href' => '#',
            'iclass' => 'fa fa-ban',
            'arialabel' => 'dismissall',
            'id' => -1,
            'methodname' => 'dismissall',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => -1,
                'ruleid' => $ruleid,
                'titlestring' => 'bulkcheckdismisstitle',
                'bodystring' => 'bulkcheckdismissallbody',
                'submitbuttonstring' => 'bulkcheckdismisssubmit',
                'component' => 'mod_booking',
            ],
        ];
    }

    /**
     * Getter for the table (for testing purposes).
     *
     * @return bulk_check_table
     */
    public function return_table(): bulk_check_table {
        return $this->table;
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return $this->data;
    }
}
