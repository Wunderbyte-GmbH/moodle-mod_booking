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
 * Price-based decision strategy (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.1): AUTOBOOK if
 * the candidate's price resolves to 0 (K3), OFFER otherwise (K4). The price is looked up FRESH
 * on every call (P1 - decided at treatment time, never cached, unlike
 * singleton_service::get_pricecategory_for_user()'s per-instance cache that A9 found). A price
 * lookup with no resolvable 'price' key (P2, e.g. pricecategoryfallback=2 with no matching
 * category) is treated exactly like price 0, with no PHP warning, via the `??` guard - the same
 * pattern A8 already confirmed correct in the current codebase's
 * waitinglist_sync_status::paid_option_skips_user().
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\price;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Decides AUTOBOOK vs. OFFER purely from the candidate's current price.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class price_based_decision_strategy implements booking_decision_strategy {
    /**
     * Decides the outcome for one candidate, based on a fresh price lookup.
     *
     * @param booking_waitlist_candidate $candidate
     * @return booking_decision
     */
    public function decide(booking_waitlist_candidate $candidate): booking_decision {
        $priceinfo = price::get_price('option', $candidate->optionid, $candidate->user);
        $pricevalue = (float) ($priceinfo['price'] ?? 0);

        if ($pricevalue === 0.0) {
            return booking_decision::AUTOBOOK;
        }

        return booking_decision::OFFER;
    }
}
