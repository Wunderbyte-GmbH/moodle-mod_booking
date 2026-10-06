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
use context_course;
use bookingextension_agent\local\wizard\core\skills\search_users_skill;

/**
 * A salutation in front of a name does not hide the person from core.search_users.
 *
 * DUC-3 (L50 thread 18996, an asset): the selector looked the person up first with "Madame <name>" and the search
 * found nobody, so the turn asked who was meant. The person resolver has narrowed such queries since wave 26
 * ("Mr Okafor"); the search now does the same: tokens that match someone must agree, a token that matches nobody
 * carries no meaning. Two people sharing the matching token stay both listed; a query no token of which matches
 * anybody finds nobody.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\search_users_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_users_salutation_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * A course with two students; the admin searches.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        foreach ([['Pia', 'Lefèvre'], ['Tom', 'Beaulieu']] as [$first, $last]) {
            $user = $this->getDataGenerator()->create_user(['firstname' => $first, 'lastname' => $last]);
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        }
    }

    /**
     * The user ids a search returns.
     *
     * @param string $query
     * @return int[]
     */
    private function found(string $query): array {
        global $USER;
        $result = (new search_users_skill())->execute(
            ['query' => $query],
            (int)context_course::instance($this->course->id)->id,
            (int)$USER->id
        );
        return array_map(static fn(array $u): int => (int)($u['userid'] ?? 0), (array)($result['users'] ?? []));
    }

    /**
     * "Madame Lefèvre" finds Lefèvre.
     */
    public function test_a_salutation_does_not_hide_the_person(): void {
        global $DB;
        $lefevre = (int)$DB->get_field('user', 'id', ['lastname' => 'Lefèvre']);
        $this->assertSame([$lefevre], $this->found('Madame Lefèvre'));
        $this->assertSame([$lefevre], $this->found('Mrs. Lefèvre'));
    }

    /**
     * Non-success paths: nobody matches any word, or several people match - nothing is guessed.
     */
    public function test_no_or_several_matches_stay_honest(): void {
        $this->assertSame([], $this->found('Madame Okonkwo'));
        $this->getDataGenerator()->enrol_user(
            $this->getDataGenerator()->create_user(['firstname' => 'Lea', 'lastname' => 'Lefèvre'])->id,
            $this->course->id,
            'student'
        );
        $this->assertCount(2, $this->found('Madame Lefèvre'));
    }
}
