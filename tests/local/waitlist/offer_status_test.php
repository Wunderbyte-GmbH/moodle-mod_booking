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
 * Tests for the offer_status state machine (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2) -
 * the first real production class of Phase 2 (Datenmodell + Reconciler). Pure logic, no DB
 * needed - the whole point of the State Pattern here is that transition validity is testable in
 * complete isolation.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\pending;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\offer_statuses\accepted;
use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\skipped;
use mod_booking\local\waitlist\offer_statuses\autobooked;

/**
 * Exhaustive transition/terminal/code tests for all seven offer_status implementations.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\offer_status
 * @covers \mod_booking\local\waitlist\offer_statuses\pending
 * @covers \mod_booking\local\waitlist\offer_statuses\offered
 * @covers \mod_booking\local\waitlist\offer_statuses\accepted
 * @covers \mod_booking\local\waitlist\offer_statuses\declined
 * @covers \mod_booking\local\waitlist\offer_statuses\expired
 * @covers \mod_booking\local\waitlist\offer_statuses\skipped
 * @covers \mod_booking\local\waitlist\offer_statuses\autobooked
 */
final class offer_status_test extends \advanced_testcase {
    /**
     * All seven concrete offer_status classes, for exhaustive all-pairs testing.
     *
     * @return array
     */
    private function all_status_classes(): array {
        return [
            pending::class,
            offered::class,
            accepted::class,
            declined::class,
            expired::class,
            skipped::class,
            autobooked::class,
        ];
    }

    /**
     * The seven valid transitions from the §2.2 state diagram - and nothing else is valid.
     *
     * @return array
     */
    public static function valid_transitions_provider(): array {
        return [
            'pending -> offered' => [pending::class, offered::class],
            'pending -> autobooked' => [pending::class, autobooked::class],
            'pending -> skipped' => [pending::class, skipped::class],
            'offered -> accepted' => [offered::class, accepted::class],
            'offered -> declined' => [offered::class, declined::class],
            'offered -> expired' => [offered::class, expired::class],
            'offered -> skipped' => [offered::class, skipped::class],
        ];
    }

    /**
     * Every documented valid transition must be allowed.
     *
     * @dataProvider valid_transitions_provider
     * @param string $fromclass
     * @param string $toclass
     */
    public function test_valid_transitions_are_allowed(string $fromclass, string $toclass): void {
        $from = new $fromclass();
        $to = new $toclass();
        $this->assertTrue(
            $from->can_transition_to($to),
            "{$fromclass} must allow a transition to {$toclass} (§2.2 state diagram)."
        );
    }

    /**
     * Exhaustive all-pairs check (7x7 = 49 combinations): every pairing that is NOT one of the
     * seven documented valid transitions - including every state transitioning to itself - must
     * be rejected. This is the structural proof behind K7: declined -> offered is just one of
     * the 42 combinations covered here, not a special case.
     */
    public function test_all_undocumented_transitions_are_rejected(): void {
        $valid = [];
        foreach (self::valid_transitions_provider() as [$fromclass, $toclass]) {
            $valid[$fromclass][$toclass] = true;
        }

        $allclasses = $this->all_status_classes();
        $checked = 0;
        foreach ($allclasses as $fromclass) {
            $from = new $fromclass();
            foreach ($allclasses as $toclass) {
                if (!empty($valid[$fromclass][$toclass])) {
                    continue;
                }
                $to = new $toclass();
                $this->assertFalse(
                    $from->can_transition_to($to),
                    "{$fromclass} must NOT allow a transition to {$toclass} - this pairing is " .
                    'not in the §2.2 state diagram.'
                );
                $checked++;
            }
        }
        // 7x7 = 49 total pairings, 7 are valid, so exactly 42 must have been checked here - a
        // sanity check that the exhaustive loop actually covered everything intended.
        $this->assertEquals(42, $checked, 'Precondition: exactly 42 non-valid pairings expected.');
    }

    /**
     * A1/B1's actual bug, tested directly and by name: declined must have ZERO outgoing
     * transitions - in particular declined -> offered (the u:rise bug) must be structurally
     * unreachable, not merely uncovered by the generic loop above.
     */
    public function test_declined_has_no_outgoing_transitions_k7(): void {
        $declined = new declined();
        foreach ($this->all_status_classes() as $toclass) {
            $to = new $toclass();
            $this->assertFalse(
                $declined->can_transition_to($to),
                "declined must not allow a transition to {$toclass} - K7 requires declined to " .
                'be a dead end, in particular declined -> offered must be unreachable.'
            );
        }
    }

    /**
     * Which states are terminal - only pending and offered are non-terminal.
     *
     * @return array
     */
    public static function terminal_provider(): array {
        return [
            'pending' => [pending::class, false],
            'offered' => [offered::class, false],
            'accepted' => [accepted::class, true],
            'declined' => [declined::class, true],
            'expired' => [expired::class, true],
            'skipped' => [skipped::class, true],
            'autobooked' => [autobooked::class, true],
        ];
    }

    /**
     * A status's is_terminal() must match the expected terminal/non-terminal classification.
     *
     * @dataProvider terminal_provider
     * @param string $statusclass
     * @param bool $expectedterminal
     */
    public function test_is_terminal(string $statusclass, bool $expectedterminal): void {
        $status = new $statusclass();
        $this->assertEquals(
            $expectedterminal,
            $status->is_terminal(),
            "{$statusclass}->is_terminal() must be " . ($expectedterminal ? 'true' : 'false') . '.'
        );
    }

    /**
     * The numeric codes fixed in the DB-Schema step (booking_waitlist_offers.status column).
     *
     * @return array
     */
    public static function code_provider(): array {
        return [
            'pending' => [pending::class, 0],
            'offered' => [offered::class, 1],
            'accepted' => [accepted::class, 2],
            'declined' => [declined::class, 3],
            'expired' => [expired::class, 4],
            'skipped' => [skipped::class, 5],
            'autobooked' => [autobooked::class, 6],
        ];
    }

    /**
     * A status's get_code() must match the DB-Schema step's fixed numeric mapping.
     *
     * @dataProvider code_provider
     * @param string $statusclass
     * @param int $expectedcode
     */
    public function test_get_code_matches_db_schema_mapping(string $statusclass, int $expectedcode): void {
        $status = new $statusclass();
        $this->assertEquals(
            $expectedcode,
            $status->get_code(),
            "{$statusclass}->get_code() must match the booking_waitlist_offers.status mapping " .
            'fixed in the DB-Schema step.'
        );
    }

    /**
     * Every status code (0-6) must be unique - two different classes returning the same code
     * would silently corrupt persistence/deserialisation.
     */
    public function test_all_codes_are_unique(): void {
        $codes = array_map(fn($c) => (new $c())->get_code(), $this->all_status_classes());
        $this->assertEquals(
            count($codes),
            count(array_unique($codes)),
            'Every offer_status implementation must have a unique get_code() value.'
        );
    }
}
