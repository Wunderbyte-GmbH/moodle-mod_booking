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
 * Slot rule helpers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use cache;

/**
 * Helper class to fetch and apply slot rules.
 */
class slot_rules {
    /** @var array */
    private static $requestrulescache = [];

    /** @var array */
    private static $requestpricerulescache = [];

    /** @var string rule type: close matching slots */
    private const RULETYPE_CLOSED = 'closed';

    /** @var string rule type: price modifier */
    private const RULETYPE_PRICE = 'price';

    /** @var bool|null cached availability of slot rule tables in current request */
    private static $hastables = null;

    /**
     * Return all rules for an option from request cache, MUC, or DB.
     *
     * @param int $optionid booking option id
     * @return array
     */
    public static function get_rules_for_option(int $optionid): array {
        global $DB;

        if ($optionid <= 0) {
            return [];
        }

        if (!self::rule_tables_available()) {
            return [];
        }

        if (isset(self::$requestrulescache[$optionid])) {
            return self::$requestrulescache[$optionid];
        }

        $cache = cache::make('mod_booking', 'slotrulesbyoption');
        $cachekey = 'option_' . $optionid;
        $cached = $cache->get($cachekey);

        if ($cached === true) {
            self::$requestrulescache[$optionid] = [];
            return [];
        }

        if (is_array($cached)) {
            self::$requestrulescache[$optionid] = $cached;
            return $cached;
        }

        $rules = $DB->get_records(
            'booking_slot_rule',
            ['optionid' => $optionid],
            'priority DESC, id ASC'
        );

        if (empty($rules)) {
            $cache->set($cachekey, true);
            self::$requestrulescache[$optionid] = [];
            return [];
        }

        $rules = array_values($rules);
        $cache->set($cachekey, $rules);
        self::$requestrulescache[$optionid] = $rules;

        return $rules;
    }

    /**
     * Apply currently supported slot rules to generated slots.
     *
     * @param int $optionid booking option id
     * @param array $slots generated base slots
     * @return array
     */
    public static function apply_to_slots(int $optionid, array $slots): array {
        if (empty($slots)) {
            return [];
        }

        $rules = self::get_rules_for_option($optionid);
        if (empty($rules)) {
            return $slots;
        }

        $result = [];
        foreach ($slots as $slot) {
            [$slotstart, $slotend] = $slot;
            if (!self::is_slot_allowed_by_rules($rules, $slotstart, $slotend)) {
                continue;
            }
            $result[] = [$slotstart, $slotend];
        }

        return $result;
    }

    /**
     * Invalidate rule cache for one option in request + MUC cache.
     *
     * @param int $optionid booking option id
     * @return void
     */
    public static function invalidate_option_cache(int $optionid): void {
        unset(self::$requestrulescache[$optionid]);
        unset(self::$requestpricerulescache[$optionid]);

        if ($optionid <= 0) {
            return;
        }

        $cache = cache::make('mod_booking', 'slotrulesbyoption');
        $cache->delete('option_' . $optionid);

        $pricecache = cache::make('mod_booking', 'slotrulepricesbyoption');
        $pricecache->delete('option_' . $optionid);
    }

    /**
     * Apply matching price rules for a single slot to the provided slot base price.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param float $baseprice slot base price after normal price resolution
     * @param string $pricecategoryidentifier resolved user category identifier
     * @return float
     */
    public static function apply_price_rules_to_slot_price(
        int $optionid,
        int $slotstart,
        int $slotend,
        float $baseprice,
        string $pricecategoryidentifier = ''
    ): float {
        $pricerules = self::get_price_rules_for_option($optionid);
        if (empty($pricerules)) {
            return $baseprice;
        }

        $price = $baseprice;
        foreach ($pricerules as $pricerule) {
            if (!self::rule_matches_slot($pricerule, $slotstart, $slotend)) {
                continue;
            }

            $rulecategoryidentifier = (string)($pricerule->pricecategoryidentifier ?? '');
            if (!self::price_rule_matches_category($rulecategoryidentifier, $pricecategoryidentifier)) {
                continue;
            }

            $mode = (string)($pricerule->mode ?? 'absolute');
            $value = (float)($pricerule->value ?? 0);

            if ($mode === 'delta') {
                $price += $value;
                continue;
            }

            if ($mode === 'factor') {
                $price *= $value;
                continue;
            }

            // Default and explicit absolute mode.
            $price = $value;
        }

        return max(0.0, $price);
    }

    /**
     * Determine if a slot is allowed by the given rules.
     *
     * @param array $rules option rules
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @return bool
     */
    private static function is_slot_allowed_by_rules(array $rules, int $slotstart, int $slotend): bool {
        foreach ($rules as $rule) {
            $ruletype = (string)($rule->ruletype ?? '');
            if ($ruletype !== self::RULETYPE_CLOSED) {
                continue;
            }

            if (self::rule_matches_slot($rule, $slotstart, $slotend)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return price rules for an option from request cache, MUC, or DB.
     *
     * @param int $optionid booking option id
     * @return array
     */
    private static function get_price_rules_for_option(int $optionid): array {
        global $DB;

        if ($optionid <= 0) {
            return [];
        }

        if (!self::rule_tables_available()) {
            return [];
        }

        if (isset(self::$requestpricerulescache[$optionid])) {
            return self::$requestpricerulescache[$optionid];
        }

        $cache = cache::make('mod_booking', 'slotrulepricesbyoption');
        $cachekey = 'option_' . $optionid;
        $cached = $cache->get($cachekey);

        if ($cached === true) {
            self::$requestpricerulescache[$optionid] = [];
            return [];
        }

        if (is_array($cached)) {
            self::$requestpricerulescache[$optionid] = $cached;
            return $cached;
        }

        $sql = "SELECT sr.id AS ruleid,
                       sr.optionid,
                       sr.ruletype,
                       sr.priority,
                       sr.activefrom,
                       sr.activeuntil,
                       sr.weekdays,
                       sr.timerangestart,
                       sr.timerangeend,
                       srp.id,
                       srp.pricecategoryidentifier,
                       srp.mode,
                       srp.value,
                       srp.currency
                  FROM {booking_slot_rule} sr
                  JOIN {booking_slot_rule_price} srp ON srp.ruleid = sr.id
                 WHERE sr.optionid = :optionid
                   AND sr.ruletype = :ruletype
              ORDER BY sr.priority DESC, sr.id ASC, srp.id ASC";

        $params = [
            'optionid' => $optionid,
            'ruletype' => self::RULETYPE_PRICE,
        ];

        $pricerules = $DB->get_records_sql($sql, $params);
        if (empty($pricerules)) {
            $cache->set($cachekey, true);
            self::$requestpricerulescache[$optionid] = [];
            return [];
        }

        $pricerules = array_values($pricerules);
        $cache->set($cachekey, $pricerules);
        self::$requestpricerulescache[$optionid] = $pricerules;

        return $pricerules;
    }

    /**
     * Match a rule category identifier to an active category identifier.
     *
     * @param string $rulecategoryidentifier configured rule category identifier (supports comma separated values)
     * @param string $activecategoryidentifier active category identifier
     * @return bool
     */
    private static function price_rule_matches_category(string $rulecategoryidentifier, string $activecategoryidentifier): bool {
        $rulecategories = array_values(array_filter(array_map('trim', explode(',', $rulecategoryidentifier))));
        if (empty($rulecategories)) {
            return false;
        }

        foreach ($rulecategories as $rulecategory) {
            if ($rulecategory === 'default') {
                return true;
            }

            if ($activecategoryidentifier !== '' && strpos($activecategoryidentifier, $rulecategory) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate if a rule matches the given slot.
     *
     * @param \stdClass $rule rule record
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @return bool
     */
    private static function rule_matches_slot(\stdClass $rule, int $slotstart, int $slotend): bool {
        if ($slotend <= $slotstart) {
            return false;
        }

        $activefrom = (int)($rule->activefrom ?? 0);
        if ($activefrom > 0 && $slotend <= $activefrom) {
            return false;
        }

        $activeuntil = (int)($rule->activeuntil ?? 0);
        if ($activeuntil > 0 && $slotstart >= ($activeuntil + DAYSECS)) {
            return false;
        }

        $weekdays = self::parse_weekdays((string)($rule->weekdays ?? ''));
        if (!empty($weekdays)) {
            $weekday = (int)date('N', $slotstart);
            if (!in_array($weekday, $weekdays, true)) {
                return false;
            }
        }

        $rangestart = self::time_to_seconds((string)($rule->timerangestart ?? ''));
        $rangeend = self::time_to_seconds((string)($rule->timerangeend ?? ''));

        if ($rangestart === null || $rangeend === null) {
            return true;
        }

        if ($rangeend <= $rangestart) {
            return false;
        }

        $daystart = strtotime('midnight', $slotstart);
        $slotdaystartsec = $slotstart - $daystart;
        $slotdayendsec = $slotend - $daystart;

        return $slotdaystartsec < $rangeend && $slotdayendsec > $rangestart;
    }

    /**
     * Parse weekdays CSV to unique integer values (1..7).
     *
     * @param string $csv weekdays csv
     * @return int[]
     */
    private static function parse_weekdays(string $csv): array {
        $parts = array_filter(array_map('trim', explode(',', $csv)), static function (string $value): bool {
            return $value !== '';
        });

        $weekdays = [];
        foreach ($parts as $part) {
            $day = (int)$part;
            if ($day >= 1 && $day <= 7) {
                $weekdays[] = $day;
            }
        }

        return array_values(array_unique($weekdays));
    }

    /**
     * Check if new slot rule tables are available in current database schema.
     *
     * @return bool
     */
    private static function rule_tables_available(): bool {
        global $DB;

        if (self::$hastables !== null) {
            return self::$hastables;
        }

        $dbman = $DB->get_manager();
        $hasslotruletable = $dbman->table_exists(new \xmldb_table('booking_slot_rule'));
        $hasslotrulepricetable = $dbman->table_exists(new \xmldb_table('booking_slot_rule_price'));

        self::$hastables = $hasslotruletable && $hasslotrulepricetable;
        return self::$hastables;
    }

    /**
     * Parse HH:MM to seconds from midnight.
     *
     * @param string $time time in HH:MM
     * @return int|null
     */
    private static function time_to_seconds(string $time): ?int {
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * HOURSECS) + ($minutes * MINSECS);
    }

    /**
     * Reset static caches (call from tests teardown).
     */
    public static function reset_caches(): void {
        self::$requestrulescache = [];
        self::$requestpricerulescache = [];
        self::$hastables = null;
    }
}
