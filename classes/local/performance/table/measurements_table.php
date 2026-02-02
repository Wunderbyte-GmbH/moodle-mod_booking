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

use cache_helper;
use html_writer;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\local\performance\performance_renderer;
use stdClass;

/**
 * Measurement table
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class measurements_table extends wunderbyte_table {
    /**
     * Actions column
     *
     * @param stdClass $values
     * @return string
     */
    public function col_actions(stdClass $values) {
        global $OUTPUT;

        $collapseid = 'edit_measurement_' . $values->id;

        // Edit button to control the collapse.
        $editbutton = html_writer::tag(
            'button',
            html_writer::tag('i', '', ['class' => 'fa fa-edit']) . ' ' . get_string('edit'),
            [
                'type' => 'button',
                'class' => 'btn btn-success mr-1',
                'data-toggle' => 'collapse',
                'data-target' => '#' . $collapseid,
                'aria-expanded' => 'false',
                'aria-controls' => $collapseid,
            ]
        );

        $data[] = [
            'label' => get_string('delete'), // Name of your action button.
            'class' => 'btn btn-danger',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-trash', // Add an icon before the label.
            'id' => $values->id,
            'name' => $values->measurementname,
            'methodname' => 'deletemeasurement', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->shortcodehash,
                'titlestring' => 'delete',
                'bodystring' => 'deleteperformancemeasurement',
                'submitbuttonstring' => 'delete',
                'component' => 'mod_booking',
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        $editcollapsable = $this->build_collapsable($collapseid, $values->id);

        return
            $editbutton
            . $OUTPUT->render_from_template(
                'local_wunderbyte_table/component_actionbutton',
                ['showactionbuttons' => $data]
            )
            . $editcollapsable;
    }

    /**
     * Build collapsable for editing.
     *
     * @param string $collapseid
     * @param string $valuesid
     * @return string
     *
     */
    private function build_collapsable(string $collapseid, $valuesid) {
        $editor = html_writer::start_div('collapse mt-2', ['id' => $collapseid]);

        $editor .= html_writer::start_div('card card-body');

        // Example inline field.
        $editor .= html_writer::tag('label', 'Note');
        $editor .= html_writer::tag(
            'textarea',
            s($values->note ?? ''),
            [
                'class' => 'form-control',
                'rows' => 3,
                'data-measurementid' => $valuesid,
            ]
        );

        $editor .= html_writer::empty_tag('br');
        $editor .= html_writer::tag('button', get_string('save'), [
            'class' => 'btn btn-primary',
            'data-action' => 'savemeasurement',
            'data-id' => $valuesid,
        ]);
        $editor .= html_writer::end_div();
        $editor .= html_writer::end_div();
        return $editor;
    }

    /**
     * Implement delete row function.
     * @param mixed $id
     * @param string $data
     * @return array
     */
    public function action_deletemeasurement(mixed $id, string $data): array {
        global $DB;

        $dataobject = json_decode($data);

        $measurement = $DB->get_record(
            performance_renderer::TABLE,
            ['id' => $dataobject->id],
            '*',
            MUST_EXIST
        );

        // Check if this is an "Entire time" measurement.
        if ($measurement->measurementname === 'Entire time') {
            // Delete all measurements that fall within the same time range.
            $DB->delete_records_select(
                performance_renderer::TABLE,
                'shortcodename = :shortcodename
                AND starttime >= :starttime
                AND endtime <= :endtime',
                [
                    'shortcodename' => $measurement->shortcodename,
                    'starttime' => $measurement->starttime,
                    'endtime' => $measurement->endtime,
                ]
            );
        } else {
            // Just delete the one selected measurement.
            $DB->delete_records(
                performance_renderer::TABLE,
                ['id' => $measurement->id]
            );
        }

        return [
           'success' => 1,
           'message' => get_string('success'),
        ];
    }

    /**
     * Formats the endtime column as a readable date.
     *
     * @param stdClass $row The current row.
     * @return string Formatted date string.
     */
    public function col_endtime($row) {
        $seconds = (int)($row->endtime / 1000000);
        return userdate($seconds);
    }
}
