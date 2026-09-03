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
 * Slot rule write helpers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use cache_helper;
use mod_booking\singleton_service;
use stdClass;

/**
 * Helper class for writing slot rules and keeping caches in sync.
 */
class slot_rule_manager {
    /** @var string rule type closed */
    public const RULETYPE_CLOSED = 'closed';

    /** @var string rule type price */
    public const RULETYPE_PRICE = 'price';

    /** @var string price mode absolute */
    public const PRICEMODE_ABSOLUTE = 'absolute';

    /** @var string price mode delta */
    public const PRICEMODE_DELTA = 'delta';

    /** @var string price mode factor */
    public const PRICEMODE_FACTOR = 'factor';

    /**
     * Save or update a slot rule.
     *
     * @param stdClass $ruledata
     * @return int rule id
     */
    public static function save_rule(stdClass $ruledata): int {
        global $DB;

        $now = time();
        $record = new stdClass();
        $record->optionid = (int)($ruledata->optionid ?? 0);
        $record->ruletype = (string)($ruledata->ruletype ?? self::RULETYPE_CLOSED);
        $record->priority = (int)($ruledata->priority ?? 100);
        $record->activefrom = (int)($ruledata->activefrom ?? 0);
        $record->activeuntil = (int)($ruledata->activeuntil ?? 0);
        $record->weekdays = (string)($ruledata->weekdays ?? '');
        $record->timerangestart = (string)($ruledata->timerangestart ?? '');
        $record->timerangeend = (string)($ruledata->timerangeend ?? '');
        $record->valueint = (int)($ruledata->valueint ?? 0);
        $record->payloadjson = (string)($ruledata->payloadjson ?? '');
        $record->timemodified = $now;

        if ($record->optionid <= 0) {
            throw new \coding_exception('slot_rule_manager: optionid is required');
        }

        if (!in_array($record->ruletype, [self::RULETYPE_CLOSED, self::RULETYPE_PRICE], true)) {
            $record->ruletype = self::RULETYPE_CLOSED;
        }

        $ruleid = (int)($ruledata->id ?? 0);
        if ($ruleid > 0 && $DB->record_exists('booking_slot_rule', ['id' => $ruleid])) {
            $existing = $DB->get_record('booking_slot_rule', ['id' => $ruleid], 'id,timecreated,optionid', MUST_EXIST);
            $record->id = $existing->id;
            $record->timecreated = (int)$existing->timecreated;
            $record->optionid = (int)$existing->optionid;
            $DB->update_record('booking_slot_rule', $record);
        } else {
            $record->timecreated = $now;
            $ruleid = (int)$DB->insert_record('booking_slot_rule', $record);
        }

        self::purge_option_caches($record->optionid);
        return $ruleid;
    }

    /**
     * Save or update a slot rule price modifier for one price category.
     *
     * @param stdClass $pricedata
     * @return int rule price id
     */
    public static function save_rule_price(stdClass $pricedata): int {
        global $DB;

        $now = time();
        $record = new stdClass();
        $record->ruleid = (int)($pricedata->ruleid ?? 0);
        $record->pricecategoryidentifier = trim((string)($pricedata->pricecategoryidentifier ?? 'default'));
        $record->mode = (string)($pricedata->mode ?? self::PRICEMODE_ABSOLUTE);
        $record->value = (float)($pricedata->value ?? 0);
        $record->currency = (string)($pricedata->currency ?? '');

        if ($record->ruleid <= 0 || !$DB->record_exists('booking_slot_rule', ['id' => $record->ruleid])) {
            throw new \coding_exception('slot_rule_manager: valid ruleid is required for rule price');
        }

        if (!in_array($record->mode, [self::PRICEMODE_ABSOLUTE, self::PRICEMODE_DELTA, self::PRICEMODE_FACTOR], true)) {
            $record->mode = self::PRICEMODE_ABSOLUTE;
        }

        if ($record->pricecategoryidentifier === '') {
            $record->pricecategoryidentifier = 'default';
        }

        $rulepriceid = (int)($pricedata->id ?? 0);
        if ($rulepriceid > 0 && $DB->record_exists('booking_slot_rule_price', ['id' => $rulepriceid])) {
            $record->id = $rulepriceid;
            $DB->update_record('booking_slot_rule_price', $record);
        } else {
            $record->timecreated = $now;
            $rulepriceid = (int)$DB->insert_record('booking_slot_rule_price', $record);
        }

        $optionid = (int)$DB->get_field('booking_slot_rule', 'optionid', ['id' => $record->ruleid], MUST_EXIST);
        self::purge_option_caches($optionid);
        return $rulepriceid;
    }

    /**
     * Delete a rule and all associated rule prices.
     *
     * @param int $ruleid
     * @return void
     */
    public static function delete_rule(int $ruleid): void {
        global $DB;

        if ($ruleid <= 0 || !$DB->record_exists('booking_slot_rule', ['id' => $ruleid])) {
            return;
        }

        $optionid = (int)$DB->get_field('booking_slot_rule', 'optionid', ['id' => $ruleid], MUST_EXIST);
        $DB->delete_records('booking_slot_rule_price', ['ruleid' => $ruleid]);
        $DB->delete_records('booking_slot_rule', ['id' => $ruleid]);

        self::purge_option_caches($optionid);
    }

    /**
     * Delete one rule price modifier.
     *
     * @param int $rulepriceid
     * @return void
     */
    public static function delete_rule_price(int $rulepriceid): void {
        global $DB;

        if ($rulepriceid <= 0 || !$DB->record_exists('booking_slot_rule_price', ['id' => $rulepriceid])) {
            return;
        }

        $ruleid = (int)$DB->get_field('booking_slot_rule_price', 'ruleid', ['id' => $rulepriceid], MUST_EXIST);
        $optionid = (int)$DB->get_field('booking_slot_rule', 'optionid', ['id' => $ruleid], MUST_EXIST);

        $DB->delete_records('booking_slot_rule_price', ['id' => $rulepriceid]);
        self::purge_option_caches($optionid);
    }

    /**
     * Purge option-specific caches and singletons after rule updates.
     *
     * @param int $optionid booking option id
     * @return void
     */
    private static function purge_option_caches(int $optionid): void {
        if ($optionid <= 0) {
            return;
        }

        slot_rules::invalidate_option_cache($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
        cache_helper::purge_by_event('setbackslotrules');
        cache_helper::purge_by_event('setbackslotruleprices');
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
    }
}
