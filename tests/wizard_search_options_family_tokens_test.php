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

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\local\wizard\options\skills\search_options_skill;

/**
 * A family named in the plural or as a compound ("Kochkurse", "Yoga-Kurse") finds the options whose title carries
 * the family word when the whole query matches nothing; a query without any matching word still finds nothing
 * (Wunderbyte-GmbH#2730).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_search_options_family_tokens_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Two cooking options and one yoga option in a fresh booking instance.
     *
     * @return int The context id of the booking module (what the skill receives as cmid).
     */
    private function create_family_instance(): int {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Family booking']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['Kochkurs für Anfänger', 'Kochkurs Vegan', 'Yoga im Park'] as $title) {
            $gen->create_option((object)[
                'bookingid' => $booking->id,
                'text' => $title,
                'chooseorcreatecourse' => 0,
                'limitanswers' => 0,
                'maxanswers' => 0,
            ]);
        }
        return (int)context_module::instance($booking->cmid)->id;
    }

    /**
     * Run the search and return the option names found.
     *
     * @param int $contextid
     * @param string $query
     * @return array{0:array,1:array} Sorted names and the whole result.
     */
    private function search(int $contextid, string $query): array {
        global $USER;
        $result = (new search_options_skill())->execute(['query' => $query], $contextid, (int)$USER->id);
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $names = array_map(static fn(array $option): string => (string)$option['name'], (array)($result['options'] ?? []));
        sort($names);
        return [$names, $result];
    }

    /**
     * The plural "Kochkurse" finds both "Kochkurs …" options and not the yoga option.
     */
    public function test_plural_family_word_finds_the_family(): void {
        $this->setAdminUser();
        $contextid = $this->create_family_instance();
        [$names] = $this->search($contextid, 'Kochkurse');
        $this->assertSame(['Kochkurs Vegan', 'Kochkurs für Anfänger'], $names);
    }

    /**
     * The compound "Yoga-Kurse" finds "Yoga im Park": the token "Yoga" matches, "Kurse" matches nothing and carries
     * no meaning.
     */
    public function test_hyphenated_compound_finds_the_family(): void {
        $this->setAdminUser();
        $contextid = $this->create_family_instance();
        [$names] = $this->search($contextid, 'Yoga-Kurse');
        $this->assertSame(['Yoga im Park'], $names);
    }

    /**
     * A query none of whose words meets any title still ends as nothing found; no guessing.
     */
    public function test_unrelated_query_still_finds_nothing(): void {
        $this->setAdminUser();
        $contextid = $this->create_family_instance();
        [$names, $result] = $this->search($contextid, 'Schwimmkurse am Abend');
        $this->assertSame([], $names);
        $this->assertStringContainsString('Results: 0', (string)$result['debugmessage']);
    }
}
