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
 * Composition root (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6) - the only place that knows
 * concrete implementations, preventing the "new X() scattered across the code" pattern that let
 * today's two parallel chains drift apart in the first place. Builds a fresh progression on every
 * call rather than caching a static instance - construction is cheap (no DB I/O of its own), and
 * this avoids any risk of stale state leaking across PHPUnit test methods.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Wires a ready-to-use progression instance.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progression_factory {
    /**
     * Builds a fully-wired progression instance.
     *
     * @return progression
     */
    public static function get(): progression {
        $repository = new db_waitlist_offer_repository();
        return new progression(
            $repository,
            new price_based_decision_strategy(),
            new capacity_calculator($repository),
            new rule_condition_checker(),
            new moodle_messaging_gateway()
        );
    }
}
