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
use mod_booking\local\wizard\booking\booking_skill_support;

/**
 * A multi-word option query that matches no title as a whole is resolved by its words.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\booking\booking_skill_support
 */
final class wizard_option_query_words_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int */
    private int $cmid = 0;

    /** @var array<string,int> option ids by title */
    private array $options = [];

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Words booking']);
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['Pilates am Abend', 'Yoga im Park', 'Kochkurs Italienisch'] as $title) {
            $option = $gen->create_option((object) [
                'bookingid' => $booking->id,
                'courseid' => $course->id,
                'text' => $title,
                'description' => $title,
                'chooseorcreatecourse' => 0,
            ]);
            $this->options[$title] = (int)$option->id;
        }
        $this->cmid = (int)$booking->cmid;
        context_module::instance($this->cmid);
    }

    /**
     * The phrase as a whole matches nothing; one of its words matches exactly one option.
     */
    public function test_a_phrase_with_one_matching_word_resolves_that_option(): void {
        $result = booking_skill_support::resolve_single_option($this->cmid, 'Tuesday evening pilates session');

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame($this->options['Pilates am Abend'], (int)$result['optionid']);
    }

    /**
     * Words that match different options stay a choice between those options, not a miss.
     */
    public function test_words_matching_different_options_stay_a_choice(): void {
        $result = booking_skill_support::resolve_single_option($this->cmid, 'Yoga oder Pilates');

        $this->assertSame('ambiguity', $result['status'], json_encode($result));
        $this->assertSame('OPTION_AMBIGUOUS', $result['issue_code']);
    }

    /**
     * Only a whole word of a title counts: a word buried in a longer title word does not.
     */
    public function test_a_word_inside_a_longer_title_word_does_not_count(): void {
        $result = booking_skill_support::resolve_single_option($this->cmid, 'Kurs am Abend');

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame($this->options['Pilates am Abend'], (int)$result['optionid']);
    }

    /**
     * A word that every option of the instance carries says nothing and is ignored.
     */
    public function test_a_word_shared_by_every_option_is_ignored(): void {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Shared word']);
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['Yoga Kurs', 'Pilates Kurs'] as $title) {
            $gen->create_option((object) [
                'bookingid' => $booking->id,
                'courseid' => $course->id,
                'text' => $title,
                'description' => $title,
                'chooseorcreatecourse' => 0,
            ]);
        }
        singleton_service::destroy_instance();
        $cmid = (int)$booking->cmid;
        context_module::instance($cmid);

        $miss = booking_skill_support::resolve_single_option($cmid, 'Kurs am Abend');
        $this->assertSame('error', $miss['status'], json_encode($miss));
        $this->assertSame('OPTION_NOT_FOUND', $miss['issue_code']);

        $hit = booking_skill_support::resolve_single_option($cmid, 'Yoga Kurs am Abend');
        $this->assertSame('ok', $hit['status'], json_encode($hit));
    }

    /**
     * A whole-title match and a true miss behave as before.
     */
    public function test_a_title_and_a_miss_behave_as_before(): void {
        $hit = booking_skill_support::resolve_single_option($this->cmid, 'Kochkurs');
        $this->assertSame('ok', $hit['status']);
        $this->assertSame($this->options['Kochkurs Italienisch'], (int)$hit['optionid']);

        $miss = booking_skill_support::resolve_single_option($this->cmid, 'zzz definitiv nirgends vorhanden');
        $this->assertSame('error', $miss['status']);
        $this->assertSame('OPTION_NOT_FOUND', $miss['issue_code']);
    }
}
