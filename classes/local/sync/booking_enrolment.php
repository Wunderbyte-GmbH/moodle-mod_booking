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

namespace mod_booking\local\sync;

use cache_helper;
use mod_booking\bo_availability\bo_info;
use mod_booking\singleton_service;
use stdClass;

/**
 * Service class for cohort/group membership → booking option enrolment sync.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_enrolment {
    /** Respect availability conditions — skip blocked users. */
    const CONDITION_POLICY_RESPECT = 0;

    /** Override availability conditions — force enrol regardless. */
    const CONDITION_POLICY_OVERRIDE = 1;

    /** Sync action: enrol. */
    const ACTION_ENROL = 'enrol';

    /** Sync action: unenrol. */
    const ACTION_UNENROL = 'unenrol';

    /** Reason code: action completed successfully. */
    const REASON_OK = 'ok';

    /** Reason code: availability condition blocked the action. */
    const REASON_BLOCKED_CONDITION = 'blocked_condition';

    /** Reason code: booking is at capacity or closed state blocked the action. */
    const REASON_BLOCKED_CAPACITY = 'blocked_capacity';

    /** Reason code: booking option is invalid or not found. */
    const REASON_BLOCKED_INVALID = 'blocked_invalid';

    /** Reason code: user's booking answer is not owned by this sync rule (so safe-unenrol is prevented). */
    const REASON_BLOCKED_NOT_SYNC_OWNED = 'blocked_not_sync_owned';

    /** Reason code: user already has an active booking answer for this option. */
    const REASON_ALREADY_ENROLLED = 'already_enrolled';

    /** Delete mode: reset syncruleid to 0 — answer ownership becomes manual. */
    const DELETE_MODE_MANUALIZE = 'manualize';

    /** Delete mode: leave syncruleid unchanged on answers (orphan reference). */
    const DELETE_MODE_KEEP_ORPHAN = 'keeporphanruleid';

    /** Delete mode: unenrol affected users and soft-delete their booking answers. */
    const DELETE_MODE_UNENROL_SOFT_DELETE = 'unenrolsoftdelete';

    /**
     * Save or update sync rules derived from a subscription form submission.
     *
     * The form provides lists of cohorts and groups. For each selected cohort
     * and group we upsert a rule. If syncenabled is false the function is a
     * no-op and returns 0.
     *
     * @param int       $optionid   Booking option ID.
     * @param stdClass  $fromform   Submitted form data. Expected fields:
     *                              syncenabled (bool), syncenrolaction (bool),
     *                              syncunenrolaction (bool), syncconditionpolicy (int),
     *                              cohortids (array|int, optional), groupids (array|int, optional).
     * @return int Number of rules saved.
     */
    public static function save_rules_from_form(int $optionid, stdClass $fromform): int {
        global $DB, $USER;

        if (empty($fromform->syncenabled)) {
            return 0;
        }

        $now = time();
        $saved = 0;

        $sources = [];
        if (!empty($fromform->cohortids)) {
            foreach ((array)$fromform->cohortids as $cohortid) {
                if ($cohortid) {
                    $sources[] = ['cohort', (int)$cohortid];
                }
            }
        }
        if (!empty($fromform->groupids)) {
            foreach ((array)$fromform->groupids as $groupid) {
                if ($groupid) {
                    $sources[] = ['group', (int)$groupid];
                }
            }
        }

        foreach ($sources as [$sourcetype, $sourceid]) {
            $existing = $DB->get_record('booking_sync_rules', [
                'bookingoptionid' => $optionid,
                'sourcetype'      => $sourcetype,
                'sourceid'        => $sourceid,
            ]);

            if ($existing) {
                $existing->syncenrol        = empty($fromform->syncenrolaction) ? 0 : 1;
                $existing->syncunenrol      = empty($fromform->syncunenrolaction) ? 0 : 1;
                $existing->conditionpolicy  = (int)($fromform->syncconditionpolicy ?? self::CONDITION_POLICY_RESPECT);
                $existing->isenabled        = 1;
                $existing->timemodified     = $now;
                $existing->usermodified     = $USER->id;
                $DB->update_record('booking_sync_rules', $existing);
            } else {
                $rule = new stdClass();
                $rule->bookingoptionid  = $optionid;
                $rule->sourcetype       = $sourcetype;
                $rule->sourceid         = $sourceid;
                $rule->syncenrol        = empty($fromform->syncenrolaction) ? 0 : 1;
                $rule->syncunenrol      = empty($fromform->syncunenrolaction) ? 0 : 1;
                $rule->conditionpolicy  = (int)($fromform->syncconditionpolicy ?? self::CONDITION_POLICY_RESPECT);
                $rule->isenabled        = 1;
                $rule->timecreated      = $now;
                $rule->timemodified     = $now;
                $rule->usercreated      = $USER->id;
                $rule->usermodified     = $USER->id;
                $DB->insert_record('booking_sync_rules', $rule);
            }
            $saved++;
        }

        // Invalidate syncrules cache for this option after any mutations.
        if ($saved > 0) {
            cache_helper::purge_by_event('setbacksyncrules');
        }

        return $saved;
    }

    /**
     * Save or update one sync rule and return the saved rule id.
     *
     * @param int      $optionid Booking option ID.
     * @param stdClass $data     Rule payload.
     * @return int Saved rule id.
     */
    public static function save_single_rule(int $optionid, stdClass $data): int {
        global $DB, $USER;

        $sourcetype = (string)($data->sourcetype ?? '');
        $sourceid = (int)($data->sourceid ?? 0);
        if (!in_array($sourcetype, ['cohort', 'group']) || $sourceid <= 0) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $synccheckenrol = !empty($data->syncenrolaction);
        $synccheckunenrol = !empty($data->syncunenrolaction);
        if (!$synccheckenrol && !$synccheckunenrol) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        if (!self::source_exists($sourcetype, $sourceid)) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $now = time();

        $ruleid = (int)($data->ruleid ?? 0);
        if ($ruleid > 0) {
            $record = $DB->get_record('booking_sync_rules', ['id' => $ruleid, 'bookingoptionid' => $optionid]);
            if (!$record) {
                throw new \moodle_exception('invalidrecord', 'error');
            }

            $duplicate = $DB->get_record('booking_sync_rules', [
                'bookingoptionid' => $optionid,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ]);
            if (!empty($duplicate) && (int)$duplicate->id !== $ruleid) {
                throw new \moodle_exception('syncrulealreadyexists', 'mod_booking');
            }

            $record->sourcetype = $sourcetype;
            $record->sourceid = $sourceid;
            $record->syncenrol = $synccheckenrol ? 1 : 0;
            $record->syncunenrol = $synccheckunenrol ? 1 : 0;
            $record->conditionpolicy = (int)($data->syncconditionpolicy ?? self::CONDITION_POLICY_RESPECT);
            $record->isenabled = 1;
            $record->timemodified = $now;
            $record->usermodified = $USER->id;
            $DB->update_record('booking_sync_rules', $record);
            cache_helper::purge_by_event('setbacksyncrules');
            return (int)$record->id;
        }

        $existing = $DB->get_record('booking_sync_rules', [
            'bookingoptionid' => $optionid,
            'sourcetype'      => $sourcetype,
            'sourceid'        => $sourceid,
        ]);

        if ($existing) {
            $existing->syncenrol = $synccheckenrol ? 1 : 0;
            $existing->syncunenrol = $synccheckunenrol ? 1 : 0;
            $existing->conditionpolicy = (int)($data->syncconditionpolicy ?? self::CONDITION_POLICY_RESPECT);
            $existing->isenabled = 1;
            $existing->timemodified = $now;
            $existing->usermodified = $USER->id;
            $DB->update_record('booking_sync_rules', $existing);
            cache_helper::purge_by_event('setbacksyncrules');
            return (int)$existing->id;
        }

        $rule = new stdClass();
        $rule->bookingoptionid = $optionid;
        $rule->sourcetype = $sourcetype;
        $rule->sourceid = $sourceid;
        $rule->syncenrol = $synccheckenrol ? 1 : 0;
        $rule->syncunenrol = $synccheckunenrol ? 1 : 0;
        $rule->conditionpolicy = (int)($data->syncconditionpolicy ?? self::CONDITION_POLICY_RESPECT);
        $rule->isenabled = 1;
        $rule->timecreated = $now;
        $rule->timemodified = $now;
        $rule->usercreated = $USER->id;
        $rule->usermodified = $USER->id;
        $ruleid = (int)$DB->insert_record('booking_sync_rules', $rule);
        cache_helper::purge_by_event('setbacksyncrules');
        return $ruleid;
    }

    /**
     * Get one rule for an option including source labels.
     *
     * @param int $optionid Booking option id.
     * @param int $ruleid Rule id.
     * @return ?stdClass
     */
    public static function get_rule_for_option(int $optionid, int $ruleid): ?stdClass {
        global $DB;

        $rule = $DB->get_record('booking_sync_rules', [
            'id' => $ruleid,
            'bookingoptionid' => $optionid,
        ]);
        if (!$rule) {
            return null;
        }

        if ($rule->sourcetype === 'cohort') {
            $rule->sourcename = $DB->get_field('cohort', 'name', ['id' => $rule->sourceid]) ?: '?';
            $rule->sourcetypelabel = get_string('syncsourcetypecohort', 'mod_booking');
        } else {
            $rule->sourcename = $DB->get_field('groups', 'name', ['id' => $rule->sourceid]) ?: '?';
            $rule->sourcetypelabel = get_string('syncsourcetypegroup', 'mod_booking');
        }

        return $rule;
    }

    /**
     * Delete a rule and handle existing rule-owned booking answers according to the chosen mode.
     *
     * Modes:
     *  - DELETE_MODE_MANUALIZE      : reset syncruleid=0 on answers, keep status unchanged.
     *  - DELETE_MODE_KEEP_ORPHAN    : leave answers completely untouched (orphan rule id).
     *  - DELETE_MODE_UNENROL_SOFT_DELETE : unenrol each affected user; user_delete_response() soft-
     *                                     deletes the answer (waitinglist=DELETED) and writes
     *                                     booking_history automatically.
     *
     * booking_sync_attempts rows are never touched.
     *
     * @param int    $optionid Booking option id.
     * @param int    $ruleid   Rule id.
     * @param string $mode     One of the DELETE_MODE_* constants.
     * @return array{affected:int}
     */
    public static function delete_rule(
        int $optionid,
        int $ruleid,
        string $mode = self::DELETE_MODE_MANUALIZE
    ): array {
        global $DB, $USER;

        $rule = $DB->get_record('booking_sync_rules', [
            'id' => $ruleid,
            'bookingoptionid' => $optionid,
        ]);
        if (!$rule) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $answers = $DB->get_records(
            'booking_answers',
            ['optionid' => $optionid, 'syncruleid' => $ruleid],
            '',
            'id,userid,waitinglist,bookingid,syncruleid'
        );

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        switch ($mode) {
            case self::DELETE_MODE_MANUALIZE:
                foreach ($answers as $answer) {
                    $update = new \stdClass();
                    $update->id = $answer->id;
                    $update->syncruleid = 0;
                    $update->timemodified = $now;
                    $DB->update_record('booking_answers', $update);
                    \mod_booking\booking_option::booking_history_insert(
                        (int)$answer->waitinglist,
                        (int)$answer->id,
                        $optionid,
                        (int)$answer->bookingid,
                        (int)$answer->userid,
                        ['syncruleid' => $ruleid, 'syncaction' => 'rule_deleted_manualize']
                    );
                }
                break;

            case self::DELETE_MODE_KEEP_ORPHAN:
                foreach ($answers as $answer) {
                    \mod_booking\booking_option::booking_history_insert(
                        (int)$answer->waitinglist,
                        (int)$answer->id,
                        $optionid,
                        (int)$answer->bookingid,
                        (int)$answer->userid,
                        ['syncruleid' => $ruleid, 'syncaction' => 'rule_deleted_orphan']
                    );
                }
                break;

            case self::DELETE_MODE_UNENROL_SOFT_DELETE:
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                if ($settings && !empty($settings->cmid)) {
                    $option = singleton_service::get_instance_of_booking_option(
                        (int)$settings->cmid,
                        $optionid
                    );
                    if ($option) {
                        foreach ($answers as $answer) {
                            // User_delete_response() soft-deletes (waitinglist=DELETED, row kept)
                            // and writes booking_history automatically.
                            $option->user_delete_response((int)$answer->userid);
                            self::log_attempt(
                                $ruleid,
                                $optionid,
                                (int)$answer->userid,
                                self::ACTION_UNENROL,
                                self::REASON_OK,
                                'Soft-deleted via rule deletion mode unenrolsoftdelete'
                            );
                        }
                    }
                }
                break;
        }

        $DB->delete_records('booking_sync_rules', ['id' => $ruleid]);
        $transaction->allow_commit();

        // Invalidate syncrules cache after deletion.
        cache_helper::purge_by_event('setbacksyncrules');

        return ['affected' => count($answers)];
    }

    /**
     * Apply one rule against current source membership.
     *
     * @param int $ruleid Sync rule id.
     * @return array{enrolattempted:int, unenrolattempted:int}
     */
    public static function apply_rule_to_current_members(int $ruleid): array {
        global $DB;

        $rule = $DB->get_record('booking_sync_rules', ['id' => $ruleid, 'isenabled' => 1]);
        if (!$rule) {
            return ['enrolattempted' => 0, 'unenrolattempted' => 0];
        }

        $memberids = self::get_source_member_ids((string)$rule->sourcetype, (int)$rule->sourceid);
        $membermap = array_fill_keys($memberids, true);

        $enrolattempted = 0;
        $unenrolattempted = 0;

        if (!empty($rule->syncenrol)) {
            // Batch fetch all users to avoid N queries in the loop.
            $userrecords = [];
            if (!empty($memberids)) {
                $userrecords = $DB->get_records_list(
                    'user',
                    'id',
                    array_unique($memberids),
                    '',
                    'id,username,email,firstname,lastname,deleted'
                );
            }

            foreach ($memberids as $userid) {
                $enrolattempted++;
                // Use the internal helper with cached user record to avoid per-user fetch.
                $user = $userrecords[(int)$userid] ?? null;
                self::_enrol_user_by_rule_with_user_cache($rule, (int)$userid, $user);
            }
        }

        if (!empty($rule->syncunenrol)) {
            $ownedanswers = $DB->get_records('booking_answers', [
                'optionid' => (int)$rule->bookingoptionid,
                'syncruleid' => (int)$rule->id,
            ], '', 'id,userid');

            foreach ($ownedanswers as $answer) {
                if (!isset($membermap[(int)$answer->userid])) {
                    $unenrolattempted++;
                    self::unenrol_user_by_rule($rule, (int)$answer->userid);
                }
            }
        }

        return ['enrolattempted' => $enrolattempted, 'unenrolattempted' => $unenrolattempted];
    }

    /**
     * Check if a sync source exists.
     *
     * @param string $sourcetype Source type.
     * @param int $sourceid Source id.
     * @return bool
     */
    public static function source_exists(string $sourcetype, int $sourceid): bool {
        global $DB;

        if ($sourcetype === 'cohort') {
            return $DB->record_exists('cohort', ['id' => $sourceid]);
        }

        if ($sourcetype === 'group') {
            return $DB->record_exists('groups', ['id' => $sourceid]);
        }

        return false;
    }

    /**
     * Return all member user ids for one source.
     *
     * @param string $sourcetype Source type.
     * @param int $sourceid Source id.
     * @return int[]
     */
    public static function get_source_member_ids(string $sourcetype, int $sourceid): array {
        global $DB;

        if ($sourcetype === 'cohort') {
            $ids = $DB->get_fieldset_select('cohort_members', 'userid', 'cohortid = :sourceid', ['sourceid' => $sourceid]);
            return array_map('intval', $ids);
        }

        if ($sourcetype === 'group') {
            $ids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :sourceid', ['sourceid' => $sourceid]);
            return array_map('intval', $ids);
        }

        return [];
    }

    /**
     * Return all sync rules for a given booking option, enriched with source names.
     *
     * @param int $optionid Booking option ID.
     * @return stdClass[] Array of rule records. Each record has extra fields:
     *                    sourcename (string), sourcetypelabel (string).
     */
    public static function get_rules_for_option(int $optionid): array {
        global $DB;

        // Try to get cached rules with preloaded source names.
        $cache = \cache::make('mod_booking', 'syncrules');
        $cachekey = 'opt_' . $optionid;
        $rules = $cache->get($cachekey);

        if ($rules === false) {
            // Cache miss: fetch and enrich rules.
            $rules = $DB->get_records('booking_sync_rules', ['bookingoptionid' => $optionid], 'timecreated ASC');

            if (!empty($rules)) {
                // Batch fetch all cohort and group names instead of N+1 queries.
                $cohortids = [];
                $groupids = [];

                foreach ($rules as $rule) {
                    if ($rule->sourcetype === 'cohort') {
                        $cohortids[] = (int)$rule->sourceid;
                    } else if ($rule->sourcetype === 'group') {
                        $groupids[] = (int)$rule->sourceid;
                    }
                }

                // Fetch all cohorts and groups in bulk.
                $cohorts = [];
                $groups = [];

                if (!empty($cohortids)) {
                    $cohortrecords = $DB->get_records_list('cohort', 'id', array_unique($cohortids), '', 'id,name');
                    foreach ($cohortrecords as $c) {
                        $cohorts[(int)$c->id] = $c->name;
                    }
                }

                if (!empty($groupids)) {
                    $grouprecords = $DB->get_records_list('groups', 'id', array_unique($groupids), '', 'id,name');
                    foreach ($grouprecords as $g) {
                        $groups[(int)$g->id] = $g->name;
                    }
                }

                // Enrich rules with source names.
                foreach ($rules as $rule) {
                    if ($rule->sourcetype === 'cohort') {
                        $rule->sourcename = $cohorts[(int)$rule->sourceid] ?? '?';
                        $rule->sourcetypelabel = get_string('syncsourcetypecohort', 'mod_booking');
                    } else if ($rule->sourcetype === 'group') {
                        $rule->sourcename = $groups[(int)$rule->sourceid] ?? '?';
                        $rule->sourcetypelabel = get_string('syncsourcetypegroup', 'mod_booking');
                    }
                }
            }

            // Cache the enriched rules.
            $cache->set($cachekey, $rules);
        }

        return array_values($rules);
    }

    /**
     * Update individual settings of a sync rule.
     *
     * @param int   $ruleid  Rule ID.
     * @param array $updates Associative array of fields to update.
     * @return bool True on success.
     */
    public static function update_rule_settings(int $ruleid, array $updates): bool {
        global $DB, $USER;

        $record = $DB->get_record('booking_sync_rules', ['id' => $ruleid]);
        if (!$record) {
            return false;
        }

        $allowed = ['syncenrol', 'syncunenrol', 'conditionpolicy', 'isenabled'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $updates)) {
                $record->$field = $updates[$field];
            }
        }
        $record->timemodified = time();
        $record->usermodified = $USER->id;
        $DB->update_record('booking_sync_rules', $record);
        cache_helper::purge_by_event('setbacksyncrules');
        return true;
    }

    /**
     * Activate one sync rule and optionally apply it to current source membership immediately.
     *
     * @param int $optionid Booking option ID.
     * @param int $ruleid Rule ID.
     * @param bool $retroactive True to process current source members immediately.
     * @return array{enrolattempted:int, unenrolattempted:int}
     */
    public static function activate_rule(int $optionid, int $ruleid, bool $retroactive = false): array {
        global $DB;

        $rule = $DB->get_record('booking_sync_rules', [
            'id' => $ruleid,
            'bookingoptionid' => $optionid,
        ]);
        if (!$rule) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        self::update_rule_settings($ruleid, ['isenabled' => 1]);

        if (!$retroactive) {
            return ['enrolattempted' => 0, 'unenrolattempted' => 0];
        }

        return self::apply_rule_to_current_members($ruleid);
    }

    /**
     * Disable all sync rules for a booking option.
     *
     * @param int $optionid Booking option ID.
     * @return int Number of rules disabled.
     */
    public static function disable_rules_for_option(int $optionid): int {
        global $DB, $USER;

        $rules = $DB->get_records('booking_sync_rules', ['bookingoptionid' => $optionid, 'isenabled' => 1]);
        $now = time();
        foreach ($rules as $rule) {
            $rule->isenabled    = 0;
            $rule->timemodified = $now;
            $rule->usermodified = $USER->id;
            $DB->update_record('booking_sync_rules', $rule);
        }

        // Invalidate syncrules cache after disabling rules.
        if (!empty($rules)) {
            cache_helper::purge_by_event('setbacksyncrules');
        }

        return count($rules);
    }

    /**
     * Handle a cohort or group membership change event and trigger sync for all matching rules.
     *
     * @param string $sourcetype     'cohort' or 'group'.
     * @param int    $sourceid       Cohort or group ID.
     * @param int    $userid         User whose membership changed.
     * @param bool   $membershipadded True if user was added, false if removed.
     */
    public static function process_source_membership(
        string $sourcetype,
        int $sourceid,
        int $userid,
        bool $membershipadded
    ): void {
        global $DB;

        $rules = $DB->get_records('booking_sync_rules', [
            'sourcetype' => $sourcetype,
            'sourceid'   => $sourceid,
            'isenabled'  => 1,
        ]);

        foreach ($rules as $rule) {
            if ($membershipadded) {
                if ($rule->syncenrol) {
                    self::enrol_user_by_rule($rule, $userid);
                }
            } else {
                if ($rule->syncunenrol) {
                    self::unenrol_user_by_rule($rule, $userid);
                }
            }
        }
    }

    /**
     * Queue one source membership sync operation to run asynchronously.
     *
     * This keeps expensive booking sync work off the original cohort/group API request.
     *
     * @param string $sourcetype 'cohort' or 'group'.
     * @param int $sourceid Source id.
     * @param int $userid User id whose membership changed.
     * @param bool $membershipadded True if added, false if removed.
     * @return void
     */
    public static function queue_source_membership_sync(
        string $sourcetype,
        int $sourceid,
        int $userid,
        bool $membershipadded
    ): void {
        if (!in_array($sourcetype, ['cohort', 'group'], true) || $sourceid <= 0 || $userid <= 0) {
            return;
        }

        $task = new \mod_booking\task\process_source_membership_adhoc();
        $task->set_component('mod_booking');
        $task->set_custom_data((object)[
            'sourcetype' => $sourcetype,
            'sourceid' => $sourceid,
            'userid' => $userid,
            'membershipadded' => $membershipadded ? 1 : 0,
        ]);
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Enrol a user into a booking option according to a sync rule.
     *
     * @param stdClass $rule   Sync rule record from booking_sync_rules.
     * @param int      $userid User to enrol.
     */
    public static function enrol_user_by_rule(stdClass $rule, int $userid): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
        if (!$settings || empty($settings->cmid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'No valid cmid for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'User not found: ' . $userid
            );
            return;
        }

        $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$rule->bookingoptionid);
        if (!$option) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'Could not load booking_option for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        if (self::has_active_booking_answer((int)$rule->bookingoptionid, $userid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_ALREADY_ENROLLED,
                'User already has an active booking answer for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        if ((int)$rule->conditionpolicy === self::CONDITION_POLICY_OVERRIDE) {
            // Override mode: force book regardless of conditions or capacity.
            $result = $option->user_submit_response(
                $user,
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                MOD_BOOKING_VERIFIED,
                '',
                0,
                false,
                (int)$rule->id
            );
        } else {
            // Respect mode: check availability first.
            $isavailable = \mod_booking\booking_option::option_allows_booking_for_user((int)$rule->bookingoptionid, $userid);
            if (!$isavailable) {
                $reasoncode = self::REASON_BLOCKED_CONDITION;
                $reasonmessage = 'Availability condition blocked enrolment';

                $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
                if (!empty($settings)) {
                    $boinfo = new bo_info($settings);
                    $results = bo_info::get_condition_results((int)$settings->id, $userid, true);
                    [$conditionid, $availability, $description] = $boinfo->is_available((int)$settings->id, $userid, true);

                    if (!$availability) {
                        $blockingids = array_values(array_unique(array_map('intval', array_keys($results))));
                        sort($blockingids, SORT_NUMERIC);

                        if (!empty($blockingids)) {
                            $idscsv = implode(',', $blockingids);
                            $reasoncode = self::REASON_BLOCKED_CONDITION . '_' . $idscsv;
                            $reasoncode = substr($reasoncode, 0, 50);
                            $reasonmessage = 'Condition ids [' . $idscsv . ']';
                        } else if (!empty($conditionid)) {
                            $reasoncode = self::REASON_BLOCKED_CONDITION . '_' . (int)$conditionid;
                            $reasonmessage = 'Condition id ' . (int)$conditionid;
                        }

                        if (!empty($description)) {
                            $reasonmessage .= ': ' . trim(strip_tags((string)$description));
                        }
                    }
                }

                self::log_attempt(
                    (int)$rule->id,
                    (int)$rule->bookingoptionid,
                    $userid,
                    self::ACTION_ENROL,
                    $reasoncode,
                    $reasonmessage
                );
                return;
            }
            $result = $option->user_submit_response(
                $user,
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                MOD_BOOKING_VERIFIED,
                '',
                0,
                false,
                (int)$rule->id
            );
        }

        if (!$result) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_CAPACITY,
                'user_submit_response returned false'
            );
        } else {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_OK
            );
            // Write sync-origin entry to booking_history so the operation is auditable.
            global $DB;
            $bookedanswer = $DB->get_record(
                'booking_answers',
                ['optionid' => (int)$rule->bookingoptionid, 'userid' => $userid, 'syncruleid' => (int)$rule->id],
                'id,bookingid,waitinglist',
                IGNORE_MULTIPLE
            );
            if ($bookedanswer) {
                \mod_booking\booking_option::booking_history_insert(
                    (int)$bookedanswer->waitinglist,
                    (int)$bookedanswer->id,
                    (int)$rule->bookingoptionid,
                    (int)$bookedanswer->bookingid,
                    $userid,
                    ['syncruleid' => (int)$rule->id, 'syncaction' => self::ACTION_ENROL]
                );
            }
        }
    }

    /**
     * Unenrol a user from a booking option if their booking answer is owned by the given rule.
     *
     * @param stdClass $rule   Sync rule record.
     * @param int      $userid User to unenrol.
     */
    public static function unenrol_user_by_rule(stdClass $rule, int $userid): void {
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
        if (!$settings || empty($settings->cmid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_UNENROL,
                self::REASON_BLOCKED_INVALID,
                'No valid cmid for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        if (!self::is_sync_owned_by_rule($rule, $userid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_UNENROL,
                self::REASON_BLOCKED_NOT_SYNC_OWNED,
                'Booking answer not owned by rule id ' . $rule->id
            );
            return;
        }

        $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$rule->bookingoptionid);
        if (!$option) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_UNENROL,
                self::REASON_BLOCKED_INVALID,
                'Could not load booking_option for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        $option->user_delete_response($userid);

        // Write sync-origin entry to booking_history. user_delete_response() already wrote one
        // entry with the standard delete status; we add a second with syncaction metadata so the
        // rule id is traceable in history queries.
        global $DB;
        $deletedanswer = $DB->get_record_sql(
            "SELECT id, bookingid FROM {booking_answers}
              WHERE optionid = :optionid AND userid = :userid AND waitinglist = :status",
            [
                'optionid' => (int)$rule->bookingoptionid,
                'userid'   => $userid,
                'status'   => MOD_BOOKING_STATUSPARAM_DELETED,
            ],
            IGNORE_MULTIPLE
        );
        if ($deletedanswer) {
            \mod_booking\booking_option::booking_history_insert(
                MOD_BOOKING_STATUSPARAM_DELETED,
                (int)$deletedanswer->id,
                (int)$rule->bookingoptionid,
                (int)$deletedanswer->bookingid,
                $userid,
                ['syncruleid' => (int)$rule->id, 'syncaction' => self::ACTION_UNENROL]
            );
        }

        self::log_attempt(
            (int)$rule->id,
            (int)$rule->bookingoptionid,
            $userid,
            self::ACTION_UNENROL,
            self::REASON_OK
        );
    }

    /**
     * Internal helper: enrol a user by rule with a pre-fetched user object.
     * Use this in loops to avoid redundant user record fetches.
     *
     * @param stdClass $rule   Sync rule record.
     * @param int      $userid User ID.
     * @param stdClass $user   Pre-fetched user record (optional). If provided, avoids a DB query.
     */
    public static function _enrol_user_by_rule_with_user_cache(stdClass $rule, int $userid, ?stdClass $user = null): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
        if (!$settings || empty($settings->cmid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'No valid cmid for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        // Use provided user object if available; otherwise fetch it.
        if ($user === null) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        }
        if (!$user) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'User not found: ' . $userid
            );
            return;
        }

        $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$rule->bookingoptionid);
        if (!$option) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_INVALID,
                'Could not load booking_option for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        if (self::has_active_booking_answer((int)$rule->bookingoptionid, $userid)) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_ALREADY_ENROLLED,
                'User already has an active booking answer for optionid ' . $rule->bookingoptionid
            );
            return;
        }

        if ((int)$rule->conditionpolicy === self::CONDITION_POLICY_OVERRIDE) {
            // Override mode: force book regardless of conditions or capacity.
            $result = $option->user_submit_response(
                $user,
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                MOD_BOOKING_VERIFIED,
                '',
                0,
                false,
                (int)$rule->id
            );
        } else {
            // Respect mode: check availability first.
            $isavailable = \mod_booking\booking_option::option_allows_booking_for_user((int)$rule->bookingoptionid, $userid);
            if (!$isavailable) {
                $reasoncode = self::REASON_BLOCKED_CONDITION;
                $reasonmessage = 'Availability condition blocked enrolment';

                $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
                if (!empty($settings)) {
                    $boinfo = new bo_info($settings);
                    $results = bo_info::get_condition_results((int)$settings->id, $userid, true);
                    [$conditionid, $availability, $description] = $boinfo->is_available((int)$settings->id, $userid, true);

                    if (!$availability) {
                        $blockingids = array_values(array_unique(array_map('intval', array_keys($results))));
                        sort($blockingids, SORT_NUMERIC);

                        if (!empty($blockingids)) {
                            $idscsv = implode(',', $blockingids);
                            $reasoncode = self::REASON_BLOCKED_CONDITION . '_' . $idscsv;
                            $reasoncode = substr($reasoncode, 0, 50);
                            $reasonmessage = 'Condition ids [' . $idscsv . ']';
                        } else if (!empty($conditionid)) {
                            $reasoncode = self::REASON_BLOCKED_CONDITION . '_' . (int)$conditionid;
                            $reasonmessage = 'Condition id ' . (int)$conditionid;
                        }

                        if (!empty($description)) {
                            $reasonmessage .= ': ' . trim(strip_tags((string)$description));
                        }
                    }
                }

                self::log_attempt(
                    (int)$rule->id,
                    (int)$rule->bookingoptionid,
                    $userid,
                    self::ACTION_ENROL,
                    $reasoncode,
                    $reasonmessage
                );
                return;
            }
            $result = $option->user_submit_response(
                $user,
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                MOD_BOOKING_VERIFIED,
                '',
                0,
                false,
                (int)$rule->id
            );
        }

        if (!$result) {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_BLOCKED_CAPACITY,
                'user_submit_response returned false'
            );
        } else {
            self::log_attempt(
                (int)$rule->id,
                (int)$rule->bookingoptionid,
                $userid,
                self::ACTION_ENROL,
                self::REASON_OK
            );
            // Write sync-origin entry to booking_history so the operation is auditable.
            global $DB;
            $bookedanswer = $DB->get_record(
                'booking_answers',
                ['optionid' => (int)$rule->bookingoptionid, 'userid' => $userid, 'syncruleid' => (int)$rule->id],
                'id,bookingid,waitinglist',
                IGNORE_MULTIPLE
            );
            if ($bookedanswer) {
                \mod_booking\booking_option::booking_history_insert(
                    (int)$bookedanswer->waitinglist,
                    (int)$bookedanswer->id,
                    (int)$rule->bookingoptionid,
                    (int)$bookedanswer->bookingid,
                    $userid,
                    ['syncruleid' => (int)$rule->id, 'syncaction' => self::ACTION_ENROL]
                );
            }
        }
    }

    /**
     * Check whether a user's booking answer for a given option is owned by the given sync rule.
     *
     * @param stdClass $rule   Sync rule record.
     * @param int      $userid User ID.
     * @return bool True if the answer exists and is owned by this rule.
     */
    public static function is_sync_owned_by_rule(stdClass $rule, int $userid): bool {
        global $DB;

        $answer = $DB->get_record('booking_answers', [
            'optionid'    => (int)$rule->bookingoptionid,
            'userid'      => $userid,
            'syncruleid'  => (int)$rule->id,
        ]);

        return !empty($answer);
    }

    /**
     * Check whether a user already has an active booking answer for a given option.
     *
     * Active means booked, waiting list, or reserved. Deleted and previously-booked rows are ignored.
     *
     * @param int $optionid Booking option id.
     * @param int $userid User id.
     * @return bool
     */
    public static function has_active_booking_answer(int $optionid, int $userid): bool {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
        ], SQL_PARAMS_NAMED, 'status');

        return $DB->record_exists_select(
            'booking_answers',
            "optionid = :optionid AND userid = :userid AND waitinglist {$insql}",
            ['optionid' => $optionid, 'userid' => $userid] + $params
        );
    }

    /**
     * Insert a log entry into booking_sync_attempts.
     *
     * @param int    $syncruleid    Sync rule ID.
     * @param int    $bookingoptionid Booking option ID.
     * @param int    $userid        User ID.
     * @param string $action        'enrol' or 'unenrol'.
     * @param string $reasoncode    One of the REASON_* constants.
     * @param string $msg           Optional message.
     */
    public static function log_attempt(
        int $syncruleid,
        int $bookingoptionid,
        int $userid,
        string $action,
        string $reasoncode,
        string $msg = ''
    ): void {
        global $DB;

        $record = new stdClass();
        $record->syncruleid       = $syncruleid;
        $record->bookingoptionid  = $bookingoptionid;
        $record->userid           = $userid;
        $record->action           = $action;
        $record->reasoncode       = $reasoncode;
        $record->reasonmessage    = $msg;
        $record->timecreated      = time();
        $DB->insert_record('booking_sync_attempts', $record);
    }

    /**
     * Get recent sync attempt records for a booking option.
     *
     * @param int $optionid Booking option ID.
     * @param int $limit    Maximum number of records to return.
     * @return stdClass[] Array of attempt records, newest first.
     */
    public static function get_recent_attempts_for_option(int $optionid, int $limit = 20): array {
        global $DB;

        $sql = "SELECT a.*, u.firstname, u.lastname, u.email,
                       r.id AS ruleid, r.sourcetype, r.sourceid,
                       c.name AS cohortname, g.name AS groupname
                  FROM {booking_sync_attempts} a
                  JOIN {user} u ON u.id = a.userid
             LEFT JOIN {booking_sync_rules} r ON r.id = a.syncruleid
             LEFT JOIN {cohort} c ON c.id = r.sourceid AND r.sourcetype = 'cohort'
             LEFT JOIN {groups} g ON g.id = r.sourceid AND r.sourcetype = 'group'
                 WHERE a.bookingoptionid = :optionid
                 ORDER BY a.timecreated DESC";
        $attempts = array_values($DB->get_records_sql($sql, ['optionid' => $optionid], 0, $limit));

        foreach ($attempts as $attempt) {
            $ruleref = '#' . (int)$attempt->syncruleid;
            if (empty($attempt->ruleid)) {
                $attempt->rulesource = $ruleref;
                continue;
            }

            if ($attempt->sourcetype === 'cohort') {
                $attempt->rulesource = $ruleref . ' - '
                    . get_string('syncsourcetypecohort', 'mod_booking') . ': '
                    . ($attempt->cohortname ?? '?');
            } else if ($attempt->sourcetype === 'group') {
                $attempt->rulesource = $ruleref . ' - '
                    . get_string('syncsourcetypegroup', 'mod_booking') . ': '
                    . ($attempt->groupname ?? '?');
            } else {
                $attempt->rulesource = $ruleref;
            }
        }

        return $attempts;
    }
}
