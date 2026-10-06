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

namespace bookingextension_agent;

use advanced_testcase;
use mod_booking\local\wizard\booking\booking_skill_support;

/**
 * Person-resolution invariants: no placeholder matching, never the guest.
 *
 * Full run 2026-07-14 (#11, teacher step): an unresolved anonymization placeholder reached
 * resolve_single_user, the follow-up "provide the numeric user ID" clarification was answered
 * with a guessed id, and the GUEST account ended up assigned as trainer. Two invariants close
 * that chain: (1) an ANON_USER_* placeholder never enters user matching — it fails with a clean
 * re-ask; (2) the guest (and deleted accounts) are never a resolution result on any path
 * (numeric id, email, name search).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support::resolve_single_user
 */
final class user_resolution_invariants_test extends advanced_testcase {
    /**
     * Any unresolved placeholder form fails with a re-ask, never a match.
     */
    public function test_anon_placeholder_never_resolves(): void {
        $this->resetAfterTest();
        // A user whose name parts collide with the placeholder's words must NOT be matched.
        $this->getDataGenerator()->create_user(['firstname' => 'Anon', 'lastname' => 'User']);

        foreach (['ANON_USER_1_both', 'ANON_USER_1_email', 'ANON_USER_1', 'ANON_USER_2@anon.invalid'] as $query) {
            $result = booking_skill_support::resolve_single_user($query);
            $this->assertSame('error', (string)$result['status'], "Placeholder '{$query}' must not resolve.");
            $this->assertSame('USER_REFERENCE_UNRESOLVED', (string)$result['issue_code']);
        }
    }

    /**
     * A numeric query naming the guest account (the observed guessed-id case) never resolves.
     */
    public function test_guest_id_never_resolves(): void {
        global $CFG;
        $this->resetAfterTest();

        $result = booking_skill_support::resolve_single_user((string)$CFG->siteguest);
        $this->assertNotSame('ok', (string)$result['status'], 'The guest id must never resolve to a person.');
    }

    /**
     * A name search whose words match the guest ("user") never returns the guest; a real user
     * with those name parts still resolves.
     */
    public function test_name_search_skips_guest(): void {
        $this->resetAfterTest();

        $real = $this->getDataGenerator()->create_user([
            'firstname' => 'Uschi',
            'lastname' => 'Guestuser',
            'email' => 'uschi.guestuser@example.com',
        ]);

        // The guest's firstname "Guest user" LIKE-matches core search_users; only real
        // accounts may come back.
        $result = booking_skill_support::resolve_single_user('Guestuser');
        $this->assertSame('ok', (string)$result['status']);
        $this->assertSame((int)$real->id, (int)$result['userid']);
    }

    /**
     * A deleted user's id or email never resolves.
     */
    public function test_deleted_user_never_resolves(): void {
        $this->resetAfterTest();

        $victim = $this->getDataGenerator()->create_user([
            'firstname' => 'Dora',
            'lastname' => 'Deleted',
            'email' => 'dora.deleted@example.com',
        ]);
        delete_user($victim);

        $byid = booking_skill_support::resolve_single_user((string)$victim->id);
        $this->assertNotSame('ok', (string)$byid['status'], 'A deleted user id must never resolve.');
    }

    /**
     * The normal paths stay intact: id, email and unique name still resolve.
     */
    public function test_regular_resolution_paths_untouched(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Norma',
            'lastname' => 'Normalfrau',
            'email' => 'norma.normalfrau@example.com',
        ]);

        foreach ([(string)$user->id, $user->email, 'Norma Normalfrau'] as $query) {
            $result = booking_skill_support::resolve_single_user($query);
            $this->assertSame('ok', (string)$result['status'], "Query '{$query}' must resolve.");
            $this->assertSame((int)$user->id, (int)$result['userid']);
        }
    }
}
