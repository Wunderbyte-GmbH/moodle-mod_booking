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
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\certificate_conditions;

use html_writer;
use moodle_url;
use MoodleQuickForm;

/**
 * Helper class for option-level certificate condition display.
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

        $records = $DB->get_records('booking_cert_cond', null, 'name ASC', 'id,name,contextid,logicjson');
        $conditioninfos = [];

        foreach ($records as $record) {
            if (empty($record->logicjson)) {
                continue;
            }

            $logicjson = json_decode($record->logicjson);
            if (empty($logicjson) || ($logicjson->logicname ?? '') !== 'bookingoption') {
                continue;
            }

            $optionids = [];
            if (!empty($logicjson->optionids) && is_array($logicjson->optionids)) {
                $optionids = array_map('intval', $logicjson->optionids);
            } else if (!empty($logicjson->optionid)) {
                $optionids = [(int)$logicjson->optionid];
            }

            if (in_array($optionid, $optionids, true)) {
                $conditioninfos[] = [
                    'name' => (string)($record->name ?? ''),
                    'url' => self::get_condition_edit_url((int)($record->contextid ?? 0)),
                ];
            }
        }

        return $conditioninfos;
    }

    /**
     * Resolve edit URL for one certificate condition by its context id.
     *
     * @param int $contextid
     * @return moodle_url|null
     */
    private static function get_condition_edit_url(int $contextid): ?moodle_url {
        global $DB;

        if (empty($contextid)) {
            return null;
        }

        $contextrecord = $DB->get_record('context', ['id' => $contextid], 'id,contextlevel,instanceid');
        if (!$contextrecord) {
            return null;
        }

        if ((int)$contextrecord->contextlevel === CONTEXT_SYSTEM) {
            return new moodle_url('/mod/booking/edit_certificateconditions.php', ['contextid' => (int)$contextrecord->id]);
        }

        if ((int)$contextrecord->contextlevel === CONTEXT_MODULE) {
            return new moodle_url('/mod/booking/edit_certificateconditions.php', ['cmid' => (int)$contextrecord->instanceid]);
        }

        return null;
    }
}
