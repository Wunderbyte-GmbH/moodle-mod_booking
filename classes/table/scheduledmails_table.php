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
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

use cache_helper;
use core_text;
use html_writer;
use local_wunderbyte_table\output\table;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

use local_wunderbyte_table\wunderbyte_table;

use stdClass;

global $CFG;
/**
 * Table to manage users (used in report.php).
 *
 * @package mod_booking
 * @author Georg Maißer, Bernhard Fischer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduledmails_table extends wunderbyte_table {
    /**
     * Return dragable column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_dragable(stdClass $values) {

        global $OUTPUT;

        return $OUTPUT->render_from_template('local_wunderbyte_table/col_sortableitem', []);
    }

    /**
     * Return column timemodified.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_nextruntime(stdClass $values): string {
        if (empty($values->nextruntime)) {
            return '';
        }
        return date('d.m.Y h:s', $values->nextruntime);
    }

    /**
     * Return column timemodified.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_firstname(stdClass $values): string {
        return format_string($values->firstname);
    }

    /**
     * Return column timemodified.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_lastname(stdClass $values): string {
        return format_string($values->lastname);
    }

    /**
     * Return column subject.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_subject(stdClass $values): string {
        return format_string($values->subject);
    }

    /**
     * Returns the name of the booking option.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_optionid(stdClass $values): string {
        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);

        $url = new \moodle_url(
            '/mod/booking/optionview.php',
            [
                'cmid' => $values->cmid,
                'optionid' => $values->optionid,
            ]
        );
        return html_writer::link($url, $settings->get_title_with_prefix(), ['target' => '_blank']);
    }

    /**
     * Returns the name of the booking option.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_cmid(stdClass $values): string {
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($values->cmid);

        $url = new \moodle_url(
            '/mod/booking/view.php',
            [
                'id' => $values->cmid,
            ]
        );
        return html_writer::link($url, $bookingsettings->name, ['target' => '_blank']);
    }

    /**
     * Return column messagetext.
     *
     * @param stdClass $values
     *
     * @return string
     *
     */
    public function col_message(stdClass $values): string {
        global $PAGE;

        $plain = trim(strip_tags($values->message));

        if (core_text::strlen($plain) <= 20) {
            return format_string($values->message);
        }

        $preview  = core_text::substr($plain, 0, 20) . '…';
        $fulltext = format_text($values->message, FORMAT_HTML);

        $modalid = 'messagemodal_' . $values->id;

        // Add JavaScript to ensure modal works.
        $PAGE->requires->js_amd_inline("
            require(['jquery'], function($) {
                $(document).ready(function() {
                    $('#{$modalid}').on('show.bs.modal', function (e) {
                        console.log('Modal opening');
                    });
                });
            });
        ");

        // Trigger button with onclick fallback.
        $button = html_writer::tag(
            'button',
            s($preview),
            [
                'type' => 'button',
                'class' => 'btn btn-link p-0 align-baseline',
                'data-bs-toggle' => 'modal',
                'data-bs-target' => '#' . $modalid,
                'onclick' => "document.getElementById('{$modalid}').style.display='block';
                document.getElementById('{$modalid}').classList.add('show');",
            ]
        );

        // Complete modal structure.
        $modalheader = html_writer::div(
            html_writer::tag('h5', get_string('message'), ['class' => 'modal-title']) .
            html_writer::tag(
                'button',
                '&times;',
                [
                'type' => 'button',
                'class' => 'btn-close',
                'data-bs-dismiss' => 'modal',
                'aria-label' => 'Close',
                'onclick' => "document.getElementById('{$modalid}').style.display='none';
                document.getElementById('{$modalid}').classList.remove('show');",
                ]
            ),
            'modal-header'
        );

        $modalbody = html_writer::div($fulltext, 'modal-body');

        $modalfooter = html_writer::div(
            html_writer::tag(
                'button',
                get_string('close', 'mod_booking'),
                [
                'type' => 'button',
                'class' => 'btn btn-secondary',
                'data-bs-dismiss' => 'modal',
                'onclick' => "document.getElementById('{$modalid}').style.display='none';
                document.getElementById('{$modalid}').classList.remove('show');",
                ]
            ),
            'modal-footer'
        );

        $modalcontent = html_writer::div(
            $modalheader . $modalbody . $modalfooter,
            'modal-content'
        );

        $modaldialog = html_writer::div($modalcontent, 'modal-dialog modal-lg');

        $modal = html_writer::div($modaldialog, 'modal fade', [
            'id' => $modalid,
            'tabindex' => '-1',
            'aria-labelledby' => $modalid . 'Label',
            'aria-hidden' => 'true',
            'role' => 'dialog',
        ]);

        return $button . $modal;
    }

    /**
     * This handles the action column with buttons, icons, checkboxes.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_action($values) {

        global $OUTPUT;

        $data[] = [
            'label' => get_string('delete', 'core'), // Name of your action button.
            'class' => 'btn btn-danger',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-cog', // Add an icon before the label.
            'arialabel' => 'cogwheel', // Add an aria-label string to your icon.
            'title' => 'Edit', // We be displayed when hovered over icon.
            'id' => $values->id,
            'name' => $values->name,
            'methodname' => 'deleteitem', // The method needs to be added to your child of wunderbyte_table class.
            'nomodal' => false,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->id,
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template(
            'local_wunderbyte_table/component_actionbutton',
            [
                'showactionbuttons' => $data,
            ]
        );
    }

    /**
     * Action to delete a scheduled mail.
     *
     * @param int $id
     *
     * @return array
     *
     */
    public function action_deleteitem(int $id): array {
        global $DB;

        $DB->delete_records('task_adhoc', ['id' => $id]);

        cache_helper::purge_by_event('setbackscheduledmailscache');

        return [
            'success' => 1,
            'message' => get_string('deleted', 'mod_booking'),
        ];
    }
}
