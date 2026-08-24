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
 * Test double for C3 (c3_live_price_change_mid_batch_test.php): a decision-strategy decorator
 * that changes ANOTHER candidate's price-category profile field the moment the first candidate
 * in the batch is decided - simulating a real affiliation change (e.g. student -> employee)
 * landing exactly between the round's start and that other candidate's own turn in the same
 * reconcile() loop. There is no way to make a real, separate process change a user's profile
 * mid-call in a single PHPUnit process, so this hooks the one seam progression.php actually calls
 * per candidate (booking_decision_strategy::decide()) to interleave the mutation at the right
 * moment, then delegates the real decision to the real price_based_decision_strategy throughout.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;

/**
 * Delegates every decide() call to the real strategy. The FIRST time decide() is called for
 * $triggeruserid, it also updates $changinguserid's profile price-category field to
 * $newpricecat and forces a full singleton_service reset (matching the existing P1
 * live-re-lookup test technique) before returning control - simulating an external affiliation
 * change landing mid-batch.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class c3_mid_batch_affiliation_change_strategy implements booking_decision_strategy {
    /** @var booking_decision_strategy */
    private $inner;

    /** @var int */
    private $triggeruserid;

    /** @var int */
    private $changinguserid;

    /** @var string */
    private $newpricecat;

    /** @var bool */
    private $applied = false;

    /**
     * Constructs the decorator.
     *
     * @param booking_decision_strategy $inner
     * @param int $triggeruserid the candidate whose OWN decide() call triggers the mutation
     * @param int $changinguserid the candidate whose profile field is changed mid-batch
     * @param string $newpricecat
     */
    public function __construct(
        booking_decision_strategy $inner,
        int $triggeruserid,
        int $changinguserid,
        string $newpricecat
    ) {
        $this->inner = $inner;
        $this->triggeruserid = $triggeruserid;
        $this->changinguserid = $changinguserid;
        $this->newpricecat = $newpricecat;
    }

    /**
     * Delegates to the real strategy; injects the mid-batch mutation exactly once, right after
     * the trigger candidate's own decision has already been made.
     *
     * @param booking_waitlist_candidate $candidate
     * @return booking_decision
     */
    public function decide(booking_waitlist_candidate $candidate): booking_decision {
        $decision = $this->inner->decide($candidate);

        if (!$this->applied && $candidate->userid === $this->triggeruserid) {
            $this->applied = true;
            $updateduser = new \stdClass();
            $updateduser->id = $this->changinguserid;
            $updateduser->profile_field_pricecat = $this->newpricecat;
            \profile_save_data($updateduser);
            // Same technique as price_based_decision_strategy_test.php's P1 test: only a full
            // instance reset picks up the DB change here.
            singleton_service::destroy_instance();
        }

        return $decision;
    }
}
