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

namespace mod_booking\local\certificate_conditions;

use mod_booking\local\certificate_conditions\conditions\taggedoptions;
use html_writer;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_conditions_info {
    /**
     * Add static form information about certificate conditions targeting this booking option.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_static_info_to_mform(MoodleQuickForm &$mform, array $formdata): void {

        $taggedconditions = self::get_all_taggedoptions_conditions();
        if (!empty($taggedconditions)) {
            $taggedconditions = ['Choose'] + $taggedconditions;
            $mform->addElement(
                'autocomplete',
                'taggedconditions',
                get_string('certificateconditions', 'mod_booking'),
                $taggedconditions,
                ['multiple' => true]
            );
        }
        $mform->setType('taggedconditions', PARAM_RAW);

        $optionid = (int)($formdata['id'] ?? $formdata['optionid'] ?? 0);
        $conditioninfos = [];
        if (!empty($optionid)) {
            $conditioninfos = self::get_condition_infos_targeting_option($optionid);
        }

        $items = [];
        foreach ($conditioninfos as $conditioninfo) {
            if (!empty($conditioninfo['url'])) {
                $items[] = html_writer::tag('li', html_writer::link($conditioninfo['url'], s($conditioninfo['name'])));
            } else {
                $items[] = html_writer::tag('li', s($conditioninfo['name']));
            }
        }

        if (empty($items)) {
            $items[] = html_writer::tag('li', get_string('certificateconditionsoptionnone', 'mod_booking'));
        }

        $content = html_writer::tag('ul', implode('', $items), ['class' => 'mb-2']);

        $mform->addElement(
            'static',
            'certificateconditions_optioninfo',
            get_string('certificateconditionsoptionheading', 'mod_booking'),
            $content
        );
    }

    /**
     * Return infos of all certificate conditions targeting a booking option.
     *
     * @param int $optionid
     * @return array
     */
    private static function get_condition_infos_targeting_option(int $optionid): array {
          global $DB;

        $sql = "SELECT i.id AS itemrowid,
                    c.name,
                    c.contextid,
                    ctx.contextlevel,
                    ctx.instanceid
                FROM {booking_cert_cond_item} i
                JOIN {booking_cert_cond} c
                    ON c.id = i.conditionid
            LEFT JOIN {context} ctx
                    ON ctx.id = c.contextid
                WHERE i.itemid = :optionid
            ORDER BY c.name ASC";

        $records = $DB->get_records_sql($sql, ['optionid' => $optionid]);
        if (empty($records)) {
            return [];
        }

        $conditioninfos = [];
        foreach ($records as $record) {
            $url = null;

            if ((int)$record->contextlevel === CONTEXT_SYSTEM) {
                $url = new moodle_url('/mod/booking/edit_certificateconditions.php', [
                    'contextid' => (int)$record->contextid,
                ]);
            } else if ((int)$record->contextlevel === CONTEXT_MODULE) {
                $url = new moodle_url('/mod/booking/edit_certificateconditions.php', [
                    'cmid' => (int)$record->instanceid,
                ]);
            }

            $conditioninfos[] = [
                'name' => (string)($record->name ?? ''),
                'url' => $url,
            ];
        }
        return $conditioninfos;
    }

    /**
     * Returns tagged options conditions for choosing.
     *
     * @return array
     *
     */
    public static function get_all_taggedoptions_conditions() {
        global $DB;

        $records = $DB->get_records('booking_cert_cond', null, 'name ASC', 'id,name,contextid,logicjson');
        $conditions = [];

        foreach ($records as $record) {
            if (empty($record->logicjson)) {
                continue;
            }

            $conditionjson = json_decode($record->logicjson);
            if ($conditionjson->conditionname === 'taggedoptions') {
                $conditions[$record->id] = $record->name;
            }
        }
        return $conditions;
    }

    /**
     * Get tagged condition IDs targeting a booking option.
     *
     * @param int $optionid
     * @return array Array of condition IDs
     */
    public static function get_tagged_condition_ids_for_option(int $optionid): array {
        global $DB;

        $sql = "SELECT DISTINCT c.id
                FROM {booking_cert_cond_item} i
                JOIN {booking_cert_cond} c
                    ON c.id = i.conditionid
                WHERE i.itemid = :optionid
                AND c.logicjson LIKE :taggedoptions";

        $records = $DB->get_records_sql($sql, [
            'optionid' => $optionid,
            'taggedoptions' => '%"conditionname":"taggedoptions"%',
        ]);

        return array_keys($records);
    }

    /**
     * Persist tagged options links from booking option form data.
     *
     * @param array $formdata
     * @return void
     */
    public static function save_tagged_conditions_from_option_form(array $formdata): void {
        $optionid = (int)($formdata['id'] ?? $formdata['optionid'] ?? 0);
        if (empty($optionid)) {
            return;
        }
        $data = new stdClass();
        $data->id = $optionid;
        $data->optionid = $optionid;
        $data->conditions = $formdata['taggedconditions'] ?? [];

        $logic = new taggedoptions();
        $logic->save_items(0, $data);
    }
}
