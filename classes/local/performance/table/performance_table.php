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
 * Performance table.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\performance\table;

use html_writer;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\local\performance\performance_renderer;
use stdClass;

/**
 * Performance table
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class performance_table extends wunderbyte_table {
    /**
     * Actions column
     *
     * @param stdClass $values
     * @return string
     */
    public function col_actions(stdClass $values) {
        global $OUTPUT;

        $data[] = [
            'label' => get_string('delete'), // Name of your action button.
            'class' => 'btn btn-danger',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-trash', // Add an icon before the label.
            'id' => $values->shortcodehash,
            'name' => $values->shortcodename,
            'methodname' => 'deleterow', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->shortcodehash,
                'titlestring' => 'delete',
                'bodystring' => 'deleteperformancemeasurements',
                'submitbuttonstring' => 'delete',
                'component' => 'mod_booking',
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        $table = new measurements_table('performancemeasurementtable');

        $table->define_headers([
            get_string('endtimemeasurement', 'booking'),
            get_string('notes', 'booking'),
            get_string('actions', 'booking'),
        ]);

        $table->define_columns(['endtime', 'note', 'actions']);
        $table->sortablecolumns = ['measurementname'];
        $table->sort_default_column = 'measurementname';

        $from = "{booking_performance_measurements}";
        $where = "shortcodename = :shortcodename AND measurementname = :measurementname";
        $params = [
            'shortcodename' => $values->shortcodename,
            'measurementname' => 'Entire time',
        ];

        $table->set_filter_sql('*', $from, $where, '', $params);

        [$a, $b, $html] = $table->lazyouthtml(10, true);

        $modal = $this->build_modal($html, $values->shortcodehash);
        return $modal . $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Build modal for editing the shortcode measurements.
     *
     * @param string $html
     * @param string $shortcodehash
     * @return string
     *
     */
    private function build_modal(string $html, $shortcodehash) {
        $modalid = 'modal_' . $shortcodehash;
        $modal = html_writer::start_tag('button', [
            'type' => 'button',
            'class' => 'btn btn-primary',
            'data-toggle' => 'modal',
            'data-target' => '#' . $modalid,
        ]);
        $modal .= html_writer::tag('i', '', ['class' => 'fa fa-edit', 'aria-label' => '', 'title' => '']);
        $modal .= ' Edit';
        $modal .= html_writer::end_tag('button');

        $modal .= html_writer::start_tag('div', [
            'class' => 'modal fade',
            'id' => $modalid,
            'tabindex' => '-1',
            'role' => 'dialog',
            'aria-hidden' => 'true',
        ]);

        $modal .= html_writer::start_div('modal-dialog modal-xl', ['role' => 'document']);
        $modal .= html_writer::start_div('modal-content');

        // Modal header.
        $modal .= html_writer::start_div('modal-header');
        $modal .= html_writer::tag('h5', 'Modal title', ['class' => 'modal-title']);
        $modal .= html_writer::tag('button', html_writer::tag('span', '&times;', ['aria-hidden' => 'true']), [
            'type' => 'button',
            'class' => 'close',
            'data-dismiss' => 'modal',
            'aria-label' => 'Close',
        ]);
        $modal .= html_writer::end_div();

        // Modal body with table HTML.
        $modal .= html_writer::div($html, 'modal-body');

        // Modal footer.
        $modal .= html_writer::start_div('modal-footer');
        $modal .= html_writer::tag('button', 'close', [
            'type' => 'button',
            'class' => 'btn btn-secondary',
            'data-dismiss' => 'modal',
        ]);
        $modal .= html_writer::end_div();

        $modal .= html_writer::end_div();
        $modal .= html_writer::end_div();
        $modal .= html_writer::end_div();
        return $modal;
    }

    /**
     * Implement delete row function.
     *
     * @param mixed $id
     * @param mixed $data
     *
     * @return array
     *
     */
    public function action_deleterow($id, string $data) {
        global $DB;

        $dataobject = json_decode($data);

        $DB->delete_records(
            performance_renderer::TABLE,
            [
                'shortcodehash' => $dataobject->id,
            ]
        );

        return [
           'success' => 1,
           'message' => get_string('success'),
        ];
    }
}
