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
 * Bulk send checker for rule mails.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bulk_check;

use cache_helper;
use context_system;
use core\lock\lock_config;
use core\message\message;
use core\task\manager;
use core_user;
use html_writer;
use mod_booking\event\bulk_check_blocked;
use mod_booking\event\bulk_check_dismissed;
use mod_booking\event\bulk_check_released;
use mod_booking\local\scheduledmails;
use mod_booking\task\release_bulk_check;
use mod_booking\task\send_mail_by_rule_adhoc;
use moodle_url;
use stdClass;

/**
 * Detects and parks bulk sends of a booking rule.
 *
 * The mails of a bulk checked rule are not sent straight away. Their sending is postponed by
 * a configurable delay, and a row is written here for every send that is queued. When the
 * postponed task finally runs it counts the sends of the same rule around its own sending
 * time. If that count is over the configured limit the send is not carried out, the row is
 * parked as blocked and the configured users are informed once per burst.
 *
 * The counting window is anchored on the sending time, not on the time the row was written.
 * A reminder that is queued today for a date in a week would otherwise never be counted.
 *
 * Unlike local_taskflow, booking has no table of sent messages to count from, so a row is not
 * deleted when its mail goes out: it turns into a sent row that keeps counting for a while and
 * is purged by the cleanup once it has fallen out of every window it could be part of.
 *
 * The burst is keyed on the rule alone, not on the rule and the option. The floods worth
 * catching in booking are the ones that run across options: a bulk edit that fires a change
 * notification for every booked user of every touched option, or a rule whose filter was
 * widened. Counted per option, every one of those would stay under the limit.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check {
    /** @var string */
    public const TABLENAME = 'booking_bulk_check';

    /** @var int The send is queued and has not been decided yet. */
    public const STATUS_PENDING = 0;

    /** @var int The mail went out. The row keeps counting until the cleanup purges it. */
    public const STATUS_SENT = 1;

    /** @var int The send was blocked and waits for a manual release. */
    public const STATUS_BLOCKED = 2;

    /** @var int The send was released manually and requeued. */
    public const STATUS_RELEASED = 3;

    /**
     * @var int The mail went out after it was released by hand. Kept apart from STATUS_SENT
     * because a person already decided about it: it must not count against the next sends of
     * its rule, or every release would block the rule again for half a period.
     */
    public const STATUS_SENTRELEASED = 4;

    /** @var int The send was let through by hand and waits for the release task. */
    public const STATUS_RELEASING = 6;

    /** @var int How many ids of a hand picked selection are dealt with in one statement. */
    public const IDCHUNK = 500;

    /** @var int How many rows one batch of the cleanup deletes. */
    public const CLEANUPBATCH = 1000;

    /** @var int How long a releasing row may wait before the cleanup gives it its task back. */
    public const STALERELEASE = 15 * MINSECS;

    /** @var int Verdict: the task may send. */
    public const SEND = 0;

    /** @var int Verdict: the task must not send. */
    public const BLOCKED = 1;

    /** @var string The lock factory type every lock of the checker is taken from. */
    public const LOCKTYPE = 'mod_booking_bulk_check';

    /** @var int How many row ids an audit event carries at most. */
    private const EVENTIDS = 500;

    /** @var int How many seconds a worker waits for another one that sweeps the same rule. */
    private const SWEEPLOCKWAIT = 10;

    /**
     * Whether the mails of the given rule have to pass the bulk check.
     *
     * @param int $ruleid
     * @return bool
     */
    public static function applies(int $ruleid): bool {
        return bulk_check_config::applies($ruleid);
    }

    /**
     * Queues a send task of a rule action and records it when the rule is bulk checked.
     *
     * This is the one place both mail actions hand their task to. When the rule is not
     * checked the task is queued through the very same core call as before and nothing else
     * happens: that is the bypass, and it has to be a real one.
     *
     * When it is checked, the run time is postponed so that the whole burst is in the queue
     * before the first of its tasks decides whether it may go out. Only a send that is due
     * within the delay is postponed at all: a reminder that is scheduled days ahead has its
     * burst queued long before it runs, and shifting it would gain nothing. The original
     * time is kept on the row, because the days-before and specific-time rules re-validate a
     * task by demanding exactly that time back.
     *
     * The delay covers the loop that queues the burst plus one cron interval. A burst that
     * takes longer to queue than that, or that is spread over several processes, is not lost:
     * sent rows count too, so once the count passes the limit every later task blocks. At
     * most the limit leaks. That is also what a delay of zero means.
     *
     * @param send_mail_by_rule_adhoc $task With its custom data and user already set.
     * @param int $ruleid
     * @param int $optionid
     * @param int $userid
     * @param int $sendtime The run time the rule computed.
     * @return void
     */
    public static function schedule_task(
        send_mail_by_rule_adhoc $task,
        int $ruleid,
        int $optionid,
        int $userid,
        int $sendtime
    ): void {
        global $DB;

        if (!self::applies($ruleid)) {
            $task->set_next_run_time($sendtime);
            manager::reschedule_or_queue_adhoc_task($task);
            return;
        }

        $runtime = max($sendtime, time() + bulk_check_config::get_global_delay());
        $task->set_next_run_time($runtime);

        // One lookup only. Core's own reschedule_or_queue_adhoc_task() does not hand the id
        // back, and its lookup scans task_adhoc with a text compare on the custom data, so
        // calling it and then looking the task up again would double the time the loop that
        // queues a burst takes - the very time the delay has to cover.
        $existing = self::get_queued_task_record($task);
        if (!empty($existing)) {
            if ((int) $existing->nextruntime !== $runtime) {
                $DB->set_field('task_adhoc', 'nextruntime', $runtime, ['id' => $existing->id]);
            }
            $taskid = (int) $existing->id;
        } else {
            $taskid = (int) manager::queue_adhoc_task($task);
        }
        if (empty($taskid)) {
            return;
        }

        self::record_scheduled($ruleid, $optionid, $userid, $taskid, $sendtime, $runtime);
    }

    /**
     * The queued task with the same class, component, custom data and user, if there is one.
     *
     * The same lookup as manager::get_queued_adhoc_task_record($task, false) in Moodle 5.0 and
     * later. Moodle 4.5 has that method only as protected and without the $includefailed flag,
     * so it is rebuilt here from the public record_from_adhoc_task().
     *
     * @param send_mail_by_rule_adhoc $task
     * @return stdClass|false
     */
    private static function get_queued_task_record(send_mail_by_rule_adhoc $task) {
        global $DB;

        $record = manager::record_from_adhoc_task($task);
        $params = [$record->classname, $record->component, $record->customdata];
        $sql = 'classname = ? AND component = ? AND ' .
            $DB->sql_compare_text('customdata', \core_text::strlen($record->customdata) + 1) . ' = ?';

        if ($record->userid) {
            $params[] = $record->userid;
            $sql .= ' AND userid = ?';
        }

        // Tasks that failed and will not be retried do not count as queued.
        $sql .= ' AND (attemptsavailable > 0 OR attemptsavailable IS NULL)';

        $queuedtasks = $DB->get_records_select('task_adhoc', $sql, $params, 'timecreated DESC, id DESC', '*', 0, 1);
        return reset($queuedtasks);
    }

    /**
     * Records that a send of a bulk checked rule was queued.
     *
     * The row is keyed on the task. A rule that runs again for the same recipient reuses the
     * task core already holds for the same custom data, and then this updates the row instead
     * of adding a second one. There is deliberately no dropping of other pending rows of the
     * same user and rule: an event rule can legitimately queue several tasks for one user and
     * option, and a task that a rule edit made obsolete aborts when it runs and deletes its
     * own row.
     *
     * @param int $ruleid
     * @param int $optionid
     * @param int $userid
     * @param int $taskid The task_adhoc record this row belongs to.
     * @param int $sendtime The run time the rule computed, before the delay.
     * @param int $scheduledtime When the task actually runs.
     * @return int The id of the row.
     */
    public static function record_scheduled(
        int $ruleid,
        int $optionid,
        int $userid,
        int $taskid,
        int $sendtime,
        int $scheduledtime
    ): int {
        global $DB;
        $now = time();

        $row = self::get_row_by_task($taskid);
        if (!empty($row)) {
            if ((int) $row->status === self::STATUS_PENDING) {
                $DB->update_record(self::TABLENAME, (object) [
                    'id' => $row->id,
                    'optionid' => $optionid,
                    'sendtime' => $sendtime,
                    'scheduledtime' => $scheduledtime,
                    'timemodified' => $now,
                ]);
            }
            // A released row for the same task: a person decided that send goes out, and a
            // rule run that happens to match the task again does not overturn that.
            return (int) $row->id;
        }

        return $DB->insert_record(self::TABLENAME, (object) [
            'ruleid' => $ruleid,
            'optionid' => $optionid,
            'userid' => $userid,
            'taskid' => $taskid,
            'status' => self::STATUS_PENDING,
            'notified' => 0,
            'sendtime' => $sendtime,
            'scheduledtime' => $scheduledtime,
            'timesent' => 0,
            'taskdata' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Returns the row that belongs to the given adhoc task.
     *
     * @param int|null $taskid
     * @return stdClass|null
     */
    public static function get_row_by_task(?int $taskid): ?stdClass {
        global $DB;
        if (empty($taskid)) {
            return null;
        }
        $row = $DB->get_record(self::TABLENAME, ['taskid' => $taskid]);
        return empty($row) ? null : $row;
    }

    /**
     * Decides whether the running task is allowed to send.
     *
     * @param stdClass|null $row The row of the running task, null when it is not bulk checked.
     * @param string $taskdata The custom data of the running task, kept on a blocked row.
     * @return int Either self::SEND or self::BLOCKED.
     */
    public static function check(?stdClass $row, string $taskdata): int {
        global $DB;

        if (empty($row)) {
            // Nothing was recorded for this task, so there is nothing to check against.
            return self::SEND;
        }

        if (!self::applies((int) $row->ruleid)) {
            // The checker was switched off, or this rule was unticked, after the send was
            // queued. Nothing may hold it back now: falling through to the check would
            // measure the burst against the default limit instead of letting it out, which
            // is the opposite of what switching the checker off is for.
            return self::SEND;
        }

        switch ((int) $row->status) {
            case self::STATUS_BLOCKED:
                // The verdict was already taken, do not take it a second time.
                return self::BLOCKED;
            case self::STATUS_RELEASED:
            case self::STATUS_RELEASING:
            case self::STATUS_SENT:
            case self::STATUS_SENTRELEASED:
                // Decided by hand, or already out. Never judged again.
                return self::SEND;
        }

        $limit = bulk_check_config::get_limit((int) $row->ruleid);
        $count = self::count_window($row, bulk_check_config::get_global_period());
        if ($count > $limit) {
            // Queued sends are counted before they are validated. Before blocking on that
            // count, the ones that will never go out are cleared away and the count is taken
            // again, so that a cancelled booking cannot tip a burst over the limit.
            $count = self::sweep_window($row, $limit);
        }
        if ($count <= $limit) {
            return self::SEND;
        }

        // The status is checked again in the statement: a row somebody released a moment
        // ago is theirs, and the copy of the task data is what a release rebuilds from.
        $DB->execute(
            "UPDATE {" . self::TABLENAME . "}
                SET status = :blocked, taskdata = :taskdata, timemodified = :now
              WHERE id = :id AND status = :pending",
            [
                'blocked' => self::STATUS_BLOCKED,
                'taskdata' => $taskdata,
                'now' => time(),
                'id' => $row->id,
                'pending' => self::STATUS_PENDING,
            ]
        );

        bulk_check_blocked::create([
            'context' => context_system::instance(),
            'relateduserid' => (int) $row->userid,
            'other' => [
                'ruleid' => (int) $row->ruleid,
                'optionid' => (int) $row->optionid,
                'userid' => (int) $row->userid,
                'count' => $count,
                'rowid' => (int) $row->id,
            ],
        ])->trigger();

        self::notify_burst($row, $count);

        return self::BLOCKED;
    }

    /**
     * Turns the row of the running task into a sent row, because the mail went out.
     *
     * This runs after the mail actually went out, so that a failure in between leaves the
     * row pending and the retry of the task can claim it again. The task id is cleared:
     * core deletes the task next, and the unique index on the task id must stay meaningful.
     *
     * A released row becomes STATUS_SENTRELEASED rather than STATUS_SENT. Somebody decided
     * that those mails go out, so they must not count against the next sends of the rule.
     *
     * @param stdClass|null $row The row of the running task.
     * @return void
     */
    public static function mark_sent(?stdClass $row): void {
        global $DB;
        if (empty($row)) {
            return;
        }
        $DB->execute(
            "UPDATE {" . self::TABLENAME . "}
                SET status = CASE WHEN status = :wasreleased THEN :sentreleased ELSE :sent END,
                    timesent = :now, taskid = NULL, taskdata = NULL, timemodified = :now2
              WHERE id = :id AND status IN (:pending, :released)",
            [
                'wasreleased' => self::STATUS_RELEASED,
                'sentreleased' => self::STATUS_SENTRELEASED,
                'sent' => self::STATUS_SENT,
                'now' => time(),
                'now2' => time(),
                'id' => $row->id,
                'pending' => self::STATUS_PENDING,
                'released' => self::STATUS_RELEASED,
            ]
        );
    }

    /**
     * Deletes the row of a task that finished without sending.
     *
     * The rule is gone, or has changed, or no longer applies, or the message controller
     * refused: whichever it was, that send will never happen and must not count towards a
     * window. A blocked row is never touched here, it belongs to the parked list.
     *
     * @param stdClass|null $row The row of the running task.
     * @return void
     */
    public static function drop(?stdClass $row): void {
        global $DB;
        if (empty($row)) {
            return;
        }
        $DB->delete_records_select(
            self::TABLENAME,
            'id = :id AND status IN (:pending, :released)',
            ['id' => $row->id, 'pending' => self::STATUS_PENDING, 'released' => self::STATUS_RELEASED]
        );
    }

    /**
     * Deletes the row of a task that somebody removed from the queue by hand.
     *
     * Called wherever booking deletes a send task itself, so that the row does not linger
     * as an orphan until the cleanup finds it.
     *
     * @param int $taskid
     * @return void
     */
    public static function drop_by_task(int $taskid): void {
        global $DB;
        if (empty($taskid)) {
            return;
        }
        $DB->delete_records_select(
            self::TABLENAME,
            'taskid = :taskid AND status IN (:pending, :released)',
            ['taskid' => $taskid, 'pending' => self::STATUS_PENDING, 'released' => self::STATUS_RELEASED]
        );
    }

    /**
     * Releases every blocked send of a rule and queues it again.
     *
     * @param int $ruleid
     * @return int The number of sends handed over for release.
     */
    public static function release_rule(int $ruleid): int {
        return self::start_release($ruleid);
    }

    /**
     * Hands a parked burst over to the release task.
     *
     * Queueing one send task per row here would mean thousands of statements inside a web
     * request, and a timeout half way through would leave the burst split between two
     * states with no way to tell which rows had been dealt with. Instead the rows are moved
     * to releasing in a single statement and the queueing itself happens in the background.
     * That also makes a second click harmless: it finds nothing blocked and queues nothing.
     *
     * @param int $ruleid
     * @return int
     */
    private static function start_release(int $ruleid): int {
        $where = 'ruleid = :ruleid';
        $params = ['ruleid' => $ruleid];

        $rows = self::select_blocked($where, $params);
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, bulk_check_released::class);

        $count = self::move_blocked($where, $params, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        self::queue_release_task($ruleid);

        return $count;
    }

    /**
     * The blocked rows a condition matches, with what the audit event needs to know.
     *
     * @param string $where Without the status, which is added here.
     * @param array $params
     * @return array
     */
    private static function select_blocked(string $where, array $params): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLENAME,
            '(' . $where . ') AND status = :blocked',
            $params + ['blocked' => self::STATUS_BLOCKED],
            'id ASC',
            'id, ruleid, optionid, userid'
        );
    }

    /**
     * Deletes rows that are still blocked, in chunks.
     *
     * The status is checked again in the statement itself: a row that another admin has
     * released in the meantime is on its way out and must not be taken away from them.
     *
     * @param array $ids
     * @return int The number of rows that were deleted.
     */
    private static function delete_blocked(array $ids): int {
        global $DB;

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bcid');
            $select = "id $insql AND status = :blocked";
            $params = $inparams + ['blocked' => self::STATUS_BLOCKED];
            $count += $DB->count_records_select(self::TABLENAME, $select, $params);
            $DB->delete_records_select(self::TABLENAME, $select, $params);
        }
        return $count;
    }

    /**
     * Fires one audit event per rule for a decision taken by hand.
     *
     * Releasing and dismissing are the two points where a person overrides the checker.
     * A dismissed row is deleted, so the event is the only record left that the mail was
     * given up on, and the one that answers why somebody never got it. One event per rule
     * rather than per row: a burst can hold thousands of rows, and the ids travel in the
     * event data, capped so that the log entry stays readable.
     *
     * @param array $rows As returned by select_blocked().
     * @param string $eventclass bulk_check_released::class or bulk_check_dismissed::class.
     * @return void
     */
    private static function log_decision(array $rows, string $eventclass): void {
        if (empty($rows)) {
            return;
        }

        $byrule = [];
        foreach ($rows as $row) {
            $byrule[(int) $row->ruleid][] = (int) $row->id;
        }

        foreach ($byrule as $ruleid => $ids) {
            $eventclass::create([
                'context' => context_system::instance(),
                'other' => [
                    'ruleid' => $ruleid,
                    'count' => count($ids),
                    'rowids' => array_slice($ids, 0, self::EVENTIDS),
                ],
            ])->trigger();
        }
    }

    /**
     * Moves every blocked row the condition matches into another status, in one statement.
     *
     * The blocked state is added here rather than by the callers, so that the one rule that
     * matters - nothing but a parked row is ever touched by hand - lives in a single place.
     *
     * @param string $where Without the status, which is added here.
     * @param array $params
     * @param int $status
     * @return int The number of rows that were moved.
     */
    private static function move_blocked(string $where, array $params, int $status): int {
        global $DB;

        $where = '(' . $where . ') AND status = :blocked';
        $params['blocked'] = self::STATUS_BLOCKED;

        $count = $DB->count_records_select(self::TABLENAME, $where, $params);
        if (empty($count)) {
            return 0;
        }

        $DB->execute(
            "UPDATE {" . self::TABLENAME . "}
                SET status = :newstatus, timemodified = :now
              WHERE " . $where,
            $params + ['newstatus' => $status, 'now' => time()]
        );

        return $count;
    }

    /**
     * Hands one rule over to the background task that queues its sends.
     *
     * @param int $ruleid
     * @return void
     */
    private static function queue_release_task(int $ruleid): void {
        $task = new release_bulk_check();
        $task->set_custom_data(['ruleid' => $ruleid]);
        $task->set_next_run_time(time());
        manager::queue_adhoc_task($task);
    }

    /**
     * Turns whatever the checkboxes sent into a clean list of row ids.
     *
     * The ids come off the client as strings and are never trusted for anything but their
     * numeric value: everything they can name is checked against the blocked state before
     * it is touched.
     *
     * @param array $ids
     * @return array
     */
    private static function clean_ids(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    }

    /**
     * Releases the parked sends that were picked out of the list by hand.
     *
     * The ids come from the checkboxes, so they can name rows of more than one rule and
     * rows another admin has already dealt with. Only rows that are still blocked are moved,
     * and because the release task works a whole rule at a time it is queued once per rule
     * the selection touches, not once per row.
     *
     * @param array $ids Ids of booking_bulk_check rows.
     * @return int The number of sends handed over for release.
     */
    public static function release_rows(array $ids): int {
        $ids = self::clean_ids($ids);
        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            $count += self::release_chunk($chunk);
        }
        return $count;
    }

    /**
     * Releases one chunk of hand picked rows.
     *
     * @param array $ids
     * @return int
     */
    private static function release_chunk(array $ids): int {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'bcid');

        // The rows have to be read before the flip: afterwards they are no longer blocked
        // and the query would come back empty.
        $rows = self::select_blocked("id $insql", $inparams);
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, bulk_check_released::class);

        $count = self::move_blocked("id $insql", $inparams, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        foreach (array_unique(array_column($rows, 'ruleid')) as $ruleid) {
            self::queue_release_task((int) $ruleid);
        }

        return $count;
    }

    /**
     * Gives up on the parked sends that were picked out of the list by hand.
     *
     * @param array $ids Ids of booking_bulk_check rows.
     * @return int The number of dismissed sends.
     */
    public static function dismiss_rows(array $ids): int {
        global $DB;

        $ids = self::clean_ids($ids);
        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bcid');
            $count += self::dismiss_selected(self::select_blocked("id $insql", $inparams));
        }
        return $count;
    }

    /**
     * Records that the rows were given up on, then deletes them.
     *
     * A dismissed send will never happen, so the row is not kept in some final state: it
     * cannot influence a verdict any more and would only make the table grow. The audit
     * event fired first is what remains of the decision.
     *
     * @param array $rows As returned by select_blocked().
     * @return int The number of dismissed sends.
     */
    private static function dismiss_selected(array $rows): int {
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, bulk_check_dismissed::class);
        return self::delete_blocked(array_keys($rows));
    }

    /**
     * Releases every parked send there is, one rule at a time.
     *
     * @return int The number of sends handed over for release.
     */
    public static function release_all(): int {
        $count = 0;
        foreach (self::get_parked_rules() as $parked) {
            $count += self::start_release((int) $parked->ruleid);
        }
        return $count;
    }

    /**
     * Gives up on every parked send there is.
     *
     * @return int The number of dismissed sends.
     */
    public static function dismiss_all(): int {
        $count = 0;
        foreach (self::get_parked_rules() as $parked) {
            $count += self::dismiss_rule((int) $parked->ruleid);
        }
        return $count;
    }

    /**
     * Gives up on every parked send of a rule.
     *
     * The mails are never sent and the burst stops turning up in the reminder. This is the
     * other way out of the parked state: release when the burst should still go out,
     * dismiss when it should not. The rows are deleted, see dismiss_selected().
     *
     * @param int $ruleid
     * @return int The number of dismissed sends.
     */
    public static function dismiss_rule(int $ruleid): int {
        return self::dismiss_selected(self::select_blocked('ruleid = :ruleid', ['ruleid' => $ruleid]));
    }

    /**
     * Queues the send tasks of everything that is waiting to be released.
     *
     * Called from the release task, in batches, so that a burst of any size gets through
     * without holding a single request open. The task is rebuilt from the copy of its custom
     * data the block kept, so it is the very same send that was parked. It still passes
     * through every check of send_mail_by_rule_adhoc: a rule that was edited or deleted in
     * the meantime lets nothing out, and that is right - the parked template is stale.
     *
     * @param int $ruleid
     * @param int $batchsize
     * @return int The number of sends queued in this batch.
     */
    public static function queue_released_batch(int $ruleid, int $batchsize = 500): int {
        global $DB;

        $rows = $DB->get_records(
            self::TABLENAME,
            ['ruleid' => $ruleid, 'status' => self::STATUS_RELEASING],
            'id ASC',
            '*',
            0,
            $batchsize
        );

        $queued = 0;
        foreach ($rows as $row) {
            if (empty($row->taskdata)) {
                // Nothing to rebuild the send from. This cannot come out of the block, but
                // a row like that would sit in releasing forever.
                mtrace("bulk_check: releasing row {$row->id} has no task data, dropped.");
                $DB->delete_records(self::TABLENAME, ['id' => $row->id]);
                continue;
            }

            $task = new send_mail_by_rule_adhoc();
            $task->set_custom_data_as_string($row->taskdata);
            $task->set_userid((int) $row->userid);
            $task->set_next_run_time(time());
            try {
                $taskid = (int) manager::queue_adhoc_task($task);
            } catch (\Throwable $e) {
                // Core refuses a task for a user who is gone or suspended. That mail can
                // never be sent, so the row goes the same way a dismissed one does.
                mtrace("bulk_check: releasing row {$row->id} could not be queued: " . $e->getMessage());
                $DB->delete_records(self::TABLENAME, ['id' => $row->id]);
                continue;
            }
            if (empty($taskid)) {
                continue;
            }

            $DB->execute(
                "UPDATE {" . self::TABLENAME . "}
                    SET status = :released, taskid = :taskid, timemodified = :now
                  WHERE id = :id AND status = :releasing",
                [
                    'released' => self::STATUS_RELEASED,
                    'taskid' => $taskid,
                    'now' => time(),
                    'id' => $row->id,
                    'releasing' => self::STATUS_RELEASING,
                ]
            );
            $queued++;
        }
        return $queued;
    }

    /**
     * Every burst that is still parked, oldest first.
     *
     * One entry per rule, carrying the name of the rule, how many sends are waiting and when
     * the oldest of them was blocked.
     *
     * @return array
     */
    public static function get_parked_bursts(): array {
        global $DB;
        $sql = "SELECT b.ruleid AS id, b.ruleid, COUNT(b.id) AS parked, MIN(b.timemodified) AS oldest
                  FROM {" . self::TABLENAME . "} b
                 WHERE b.status = :blocked
              GROUP BY b.ruleid
              ORDER BY MIN(b.timemodified) ASC";
        $bursts = $DB->get_records_sql($sql, ['blocked' => self::STATUS_BLOCKED]);
        $names = self::get_rule_names(array_keys($bursts));
        foreach ($bursts as $burst) {
            $burst->rulename = $names[(int) $burst->ruleid] ?? '';
        }
        return $bursts;
    }

    /**
     * Every rule that still has parked sends, oldest first.
     *
     * @return array
     */
    public static function get_parked_rules(): array {
        return self::get_parked_bursts();
    }

    /**
     * How many mails are parked, either altogether or for one rule.
     *
     * @param int $ruleid Zero counts every rule.
     * @return int
     */
    public static function count_parked(int $ruleid = 0): int {
        global $DB;
        $conditions = ['status' => self::STATUS_BLOCKED];
        if (!empty($ruleid)) {
            $conditions['ruleid'] = $ruleid;
        }
        return $DB->count_records(self::TABLENAME, $conditions);
    }

    /**
     * Returns the sql of the parked list, one row per mail that is waiting.
     *
     * The id of the row is the id of the parked send, because that is what the checkboxes
     * of the list hand back when a selection is sent or dismissed.
     *
     * Everything the list filters, searches and sorts on is selected inside the subquery, so
     * that the library can use the column names on their own in its own where clauses.
     *
     * The rule and the option are joined loosely and coalesced: deleting either leaves the
     * parked rows behind, and those have to stay visible so that they can be disposed of.
     *
     * @param int $ruleid Limits the list to one rule, zero takes all of them.
     * @return array [$fields, $from, $where, $params]
     */
    public static function get_parked_mails_sql(int $ruleid = 0): array {
        global $DB;

        $params = ['blocked' => self::STATUS_BLOCKED];
        $scope = '';
        if (!empty($ruleid)) {
            $scope = ' AND b.ruleid = :ruleid';
            $params['ruleid'] = $ruleid;
        }

        $recipient = $DB->sql_concat('u.lastname', "' '", 'u.firstname');
        $rulename = self::get_rulename_sql('br');

        // Every name field of the site, or fullname() complains about the ones it misses.
        $namefields = [];
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $namefields[] = 'u.' . $namefield;
        }
        $namefields = implode(",\n                         ", $namefields);

        $from = "(SELECT b.id,
                         b.ruleid,
                         b.optionid,
                         b.userid,
                         b.sendtime,
                         b.scheduledtime,
                         b.timemodified,
                         COALESCE($rulename, '') AS rulename,
                         COALESCE(bo.text, '') AS optionname,
                         bo.bookingid,
                         $namefields,
                         u.email,
                         $recipient AS recipient
                    FROM {" . self::TABLENAME . "} b
                    JOIN {user} u ON u.id = b.userid
               LEFT JOIN {booking_rules} br ON br.id = b.ruleid
               LEFT JOIN {booking_options} bo ON bo.id = b.optionid
                   WHERE b.status = :blocked" . $scope . ") parkedmails";

        return ['*', $from, '1=1', $params];
    }

    /**
     * Removes what can no longer matter and rescues what got stuck.
     *
     * A pending row whose task is gone will never be sent, so it goes. A sent row that has
     * fallen out of every window it could still be counted in goes too. A releasing row
     * whose release task is gone is the opposite case, mail that was meant to go out, and
     * gets its task back instead.
     *
     * Deleting happens in batches: a burst can leave thousands of rows behind and one
     * unbounded statement would hold the table locked for the duration.
     *
     * @param int $maxbatches How many batches one run gets through per kind of row.
     * @return array Counts keyed by orphaned, sentpurged and rescued.
     */
    public static function cleanup(int $maxbatches = 20): array {
        global $DB;

        $now = time();
        $period = bulk_check_config::get_global_period();
        $result = ['orphaned' => 0, 'sentpurged' => 0, 'rescued' => 0];

        // Pending rows whose task is gone, or whose task failed for good and is kept by core
        // with no attempts left. The horizon keeps a row that was written a moment ago out
        // of it, and rules out counting against a send that is merely late.
        for ($batch = 0; $batch < $maxbatches; $batch++) {
            $rows = $DB->get_records_sql(
                "SELECT b.id
                   FROM {" . self::TABLENAME . "} b
              LEFT JOIN {task_adhoc} t ON t.id = b.taskid
                  WHERE b.status = :pending
                    AND (t.id IS NULL OR t.attemptsavailable = 0)
                    AND b.scheduledtime < :horizon
               ORDER BY b.id ASC",
                ['pending' => self::STATUS_PENDING, 'horizon' => $now - $period],
                0,
                self::CLEANUPBATCH
            );
            if (empty($rows)) {
                break;
            }
            $DB->delete_records_list(self::TABLENAME, 'id', array_keys($rows));
            $result['orphaned'] += count($rows);
            if (count($rows) < self::CLEANUPBATCH) {
                break;
            }
        }

        // Sent rows that no window can reach any more. Twice the period, so that a task
        // that ran up to a full period late still finds them in its window.
        for ($batch = 0; $batch < $maxbatches; $batch++) {
            $rows = $DB->get_records_select(
                self::TABLENAME,
                'status IN (:sent, :sentreleased) AND timesent < :horizon',
                [
                    'sent' => self::STATUS_SENT,
                    'sentreleased' => self::STATUS_SENTRELEASED,
                    'horizon' => $now - 2 * $period,
                ],
                'id ASC',
                'id',
                0,
                self::CLEANUPBATCH
            );
            if (empty($rows)) {
                break;
            }
            $DB->delete_records_list(self::TABLENAME, 'id', array_keys($rows));
            $result['sentpurged'] += count($rows);
            if (count($rows) < self::CLEANUPBATCH) {
                break;
            }
        }

        // Releasing rows that nobody is working on any more.
        $ruleids = $DB->get_fieldset_select(
            self::TABLENAME,
            'DISTINCT ruleid',
            'status = :releasing AND timemodified < :stale',
            ['releasing' => self::STATUS_RELEASING, 'stale' => $now - self::STALERELEASE]
        );
        foreach ($ruleids as $ruleid) {
            if (self::release_task_queued((int) $ruleid)) {
                continue;
            }
            self::queue_release_task((int) $ruleid);
            $result['rescued']++;
        }

        return $result;
    }

    /**
     * Whether a release task is waiting for the given rule.
     *
     * @param int $ruleid
     * @return bool
     */
    private static function release_task_queued(int $ruleid): bool {
        global $DB;
        $sql = "SELECT COUNT(id)
                  FROM {task_adhoc}
                 WHERE classname = :classname
                   AND " . $DB->sql_compare_text('customdata') . " = :customdata";
        return $DB->count_records_sql($sql, [
            'classname' => '\\' . release_bulk_check::class,
            'customdata' => json_encode(['ruleid' => $ruleid]),
        ]) > 0;
    }

    /**
     * Clears the queued sends out of the window that will never go out, then counts again.
     *
     * A queued send is counted from the moment it is queued, but only validated when its own
     * task runs. For a reminder that is queued days ahead a booking can be cancelled or an
     * option changed in between, and those sends would still push the count over the limit.
     * This runs only when the running task is about to be blocked, and validates the other
     * pending rows of the window with the same check the scheduled mails list uses. A send
     * that no longer applies loses its task and its row, which is what its own task would
     * have done when it ran.
     *
     * The sweep stops as soon as enough invalid sends can no longer be found to get under the
     * limit. A real flood is therefore settled after about as many checks as the limit, while
     * a burst just over the limit is checked completely.
     *
     * A task another worker has already started is left alone: deleting it would take away
     * the row it needs, and it would then send without being checked.
     *
     * @param stdClass $row The row of the running task.
     * @param int $limit
     * @return int The count of the window after the sweep.
     */
    private static function sweep_window(stdClass $row, int $limit): int {
        global $DB;

        $period = bulk_check_config::get_global_period();
        $lock = lock_config::get_lock_factory(self::LOCKTYPE)
            ->get_lock('sweep' . $row->ruleid, self::SWEEPLOCKWAIT);
        if (!$lock) {
            // Another worker sweeps this rule and did not finish in time. Its result is in
            // the table by the time the next task of the burst runs.
            return self::count_window($row, $period);
        }

        try {
            // Taken again under the lock: the worker that held it may have swept already.
            $count = self::count_window($row, $period);
            $needed = $count - $limit;
            if ($needed <= 0) {
                return $count;
            }

            $params = self::window_params($row, $period) + [
                'pending' => self::STATUS_PENDING,
                'ownid' => $row->id,
            ];
            $where = "b.ruleid = :ruleid
                  AND b.status = :pending
                  AND b.id <> :ownid
                  AND b.scheduledtime >= :windowstart
                  AND b.scheduledtime <= :windowend";

            $remaining = $DB->count_records_sql("SELECT COUNT(b.id) FROM {" . self::TABLENAME . "} b WHERE $where", $params);
            if ($remaining < $needed) {
                // Even if every other queued send was invalid, the count stays over the limit.
                return $count;
            }

            $candidates = $DB->get_recordset_sql(
                "SELECT b.id AS rowid, t.id, t.customdata, t.nextruntime, t.timestarted, t.attemptsavailable,
                        br.id AS ruleid, br.isactive, br.contextid
                   FROM {" . self::TABLENAME . "} b
              LEFT JOIN {task_adhoc} t ON t.id = b.taskid
              LEFT JOIN {booking_rules} br ON br.id = b.ruleid
                  WHERE $where
               ORDER BY b.id ASC",
                $params
            );

            $dropped = 0;
            $deletedtasks = 0;
            foreach ($candidates as $candidate) {
                if ($dropped + $remaining < $needed) {
                    break;
                }
                $remaining--;

                if (empty($candidate->id)) {
                    // The task is gone, so this send can never happen.
                    $DB->delete_records(self::TABLENAME, ['id' => $candidate->rowid, 'status' => self::STATUS_PENDING]);
                    $dropped++;
                    continue;
                }
                if (!empty($candidate->timestarted)) {
                    // Running on another worker right now, it settles its own row.
                    continue;
                }
                if ($candidate->attemptsavailable !== null && (int) $candidate->attemptsavailable === 0) {
                    // Failed for good. Core keeps the task for the admin, the row goes.
                    $DB->delete_records(self::TABLENAME, ['id' => $candidate->rowid, 'status' => self::STATUS_PENDING]);
                    $dropped++;
                    continue;
                }
                if (scheduledmails::is_task_still_valid($candidate)) {
                    continue;
                }

                $DB->delete_records_select('task_adhoc', 'id = :id AND timestarted IS NULL', ['id' => $candidate->id]);
                $DB->delete_records(self::TABLENAME, ['id' => $candidate->rowid, 'status' => self::STATUS_PENDING]);
                $deletedtasks++;
                $dropped++;
            }
            $candidates->close();

            if (empty($dropped)) {
                return $count;
            }

            mtrace("bulk_check: {$dropped} queued sends of rule {$row->ruleid} no longer apply and were removed.");
            if (!empty($deletedtasks)) {
                cache_helper::purge_by_definition('mod_booking', 'scheduledmailscache');
                cache_helper::purge_by_event('setbackscheduledmailscache');
            }

            return self::count_window($row, $period);
        } finally {
            $lock->release();
        }
    }

    /**
     * Counts the sends of the same rule around the sending time of the row.
     *
     * Two kinds of row add up. Pending and blocked rows are the sends still ahead, and the
     * blocked ones keep counting so that the verdict stays stable while cron works through
     * the burst. Sent rows are the sends already behind, counted by when the mail actually
     * went out, so that a slow flood is caught as well as a burst. Sends that will never
     * happen have no row.
     *
     * Released and releasing rows were decided by hand and are not counted. Leaving them in
     * would break the release itself: the sends that were just let through would be counted
     * against their own limit and blocked all over again, so the admin would get a success
     * message and no mail would ever go out. Once such a send is out it becomes a
     * STATUS_SENTRELEASED row, which does not count either: otherwise the released burst
     * would block every further send of the rule for half a period after the release.
     *
     * @param stdClass $row
     * @param int $period
     * @return int
     */
    private static function count_window(stdClass $row, int $period): int {
        global $DB;
        $params = self::window_params($row, $period);

        $queued = "SELECT COUNT(id)
                     FROM {" . self::TABLENAME . "}
                    WHERE ruleid = :ruleid
                      AND status IN (:pending, :blocked)
                      AND scheduledtime >= :windowstart
                      AND scheduledtime <= :windowend";
        $count = (int) $DB->count_records_sql($queued, $params + [
            'pending' => self::STATUS_PENDING,
            'blocked' => self::STATUS_BLOCKED,
        ]);

        $sent = "SELECT COUNT(id)
                   FROM {" . self::TABLENAME . "}
                  WHERE ruleid = :ruleid
                    AND status = :sent
                    AND timesent >= :windowstart
                    AND timesent <= :windowend";
        $count += (int) $DB->count_records_sql($sent, $params + ['sent' => self::STATUS_SENT]);

        return $count;
    }

    /**
     * The counting window of a row.
     *
     * The window is centred on the sending time of the row and is exactly one period wide,
     * so that a limit of fifty per hour really means fifty per hour. Centring it rather
     * than only looking backwards is what lets every row of one burst see every other row,
     * which in turn makes them all reach the same verdict no matter in which order cron
     * works through them.
     *
     * @param stdClass $row
     * @param int $period
     * @return array
     */
    private static function window_params(stdClass $row, int $period): array {
        $half = intdiv($period, 2);
        return [
            'ruleid' => $row->ruleid,
            'windowstart' => $row->scheduledtime - $half,
            'windowend' => $row->scheduledtime + $half,
        ];
    }

    /**
     * Informs the configured users that a burst was blocked, once per burst.
     *
     * Several cron workers can run tasks of the same burst at the same time, so only the
     * one that gets the lock sends the notification. The others still block, they just
     * stay quiet about it.
     *
     * @param stdClass $row
     * @param int $count
     * @return void
     */
    private static function notify_burst(stdClass $row, int $count): void {
        global $DB;

        $factory = lock_config::get_lock_factory(self::LOCKTYPE);
        $lock = $factory->get_lock('r' . $row->ruleid, 0);
        if (!$lock) {
            return;
        }

        try {
            $period = bulk_check_config::get_global_period();
            if (self::burst_was_notified($row, $period)) {
                return;
            }
            $DB->set_field(self::TABLENAME, 'notified', 1, ['id' => $row->id]);

            // The list is reached straight from the alert, already narrowed to the rule the
            // burst belongs to, so that the decision can be taken there and then.
            $url = new moodle_url('/mod/booking/bulkcheck.php', ['ruleid' => $row->ruleid]);

            $a = (object) [
                'rule' => self::get_rule_name((int) $row->ruleid),
                'ruleid' => $row->ruleid,
                'count' => $count,
                'limit' => bulk_check_config::get_limit((int) $row->ruleid),
                'period' => format_time($period),
                'link' => $url->out(false),
            ];

            $stringmanager = get_string_manager();
            foreach (bulk_check_config::get_notify_userids() as $userid) {
                $userto = core_user::get_user($userid);
                if (empty($userto) || !empty($userto->deleted)) {
                    continue;
                }
                $lang = $userto->lang ?: current_language();
                $msg = new message();
                $msg->component = 'mod_booking';
                $msg->name = 'bulkchecknotification';
                $msg->userfrom = core_user::get_noreply_user();
                $msg->userto = $userto;
                $msg->subject = $stringmanager->get_string('bulkcheckblockedsubject', 'mod_booking', $a, $lang);

                // The plain part carries the bare address, the html part a real link.
                $a->link = $url->out(false);
                $msg->fullmessage = $stringmanager->get_string('bulkcheckblockedbody', 'mod_booking', $a, $lang);
                $a->link = html_writer::link($url, $url->out(false));
                $msg->fullmessagehtml = $stringmanager->get_string('bulkcheckblockedbody', 'mod_booking', $a, $lang);

                $msg->fullmessageformat = FORMAT_HTML;
                $msg->smallmessage = $msg->subject;
                $msg->notification = 1;
                $msg->contexturl = $url->out(false);
                $msg->contexturlname = $stringmanager->get_string('bulkcheckparked', 'mod_booking', null, $lang);
                message_send($msg);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the burst that is parked right now was already announced.
     *
     * Only blocked rows count. Once a burst was released or dismissed it is dealt with, and a
     * block after that is a new burst that the configured users have to hear about.
     *
     * @param stdClass $row
     * @param int $period
     * @return bool
     */
    private static function burst_was_notified(stdClass $row, int $period): bool {
        global $DB;
        $sql = "SELECT COUNT(id)
                  FROM {" . self::TABLENAME . "}
                 WHERE ruleid = :ruleid
                   AND scheduledtime >= :windowstart
                   AND scheduledtime <= :windowend
                   AND notified = 1
                   AND status = :blocked";
        $params = self::window_params($row, $period) + ['blocked' => self::STATUS_BLOCKED];
        return $DB->count_records_sql($sql, $params) > 0;
    }

    /**
     * The sql expression that reads the name of a rule out of its json.
     *
     * The same database family switch scheduledmails::get_sql() uses.
     *
     * @param string $alias The alias of the booking_rules table.
     * @return string
     */
    private static function get_rulename_sql(string $alias): string {
        global $DB;
        if ($DB->get_dbfamily() === 'postgres') {
            return "$alias.rulejson::jsonb ->> 'name'";
        }
        return "JSON_UNQUOTE(JSON_EXTRACT($alias.rulejson, '$.name'))";
    }

    /**
     * The names of the given rules, keyed by id. A rule that is gone has no entry.
     *
     * @param array $ruleids
     * @return array
     */
    private static function get_rule_names(array $ruleids): array {
        global $DB;
        $names = [];
        if (empty($ruleids)) {
            return $names;
        }
        foreach ($DB->get_records_list('booking_rules', 'id', $ruleids, '', 'id, rulejson') as $rule) {
            $json = json_decode($rule->rulejson ?? '');
            $names[(int) $rule->id] = (string) ($json->name ?? '');
        }
        return $names;
    }

    /**
     * The name of a rule, or a placeholder when the rule is gone.
     *
     * A rule can be deleted while its sends are still parked, and the alert has to say
     * something useful even then.
     *
     * @param int $ruleid
     * @return string
     */
    public static function get_rule_name(int $ruleid): string {
        $names = self::get_rule_names([$ruleid]);
        if (empty($names[$ruleid])) {
            return get_string('bulkcheckdeletedrule', 'mod_booking', $ruleid);
        }
        return format_string($names[$ruleid]);
    }
}
