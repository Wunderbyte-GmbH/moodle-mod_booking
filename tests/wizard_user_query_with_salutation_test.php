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
 * A salutation glued to a resolved address does not hide the person.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\wizard\booking\booking_skill_support;

/**
 * Baseline run 25, DBI-3: "Madame elise.lefevre (at) example.org" reached the user resolver and was looked up
 * whole as an e-mail address (#2453, wave 19).
 *
 * @covers \mod_booking\local\wizard\booking\booking_skill_support
 */
final class wizard_user_query_with_salutation_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The person behind "Madame <e-mail>" is resolved.
     */
    public function test_the_address_inside_the_query_wins(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'elise.lefevre@example.org', 'lastname' => 'Lefèvre']);

        $resolved = booking_skill_support::resolve_users_for_restriction('Madame elise.lefevre@example.org');

        $this->assertSame([], $resolved['errors']);
        $this->assertSame([(int)$user->id], $resolved['userids']);
    }

    /**
     * Run 31, SVO-3 shape: a salutation before a unique surname resolves; two users sharing a token do not.
     */
    public function test_a_salutation_before_a_unique_name_resolves(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Okafor']);
        $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Nwosu']);

        $resolved = booking_skill_support::resolve_users_for_restriction('Mr Okafor');
        $this->assertSame([], $resolved['errors']);
        $this->assertSame([(int)$user->id], $resolved['userids']);

        $ambiguous = booking_skill_support::resolve_single_user('Herr Chidi');
        $this->assertSame('ambiguity', (string)($ambiguous['status'] ?? ''), 'two users share the first name - the skill asks');
    }

    /**
     * F81: no word means "me" any more; only the engine marker does. A word is looked up as a name.
     */
    public function test_only_the_engine_marker_means_the_requester(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $marker = booking_skill_support::resolve_users_for_restriction('__current_user__');
        $this->assertSame([(int)$USER->id], $marker['userids']);

        $word = booking_skill_support::resolve_users_for_restriction('mich');
        $this->assertNotSame([(int)$USER->id], $word['userids'], 'a word in some language is not the requester');
    }

    /**
     * #2569: the requester marker inside a person list resolves like every other entry (book_users, restrictions).
     */
    public function test_the_marker_inside_a_person_list_is_the_requester(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Okafor']);

        $list = booking_skill_support::REQUESTER_MARKER . ', ' . $other->email;
        $booking = booking_skill_support::resolve_users_for_booking($list);
        $this->assertSame([], $booking['errors']);
        $this->assertEqualsCanonicalizing([(int)$USER->id, (int)$other->id], $booking['userids']);

        $restriction = booking_skill_support::resolve_users_for_restriction($list);
        $this->assertEqualsCanonicalizing([(int)$USER->id, (int)$other->id], $restriction['userids']);
    }

    /**
     * #2569, P6a: several matches for one person come back as a choice (id and label), next to the old text.
     */
    public function test_an_ambiguous_person_comes_back_as_a_choice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Okafor']);
        $second = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Nwosu']);

        $ambiguous = booking_skill_support::resolve_single_user('Chidi');
        $this->assertSame('ambiguity', (string)($ambiguous['status'] ?? ''));
        $ids = array_map(static fn(array $c): int => (int)$c['id'], (array)($ambiguous['candidates'] ?? []));
        $this->assertEqualsCanonicalizing([(int)$first->id, (int)$second->id], $ids);
        $this->assertStringNotContainsString((string)$first->id, (string)($ambiguous['choice_message'] ?? ''));
        $this->assertNotSame('', trim((string)($ambiguous['choice_message'] ?? '')));
    }
}
