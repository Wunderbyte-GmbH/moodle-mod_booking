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
 * Tests for the structural target-query normalisation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\target_query_normalizer;

/**
 * Wave 19 (#2453): the two tolerances the resolvers were missing in baseline runs 25-27.
 *
 * @covers \bookingextension_agent\local\wizard\services\target_query_normalizer
 */
final class target_query_normalizer_test extends \basic_testcase {
    /**
     * A salutation, a name or punctuation around an address does not hide the address.
     */
    public function test_the_address_token_is_found_wherever_it_stands(): void {
        $this->assertSame(
            'wbtf_duval@example.invalid',
            target_query_normalizer::address_token('Madame wbtf_duval@example.invalid')
        );
        $this->assertSame('h.reisinger@firma.at', target_query_normalizer::address_token('Herr h.reisinger@firma.at.'));
        $this->assertSame('tom@example.org', target_query_normalizer::address_token('tom@example.org'));
        $this->assertSame('tom@example.org', target_query_normalizer::address_token('Tom (tom@example.org), please'));
    }

    /**
     * No address, or more than one, is no address.
     */
    public function test_none_or_several_addresses_yield_nothing(): void {
        $this->assertSame('', target_query_normalizer::address_token('Madame Duvernay'));
        $this->assertSame('', target_query_normalizer::address_token('a@x.org and b@x.org'));
        $this->assertSame('', target_query_normalizer::address_token(''));
    }

    /**
     * Hyphens, spaces and case do not make two names different.
     */
    public function test_a_name_key_ignores_hyphens_spaces_and_case(): void {
        $forum = target_query_normalizer::name_key('Vorstellungsforum');
        $this->assertSame($forum, target_query_normalizer::name_key('Vorstellungs-Forum'));
        $this->assertSame($forum, target_query_normalizer::name_key('vorstellungs forum'));
        $this->assertSame('übungsdaten', target_query_normalizer::name_key('Übungs-Daten'));
        $this->assertNotSame($forum, target_query_normalizer::name_key('Vorstellungsrunde'));
    }

    /**
     * A directory of three users, searched by substring - the shape every resolver offers.
     *
     * @return callable
     */
    private function directory(): callable {
        $users = [
            ['userid' => 11, 'firstname' => 'Chidi', 'lastname' => 'Okafor'],
            ['userid' => 12, 'firstname' => 'Amélie', 'lastname' => 'Duvernay'],
            ['userid' => 13, 'firstname' => 'Chidi', 'lastname' => 'Nwosu'],
        ];
        return static function (string $token, int $limit) use ($users): array {
            $hits = [];
            foreach ($users as $user) {
                if (stripos($user['firstname'] . ' ' . $user['lastname'], $token) !== false) {
                    $hits[] = $user;
                }
            }
            return array_slice($hits, 0, $limit);
        };
    }

    /**
     * Run 31, SVO-3: "Mr Okafor" - the salutation matches nobody, the name matches one.
     */
    public function test_a_token_nobody_matches_carries_no_meaning(): void {
        $found = target_query_normalizer::narrow_by_tokens('Mr Okafor', $this->directory());
        $this->assertCount(1, $found);
        $this->assertSame(11, (int)$found[0]['userid']);
    }

    /**
     * Two matching tokens must agree; the first name alone would be ambiguous.
     */
    public function test_matching_tokens_must_agree_on_one_user(): void {
        $this->assertSame(11, (int)target_query_normalizer::narrow_by_tokens('Chidi Okafor', $this->directory())[0]['userid']);
        $ambiguous = target_query_normalizer::narrow_by_tokens('Herr Chidi', $this->directory());
        $this->assertCount(2, $ambiguous, 'two users share the first name: the caller asks');
        $this->assertSame(
            [],
            target_query_normalizer::narrow_by_tokens('Chidi Duvernay', $this->directory()),
            'no user unites both'
        );
    }

    /**
     * One incidental hit inside a long unrelated query is not a match: at least half of the tokens must be carried.
     */
    public function test_an_incidental_hit_in_a_long_query_does_not_resolve(): void {
        $directory = static function (string $token, int $limit): array {
            return stripos('Test course 1', $token) !== false ? [['id' => 7, 'name' => 'Test course 1']] : [];
        };
        $this->assertSame([], target_query_normalizer::narrow_by_tokens('Course That Does Not Exist Zz421337', $directory));
        $this->assertSame(7, (int)target_query_normalizer::narrow_by_tokens('Test-Kurs', $directory)[0]['id'], 'one of two tokens');
    }

    /**
     * One token, an address or a number is not a case for token narrowing.
     */
    public function test_single_tokens_addresses_and_numbers_are_left_to_the_callers(): void {
        $this->assertSame([], target_query_normalizer::narrow_by_tokens('Okafor', $this->directory()));
        $this->assertSame([], target_query_normalizer::narrow_by_tokens('Mr', $this->directory()));
        $this->assertSame([], target_query_normalizer::narrow_by_tokens('Madame wbtf_duval@example.invalid', $this->directory()));
        $this->assertSame([], target_query_normalizer::narrow_by_tokens('user 4021', $this->directory()));
    }
}
