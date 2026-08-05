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
 * Site-wide relevance data for the SQL availability filter.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use cache;
use stdClass;

/**
 * Site-wide relevance data for the SQL availability filter.
 *
 * The availability conditions embed user data (course enrolment ids, cohort ids,
 * profile field values) into the options WHERE clause. Values that no sqlfilter
 * condition references anywhere on the site can never influence the filter
 * result, but they make the SQL string - and with it the wunderbyte table cache
 * key - unique per user, which floods the application caches. This service
 * caches, per condition, WHICH values are referenced site-wide, so the
 * conditions can trim the user data they embed down to the relevant set.
 *
 * The data is cached globally (one entry for the whole site), rebuilt lazily on
 * every cache miss (so a plain cache purge heals itself) and invalidated
 * whenever the availability of a booking option is written or an option is
 * deleted.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sqlfilter_relevance {
    /** @var string the single key used in the cache */
    private const CACHEKEY = 'relevance';

    /**
     * Drop the cached relevance data. Call whenever the availability JSON of a
     * booking option is (re)written or an option is deleted.
     *
     * @return void
     */
    public static function purge(): void {
        cache::make('mod_booking', 'sqlfilterrelevance')->delete(self::CACHEKEY);
    }

    /**
     * Whether any booking option on the site uses the SQL filter at all.
     *
     * @return bool
     */
    public static function any_sqlfilter_in_use(): bool {
        return (bool) self::get_data()['inuse'];
    }

    /**
     * The values the given condition references site-wide (empty when nothing
     * is referenced).
     *
     * @param int $conditionid the condition id as used in the availability json
     * @return array
     */
    public static function referenced_values(int $conditionid): array {
        return self::get_data()['byconditionid'][$conditionid] ?? [];
    }

    /**
     * Trim a list of user values to those the given condition references
     * site-wide, sorted deterministically for maximal cache key sharing.
     *
     * @param int $conditionid the condition id as used in the availability json
     * @param array $values the user values (e.g. enrolled course ids)
     * @return array
     */
    public static function trim_to_referenced(int $conditionid, array $values): array {
        $values = array_values(array_intersect($values, self::referenced_values($conditionid)));
        sort($values);
        return $values;
    }

    /**
     * Whether the given condition's SQL can be skipped entirely because no
     * option on the site uses it.
     *
     * Two optional contracts make a condition eligible:
     * - sqlfilter_referenced_values(): the condition's usage is fully visible
     *   through availability json entries carrying its id - skipped when its id
     *   appears in no entry.
     * - sqlfilter_usage_markervalue(): the condition's usage is signalled
     *   through a specific value of the booking_options.sqlfilter marker column
     *   (e.g. the booking time filter) - skipped when that value occurs on no
     *   option.
     * Conditions implementing neither are never skipped and behave exactly as
     * before.
     *
     * @param object $condition an mform condition instance
     * @return bool
     */
    public static function condition_is_skippable(object $condition): bool {
        if (method_exists($condition, 'sqlfilter_referenced_values')) {
            return !in_array((int) $condition->id, self::get_data()['usedids'], true);
        }
        if (method_exists($condition, 'sqlfilter_usage_markervalue')) {
            return !in_array((int) $condition::sqlfilter_usage_markervalue(), self::get_data()['markervalues'], true);
        }
        // The condition's usage is not detectable - never skip it.
        return false;
    }

    /**
     * A copy of the full relevance data for reporting purposes (read-only).
     *
     * @return array{inuse:bool,byconditionid:array,usedids:array,markervalues:array}
     */
    public static function report_overview(): array {
        return self::get_data();
    }

    /**
     * Return the relevance data, lazily rebuilt on cache miss.
     *
     * @return array{inuse:bool,byconditionid:array,usedids:array,markervalues:array}
     */
    private static function get_data(): array {
        $cache = cache::make('mod_booking', 'sqlfilterrelevance');
        $data = $cache->get(self::CACHEKEY);
        if (!is_array($data) || !isset($data['markervalues'])) {
            $data = self::build();
            $cache->set(self::CACHEKEY, $data);
        }
        return $data;
    }

    /**
     * Build the relevance data from all options carrying the sqlfilter marker.
     *
     * Every mform condition may announce the values it references in a given
     * availability entry through an optional static method
     * sqlfilter_referenced_values(stdClass $entry): array. Conditions without
     * that method simply contribute nothing - no condition is known by name
     * here.
     *
     * @return array{inuse:bool,byconditionid:array,usedids:array,markervalues:array}
     */
    private static function build(): array {
        global $DB;

        $inuse = false;
        $byconditionid = [];
        $usedids = [];
        $markervalues = [];

        // Condition id -> instance map, generically over all mform conditions.
        $conditionsbyid = [];
        foreach (bo_info::get_available_conditions(MOD_BOOKING_CONDPARAM_MFORM_ONLY) as $class) {
            $condition = method_exists($class, 'instance') ? $class::instance() : new $class();
            $conditionsbyid[(int) $condition->id] = $condition;
        }

        $records = $DB->get_recordset_select('booking_options', 'sqlfilter > 0', [], '', 'id, availability, sqlfilter');
        foreach ($records as $record) {
            $inuse = true;
            $markervalues[(int) $record->sqlfilter] = (int) $record->sqlfilter;
            if (empty($record->availability)) {
                continue;
            }
            $entries = json_decode($record->availability);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!$entry instanceof stdClass || empty($entry->sqlfilter) || !isset($entry->id)) {
                    continue;
                }
                $conditionid = (int) $entry->id;
                $usedids[$conditionid] = $conditionid;
                $condition = $conditionsbyid[$conditionid] ?? null;
                if (!$condition || !method_exists($condition, 'sqlfilter_referenced_values')) {
                    continue;
                }
                foreach ($condition::sqlfilter_referenced_values($entry) as $value) {
                    // Deduplicate via the array key.
                    $byconditionid[$conditionid][$value] = $value;
                }
            }
        }
        $records->close();

        foreach ($byconditionid as $conditionid => $values) {
            $values = array_values($values);
            sort($values);
            $byconditionid[$conditionid] = $values;
        }
        $usedids = array_values($usedids);
        sort($usedids);
        $markervalues = array_values($markervalues);
        sort($markervalues);

        return [
            'inuse' => $inuse,
            'byconditionid' => $byconditionid,
            'usedids' => $usedids,
            'markervalues' => $markervalues,
        ];
    }
}
