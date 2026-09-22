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
 * Per rule configuration of the bulk send checker.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bulk_check;

use cache;
use stdClass;

/**
 * Configuration of the bulk send checker, one row per booking rule.
 *
 * A rule that has no row, or whose row is not enabled, is not bulk checked at all and its
 * mails are sent exactly as before. The rows are written from the rule form, which is the only
 * thing that should ever touch the table directly: every write goes through set_settings() so
 * that the cached copy can never go stale.
 *
 * The configuration deliberately lives outside booking_rules.rulejson. The rulejson is copied
 * into every queued send task and compared at run time, and any difference aborts the queued
 * mails of a days-before or specific-time rule. Ticking the checker on or off must not cancel
 * every reminder that rule has already scheduled.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_config {
    /** @var string */
    public const TABLENAME = 'booking_bulk_config';

    /** @var string The single key the whole table is cached under. */
    private const CACHEKEY = 'all';

    /**
     * @var int Default grace period between scheduling and the burst check.
     *
     * Two minutes, not the quarter of an hour local_taskflow uses: a booking rule queues its
     * whole burst in one loop of one process (the shutdown drain of the collected rules, or
     * the synchronous re-execution on saving a rule), so the delay only has to outlast that
     * loop plus one cron interval. See bulk_check::schedule_task() for what happens when a
     * burst takes longer than that.
     */
    public const DEFAULT_DELAY = 2 * MINSECS;

    /** @var int Default length of the counting window. */
    public const DEFAULT_PERIOD = HOURSECS;

    /** @var int Default number of sends per period that is still considered normal. */
    public const DEFAULT_LIMIT = 50;

    /** @var string[] The rule actions whose mails pass through the checker. */
    public const CHECKABLE_ACTIONS = ['send_mail', 'send_copy_of_mail'];

    /**
     * Whether the bulk send checker is switched on for this site at all.
     *
     * While it is off no sending is postponed, nothing is recorded and nothing is blocked,
     * so the rule mails behave exactly as they did before the checker existed. Switching it
     * off with a burst already queued lets that burst go out on its normal schedule.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('booking', 'bulkcheckenabled'));
    }

    /**
     * The whole configuration table, keyed by rule id.
     *
     * applies() runs once per recipient while a rule is queueing its mails, so this must
     * not be a query per recipient. The table holds one row per rule, a handful of rows in
     * practice, so a single read serves the entire run.
     *
     * @return array
     */
    private static function get_all(): array {
        global $DB;

        // The static acceleration of the cache is what keeps this cheap inside the loop.
        // Do not add a static property here as well: it would survive a cache purge, and
        // in the test runner it would survive the reset between two tests.
        $cache = cache::make('mod_booking', 'bulkcheckconfig');
        $configs = $cache->get(self::CACHEKEY);
        if ($configs === false) {
            $configs = $DB->get_records(
                self::TABLENAME,
                null,
                '',
                'ruleid, enabled, limitcount'
            );
            $cache->set(self::CACHEKEY, $configs);
        }

        return $configs;
    }

    /**
     * Drops the cached copy of the table.
     *
     * @return void
     */
    private static function invalidate(): void {
        cache::make('mod_booking', 'bulkcheckconfig')->delete(self::CACHEKEY);
    }

    /**
     * Returns the configuration entry of a rule, or null when it is not bulk checked.
     *
     * @param int $ruleid
     * @return array|null
     */
    public static function get_settings(int $ruleid): ?array {
        if (!self::is_enabled()) {
            return null;
        }

        $configs = self::get_all();
        if (empty($configs[$ruleid]) || empty($configs[$ruleid]->enabled)) {
            return null;
        }

        // Only the limit is set per rule. The window and the delay are the same for every
        // rule, so that the sending stays regular and nobody has to look up a different
        // value for each one.
        return [
            'limit' => (int) $configs[$ruleid]->limitcount,
            'period' => self::get_global_period(),
            'delay' => self::get_global_delay(),
        ];
    }

    /**
     * Whether the mails of the given rule have to pass the bulk check before they are sent.
     *
     * @param int $ruleid
     * @return bool
     */
    public static function applies(int $ruleid): bool {
        return self::get_settings($ruleid) !== null;
    }

    /**
     * Length of the counting window, the same for every rule.
     *
     * @return int
     */
    public static function get_global_period(): int {
        $period = (int) get_config('booking', 'bulkcheckperiod');
        return $period > 0 ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * How long sending is postponed, the same for every rule. Zero is allowed.
     *
     * @return int
     */
    public static function get_global_delay(): int {
        $delay = get_config('booking', 'bulkcheckdelay');
        return $delay === false || $delay === '' ? self::DEFAULT_DELAY : max(0, (int) $delay);
    }

    /**
     * Whether the bulk check can ever act on a rule with this action.
     *
     * Only the mail sending actions reach the check at all, so offering the setting on any
     * other action would store something that can never take effect.
     *
     * @param string|null $actionname
     * @return bool
     */
    public static function is_checkable_action(?string $actionname): bool {
        return in_array($actionname ?? '', self::CHECKABLE_ACTIONS, true);
    }

    /**
     * Returns the stored row of a rule, whether it is enabled or not.
     *
     * Used by the rule form, which has to show the numbers of a rule that is currently
     * switched off just as much as of one that is on.
     *
     * @param int $ruleid
     * @return stdClass|null
     */
    public static function get_record(int $ruleid): ?stdClass {
        $configs = self::get_all();
        return $configs[$ruleid] ?? null;
    }

    /**
     * Stores the configuration of one rule.
     *
     * @param int $ruleid
     * @param bool $enabled
     * @param int $limit
     * @return void
     */
    public static function set_settings(
        int $ruleid,
        bool $enabled,
        int $limit = self::DEFAULT_LIMIT
    ): void {
        global $DB, $USER;

        $now = time();
        $limit = max(1, $limit);
        $record = $DB->get_record(self::TABLENAME, ['ruleid' => $ruleid]);

        if (empty($record)) {
            $DB->insert_record(self::TABLENAME, (object) [
                'ruleid' => $ruleid,
                'enabled' => $enabled ? 1 : 0,
                'limitcount' => $limit,
                'usermodified' => $USER->id ?? 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            $DB->update_record(self::TABLENAME, (object) [
                'id' => $record->id,
                'enabled' => $enabled ? 1 : 0,
                'limitcount' => $limit,
                'usermodified' => $USER->id ?? 0,
                'timemodified' => $now,
            ]);
        }

        self::invalidate();
    }

    /**
     * Removes the configuration of a rule, for when the rule itself is deleted.
     *
     * @param int $ruleid
     * @return void
     */
    public static function delete_settings(int $ruleid): void {
        global $DB;
        $DB->delete_records(self::TABLENAME, ['ruleid' => $ruleid]);
        self::invalidate();
    }

    /**
     * How many sends of this rule are allowed within the period.
     *
     * @param int $ruleid
     * @return int
     */
    public static function get_limit(int $ruleid): int {
        $settings = self::get_settings($ruleid);
        return (int) ($settings['limit'] ?? self::DEFAULT_LIMIT);
    }

    /**
     * Users to inform about a blocked burst. Falls back to all site admins.
     *
     * @return array Array of user ids.
     */
    public static function get_notify_userids(): array {
        $userids = [];
        $setting = get_config('booking', 'bulkchecknotifyusers');
        if (!empty($setting)) {
            $userids = array_filter(array_map('intval', explode(',', $setting)));
        }
        if (empty($userids)) {
            $userids = array_keys(get_admins());
        }
        return array_values(array_unique(array_map('intval', $userids)));
    }
}
