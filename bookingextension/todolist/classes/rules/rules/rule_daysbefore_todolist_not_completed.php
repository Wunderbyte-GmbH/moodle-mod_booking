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

namespace bookingextension_todolist\rules\rules;

use bookingextension_todolist\local\todolist_helper;
use core_component;
use mod_booking\booking_rules\rules\rule_specifictime;
use MoodleQuickForm;
use stdClass;

/**
 * Rule: notify before date but only for incomplete todo lists.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_daysbefore_todolist_not_completed extends rule_specifictime {
    /** @var string */
    protected $rulename = 'rule_daysbefore_todolist_not_completed';

    /** @var string */
    protected $rulenamestringid = 'ruledaysbefore_todolist_not_completed';

    /**
     * Rule name in select list.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_rule(bool $localized = true): string {
        return $localized ? get_string($this->rulenamestringid, 'bookingextension_todolist') : $this->rulename;
    }

    /**
     * Reuse specifictime form and add extension-specific description.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param array $ajaxformdata
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeateloptions, array $ajaxformdata = []) {
        parent::add_rule_to_mform($mform, $repeateloptions, $ajaxformdata);
        $mform->addElement(
            'static',
            'rule_daysbefore_todolist_not_completed_desc',
            '',
            get_string('ruledaysbefore_todolist_not_completed_desc', 'bookingextension_todolist')
        );
    }

    /**
     * Persist rule and keep extension component in ruledata.
     *
     * @param stdClass $data
     * @return int
     */
    public function save_rule(stdClass &$data): int {
        global $DB;

        $ruleid = parent::save_rule($data);

        $record = $DB->get_record('booking_rules', ['id' => $ruleid], 'id, rulejson', MUST_EXIST);
        $jsonobject = json_decode($record->rulejson);
        if (empty($jsonobject->ruledata)) {
            $jsonobject->ruledata = new stdClass();
        }
        $jsonobject->ruledata->component = core_component::get_component_from_classname(static::class);
        $record->rulejson = json_encode($jsonobject);
        $DB->update_record('booking_rules', $record);

        return $ruleid;
    }

    /**
     * Execute only for booking options with an incomplete todo list.
     *
     * @param int $optionid
     * @param int $userid
     * @param bool $testmode
     * @param int $nextruntime
     * @return array
     */
    public function get_records_for_execution(
        int $optionid = 0,
        int $userid = 0,
        bool $testmode = false,
        int $nextruntime = 0
    ) {
        $records = parent::get_records_for_execution($optionid, $userid, $testmode, $nextruntime);

        return array_filter(
            $records,
            static fn($record) => !todolist_helper::todolist_completed((int)$record->optionid)
        );
    }
}
