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

use mod_booking\event\bookingoption_completed;
use stdClass;

/**
 * Shared completion counting and gating for certificate conditions that observe a set of booking options.
 *
 * A condition using this trait only has to provide get_targeted_optionids() and get_requiredcount().
 * The completion state is read with one query over the answers of the user (not by loading the answers
 * of every observed option), so the cost does not grow with the size of the booking instances.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait completion_counter_trait {
    /**
     * Returns true when the given option is one of the targeted options.
     *
     * @param int $optionid
     * @return bool
     */
    public function targets_option(int $optionid): bool {
        return in_array($optionid, array_map('intval', $this->get_targeted_optionids()), true);
    }

    /**
     * Return the ids of the targeted options the user has completed, excluding the given ids.
     *
     * @param int $userid
     * @param int[] $excludeoptionids
     * @return int[] sorted ascending by option id
     */
    public function get_completed_optionids_for_user(int $userid, array $excludeoptionids = []): array {
        $completed = array_keys($this->get_completions_for_user($userid, $excludeoptionids));
        sort($completed);
        return $completed;
    }

    /**
     * Return the completed targeted options of the user with their completion time, excluding the given ids.
     *
     * @param int $userid
     * @param int[] $excludeoptionids
     * @return array optionid => completion timestamp, sorted by timestamp ASC, option id ASC
     */
    public function get_completions_for_user(int $userid, array $excludeoptionids = []): array {
        if (empty($userid)) {
            return [];
        }
        $exclude = array_flip(array_map('intval', $excludeoptionids));
        $optionids = [];
        foreach ($this->get_targeted_optionids() as $optionid) {
            if (!isset($exclude[(int)$optionid])) {
                $optionids[(int)$optionid] = (int)$optionid;
            }
        }
        if (empty($optionids)) {
            return [];
        }

        $completions = self::completions_from_answers($this->fetch_user_answers($userid, array_values($optionids)));
        uksort($completions, fn($a, $b) => [$completions[$a], $a] <=> [$completions[$b], $b]);
        return $completions;
    }

    /**
     * Fetch the relevant answers of the user for the given options, oldest first.
     *
     * Mirrors the answers booking_answers works with: all statuses except deleted ones. Conditions
     * observing a whole booking instance override this to query by booking id instead of an id list.
     *
     * @param int $userid
     * @param int[] $optionids
     * @return stdClass[] records with id, optionid, waitinglist, completed, completeddate, timemodified
     */
    protected function fetch_user_answers(int $userid, array $optionids): array {
        global $DB;

        if (empty($optionids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED, 'opt');
        [$statussql, $statusparams] = $DB->get_in_or_equal(self::answer_statuses(), SQL_PARAMS_NAMED, 'st');
        $params = array_merge($params, $statusparams, ['userid' => $userid]);

        return $DB->get_records_select(
            'booking_answers',
            "userid = :userid AND waitinglist $statussql AND optionid $insql",
            $params,
            'timemodified ASC, id ASC',
            'id, optionid, waitinglist, completed, completeddate, timemodified'
        );
    }

    /**
     * The answer statuses that count (everything but deleted), as used by booking_answers.
     *
     * @return int[]
     */
    protected static function answer_statuses(): array {
        return [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
            MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
        ];
    }

    /**
     * Reduce answers (oldest first) to the completed options and their completion time, with the same precedence
     * as booking_answers: the newest answer of an option wins, except that a previously booked answer never shadows
     * an active one. The completion time is completeddate, or the last modification when it is not stored.
     *
     * @param stdClass[] $answers
     * @return array optionid => completion timestamp
     */
    protected static function completions_from_answers(array $answers): array {
        $winners = [];
        foreach ($answers as $answer) {
            $optionid = (int)$answer->optionid;
            if (
                (int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED
                || !isset($winners[$optionid])
                || (int)$winners[$optionid]->waitinglist === MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED
            ) {
                $winners[$optionid] = $answer;
            }
        }
        $completions = [];
        foreach ($winners as $optionid => $answer) {
            if ((int)$answer->completed === 1) {
                $completions[$optionid] = (int)(($answer->completeddate ?? 0) ?: ($answer->timemodified ?? 0));
            }
        }
        return $completions;
    }

    /**
     * Returns true when the user has completed enough of the targeted options, ignoring the given ids.
     *
     * The required count is capped by the number of targeted options.
     *
     * @param int $userid
     * @param int[] $excludeoptionids
     * @return bool
     */
    public function is_fulfilled_for_user(int $userid, array $excludeoptionids = []): bool {
        $targeted = $this->get_targeted_optionids();
        if (empty($targeted)) {
            return false;
        }
        $requiredcount = min(max(1, $this->get_requiredcount()), count($targeted));
        return count($this->get_completed_optionids_for_user($userid, $excludeoptionids)) >= $requiredcount;
    }

    /**
     * Standard evaluation of a completion based condition.
     *
     * Only the completion of one of the targeted options may trigger the condition; it is fulfilled
     * when the user has completed the required number of targeted options.
     *
     * @param stdClass $context object with event, userid and optionid
     * @return bool
     */
    protected function evaluate_completion(stdClass $context): bool {
        $event = $context->event ?? null;
        if (!($event instanceof bookingoption_completed)) {
            return false;
        }
        if (!$this->targets_option((int)($context->optionid ?? 0))) {
            return false;
        }
        $userid = (int)($context->userid ?? $event->relateduserid ?? $event->userid ?? 0);
        if (empty($userid)) {
            return false;
        }
        return $this->is_fulfilled_for_user($userid);
    }
}
