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
 * The reconciler facade (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3) - the single write
 * path for waitlist progression. Wires all previously-built collaborators; contains no SQL of its
 * own. K3 autobook reuses booking_option::user_submit_response() rather than the low-level DB
 * writer, so the existing capacity/availability re-check, enrolment, and event/rules side effects
 * all still happen exactly as they do for the existing waitlist-sync autobook path today.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_option;
use mod_booking\local\waitlist\offer_statuses\autobooked;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\singleton_service;
use mod_booking\task\expire_waitlist_offer_adhoc;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Reconciles one option's waiting list against its free capacity.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progression {
    /** @var waitlist_offer_repository */
    private $offers;

    /** @var booking_decision_strategy */
    private $decision;

    /** @var capacity_calculator */
    private $capacity;

    /** @var rule_condition_checker */
    private $condition;

    /** @var messaging_gateway */
    private $messaging;

    /** @var \core\clock */
    private $clock;

    /**
     * Constructs the reconciler with its collaborators.
     *
     * @param waitlist_offer_repository $offers
     * @param booking_decision_strategy $decision
     * @param capacity_calculator $capacity
     * @param rule_condition_checker $condition
     * @param messaging_gateway $messaging
     * @param \core\clock|null $clock defaults to the globally registered clock (§5.1)
     */
    public function __construct(
        waitlist_offer_repository $offers,
        booking_decision_strategy $decision,
        capacity_calculator $capacity,
        rule_condition_checker $condition,
        messaging_gateway $messaging,
        ?\core\clock $clock = null
    ) {
        $this->offers = $offers;
        $this->decision = $decision;
        $this->capacity = $capacity;
        $this->condition = $condition;
        $this->messaging = $messaging;
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Reconciles one option's waiting list: autobooks/offers as many candidates as free capacity
     * and applicable rules allow (K1-K12, O1/O2). The single write path for waitlist progression.
     *
     * @param int $optionid
     * @param string $reason free-text trigger reason, reserved for future logging/audit (G1)
     * @return void
     */
    public function reconcile(int $optionid, string $reason = ''): void {
        $free = $this->capacity->free_capacity($optionid);
        if ($free <= 0) {
            return; // K12: structural, no special-case guard needed.
        }

        $ruleids = $this->condition->applicable_rules($optionid); // K11.
        if (empty($ruleids)) {
            return;
        }

        $excludeuserids = $this->offers->get_permanently_declined_userids($optionid); // K7.
        $rows = $this->offers->get_unbehandelte_waitinglist($optionid, $excludeuserids); // O1/O2.

        $roundid = $this->clock->time();
        $treated = [];

        foreach ($ruleids as $ruleid) {
            foreach ($rows as $index => $row) {
                if ($free <= 0) {
                    break 2; // K1: min(N, M).
                }

                $userid = (int) $row->userid;
                if (isset($treated[$userid])) {
                    continue;
                }
                if (!$this->offers->is_still_on_waitinglist($optionid, $userid)) {
                    continue; // K8: left the waiting list mid-round, no $free--.
                }
                $treated[$userid] = true;
                $sortorder = $index + 1; // Frozen at round start (O1-O3, O5), not per-rule.

                $user = singleton_service::get_instance_of_user($userid);
                $candidate = new booking_waitlist_candidate($optionid, $userid, (int) $row->baid, $user);

                $decision = $this->decision->decide($candidate);
                if ($decision === booking_decision::AUTOBOOK) {
                    if ($this->autobook($candidate, $optionid, $ruleid, $roundid, $sortorder)) {
                        $free--;
                    }
                } else {
                    $this->offer($candidate, $optionid, $ruleid, $roundid, $sortorder); // K4.
                    $free--;
                }
            }
        }
    }

    /**
     * Books the candidate's existing waiting-list answer directly (K3), reusing
     * booking_option::user_submit_response() rather than the low-level DB writer - this also
     * re-checks availability/capacity, handles enrolment, and triggers the usual booking
     * events/rules, exactly like the existing waitlist-sync autobook path does today. Returns
     * false without side effects if that re-check rejects the booking (defensive - free capacity
     * is not decremented for a candidate that could not actually be booked).
     *
     * @param booking_waitlist_candidate $candidate
     * @param int $optionid
     * @param int $ruleid
     * @param int $roundid
     * @param int $sortorder
     * @return bool
     */
    private function autobook(
        booking_waitlist_candidate $candidate,
        int $optionid,
        int $ruleid,
        int $roundid,
        int $sortorder
    ): bool {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            return false;
        }

        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $result = $bookingoption->user_submit_response($candidate->user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        if ($result === false) {
            return false;
        }

        $this->offers->create_offer(
            $optionid,
            $candidate->userid,
            $roundid,
            $sortorder,
            new autobooked(),
            0,
            $ruleid
        );
        $this->messaging->notify_autobooked($candidate, $ruleid);

        return true;
    }

    /**
     * Creates a paid offer for the candidate (K4: hard expiry, expiresat = now + the rule's own
     * interval), and notifies them.
     *
     * @param booking_waitlist_candidate $candidate
     * @param int $optionid
     * @param int $ruleid
     * @param int $roundid
     * @param int $sortorder
     * @return void
     */
    private function offer(
        booking_waitlist_candidate $candidate,
        int $optionid,
        int $ruleid,
        int $roundid,
        int $sortorder
    ): void {
        $expiresat = $this->clock->time() + $this->offer_interval_seconds($ruleid);
        $offerobj = $this->offers->create_offer(
            $optionid,
            $candidate->userid,
            $roundid,
            $sortorder,
            new offered(),
            $expiresat,
            $ruleid
        );

        // K4: one task per offer, scheduled to run exactly at the hard-expiry deadline.
        $task = new expire_waitlist_offer_adhoc();
        $task->set_custom_data(['offerid' => $offerobj->id]);
        $task->set_next_run_time($expiresat);
        \core\task\manager::queue_adhoc_task($task);

        $this->grant_confirmation_if_required($candidate, $optionid);

        $this->messaging->notify_offer($offerobj, $ruleid);
    }

    /**
     * Reads the rule's own interval (minutes, send_mail_interval's actiondata) and converts it to
     * seconds. Falls back to 0 if the rule can no longer be resolved.
     *
     * @param int $ruleid
     * @return int
     */
    private function offer_interval_seconds(int $ruleid): int {
        global $DB;
        $rulerecord = $DB->get_record('booking_rules', ['id' => $ruleid], '*', IGNORE_MISSING);
        if (empty($rulerecord)) {
            return 0;
        }
        $rulejson = json_decode($rulerecord->rulejson);
        $minutes = (int) ($rulejson->actiondata->interval ?? 60);
        return $minutes * MINSECS;
    }

    /**
     * Grants this candidate the "confirmed" flag on their waiting-list answer, if the option
     * requires waitlist confirmation AND automatically grants it on notification (W1-W3, Phase 3
     * gap fix - WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.4 confirmationonnotification):
     * this is what actually lets bo_availability/conditions/onwaitinglist.php allow the booking -
     * without it, an offered candidate would receive a mail but could never actually book.
     *
     * waitforconfirmation=0 (no confirmation required at all) and confirmationonnotification=0
     * ("don't auto-grant on notification") are both a no-op here - matches the old system exactly.
     * confirmationonnotification 1 ("for everyone") and 2 ("one at a time") are treated the same:
     * grant THIS candidate only, never touching anyone else's grant - K1 already caps how many
     * candidates are offered per reconcile() pass to the actual free capacity, so "never more
     * grants than can book" holds structurally, without needing to couple/revoke across
     * candidates (per Georg, 2026-08-21 - the old exclusive-mode auto-revoke is intentionally not
     * reproduced; a person's grant stays independently, manually adjustable via the existing
     * per-user confirmation UI regardless of what this method does).
     *
     * @param booking_waitlist_candidate $candidate
     * @param int $optionid
     * @return void
     */
    private function grant_confirmation_if_required(booking_waitlist_candidate $candidate, int $optionid): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->waitforconfirmation) || empty($settings->confirmationonnotification)) {
            return;
        }

        $answer = $DB->get_record('booking_answers', ['id' => $candidate->baid], '*', IGNORE_MISSING);
        if (empty($answer)) {
            return;
        }

        booking_option::write_user_answer_to_db(
            $answer->bookingid,
            $answer->frombookingid,
            $answer->userid,
            $answer->optionid,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $answer->id,
            null,
            MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
            "",
            MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED
        );
    }
}
