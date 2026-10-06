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
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\options\skills\add_price_category_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill;
use mod_booking\local\wizard\options\skills\list_option_properties_skill;
use mod_booking\local\wizard\options\skills\search_options_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Wave 32, group B2: skill-only fixes for the booking prompts that were not clean in every run.
 *
 * Each test names the run and thread whose failure it pins (analysis files in
 * secret_docs/bookingextension_agent/w32_skill_analysis/). Written test-first as a proposal; not run.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\add_price_category_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_option_properties_skill
 */
final class wizard_w32_b2_skill_fixes_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int Course-module id. */
    private int $cmid = 0;

    /** @var int Booking instance id. */
    private int $bookingid = 0;

    /**
     * Setup: one booking activity.
     */
    protected function setUp(): void {
        $this->skip_without_agent_extension();
        parent::setUp();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'W32 B2']);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;
    }

    /**
     * Create an option with one session.
     *
     * @param string $title
     * @param int $start Session start.
     * @param array $extra Further option fields.
     * @return int Option id.
     */
    private function option(string $title, int $start, array $extra = []): int {
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $record = (object)array_merge([
            'bookingid' => $this->bookingid,
            'text' => $title,
            'importing' => 1,
            'optiondateid_0' => 0,
            'coursestarttime_0' => $start,
            'courseendtime_0' => $start + HOURSECS,
        ], $extra);
        $optionid = (int)$generator->create_option($record)->id;
        singleton_service::destroy_instance();
        return $optionid;
    }

    /**
     * BKU-1 (all ten runs, e.g. L41 thread 12579): "Peter" is the first name of one person; others merely
     * contain the string. The exact first name wins; the substring matches no longer make it a question.
     */
    public function test_a_query_equal_to_one_first_name_resolves_that_person(): void {
        $gen = $this->getDataGenerator();
        $peter = $gen->create_user(['firstname' => 'Peter', 'lastname' => 'Brandl', 'email' => 'pb@example.org']);
        $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Petersen', 'email' => 'ap@example.org']);
        $gen->create_user(['firstname' => 'Max', 'lastname' => 'Huber', 'email' => 'peter.office@example.org']);

        $result = booking_skill_support::resolve_single_user('Peter');

        $this->assertSame('ok', $result['status'] ?? '', json_encode($result));
        $this->assertSame((int)$peter->id, (int)$result['userid']);
    }

    /**
     * Two people with the same exact first name stay a question with both of them.
     */
    public function test_two_exact_first_names_stay_a_question(): void {
        $gen = $this->getDataGenerator();
        $gen->create_user(['firstname' => 'Peter', 'lastname' => 'Brandl']);
        $gen->create_user(['firstname' => 'Peter', 'lastname' => 'Oberhofer']);

        $result = booking_skill_support::resolve_single_user('Peter');

        $this->assertSame('ambiguity', $result['status'] ?? '', json_encode($result));
    }

    /**
     * A suspended namesake does not count against the one active person.
     */
    public function test_a_suspended_namesake_is_not_a_second_match(): void {
        $gen = $this->getDataGenerator();
        $active = $gen->create_user(['firstname' => 'Jorinde', 'lastname' => 'Aigner']);
        $gen->create_user(['firstname' => 'Jorinde', 'lastname' => 'Wallner', 'suspended' => 1]);

        $result = booking_skill_support::resolve_single_user('Jorinde');

        $this->assertSame('ok', $result['status'] ?? '', json_encode($result));
        $this->assertSame((int)$active->id, (int)$result['userid']);
    }

    /**
     * UO-1 (all ten runs, e.g. L41 thread 12559): an option named by optionquery with one session is moved by
     * shiftdays; before, the shift ran before the resolution and always reported "no sessions".
     */
    public function test_shiftdays_moves_an_option_named_by_query(): void {
        $start = strtotime('2046-09-27 18:00');
        $optionid = $this->option('Töpferkurs am Donnerstag', $start);

        $preflight = (new update_option_skill())->preflight(
            ['optionquery' => 'Töpferkurs', 'shiftdays' => 7],
            \context_module::instance($this->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertNotContains('UPDATE_OPTION_NO_SESSIONS_TO_SHIFT', $preflight->issuecodes, json_encode($preflight->to_array()));
        $prepared = $preflight->preparedinput;
        $this->assertSame($optionid, (int)($prepared['optionid'] ?? 0));
        $this->assertSame('replace', (string)($prepared['optiondatesmode'] ?? ''));
        $this->assertCount(1, (array)($prepared['optiondates'] ?? []));
    }

    /**
     * Non-success path of UO-1: an option named by query that has no dates yet cannot be moved. That is a question
     * (needs_clarification) with the skill's own text - no issue code in the text, no hard error.
     */
    public function test_shiftdays_on_an_option_without_dates_is_a_question(): void {
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $generator->create_option((object)['bookingid' => $this->bookingid, 'text' => 'Töpferkurs ohne Termin']);
        singleton_service::destroy_instance();

        $preflight = (new update_option_skill())->preflight(
            ['optionquery' => 'Töpferkurs ohne Termin', 'shiftdays' => 7],
            \context_module::instance($this->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertContains('UPDATE_OPTION_NO_SESSIONS_TO_SHIFT', $preflight->issuecodes, json_encode($preflight->to_array()));
        foreach ($preflight->issues as $issue) {
            if (($issue['code'] ?? '') !== 'UPDATE_OPTION_NO_SESSIONS_TO_SHIFT') {
                continue;
            }
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
            $this->assertNotSame('', trim((string)($issue['message'] ?? '')));
            $this->assertStringNotContainsString('UPDATE_OPTION_NO_SESSIONS_TO_SHIFT', (string)$issue['message']);
        }
    }

    /**
     * APC-1 (L38 thread 11243, call 71807): the tariff is named but no technical key is given; the skill derives it.
     */
    public function test_a_price_category_needs_only_its_name(): void {
        $skill = new add_price_category_skill();

        $structure = $skill->check_structure(['name' => 'Lehrlinge']);
        $this->assertTrue((bool)($structure['valid'] ?? false), json_encode($structure));

        $preflight = $skill->preflight(['name' => 'Lehrlinge'], \context_system::instance()->id, (int)get_admin()->id);
        $prepared = $preflight->preparedinput;
        $this->assertSame('lehrlinge', (string)($prepared['identifier'] ?? ''), json_encode($preflight->to_array()));

        $this->assertSame('tarif_etudiant', add_price_category_skill::identifier_from_input(['name' => 'Tarif étudiant']));
        $this->assertSame('partner', add_price_category_skill::identifier_from_input(['identifier' => 'partner', 'name' => 'X Y']));
        $this->assertFalse((bool)($skill->check_structure([])['valid'] ?? true), 'nothing named is still a question');

        // The confirmation card shows the key the skill will store, also when the command carries only the name.
        $card = (array)$skill->describe_proposed_action(['name' => 'Lehrlinge']);
        $values = array_map(static fn(array $row): string => (string)($row['value'] ?? ''), (array)($card['rows'] ?? []));
        $this->assertContains('lehrlinge', $values, json_encode($card));
    }

    /**
     * DCI-1 (L40 thread 12209, L41 thread 12633): a phrase that matches no title ("pilates course" at the time; a
     * single title word now resolves, #2572). The skill offers the options as choices, the one the person is booked
     * in first - never a bare error.
     */
    public function test_a_cancellation_miss_offers_the_booked_option_first(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->option('Yoga im Park', strtotime('+10 days'));
        $pilates = $this->option('Pilates am Abend', strtotime('+20 days'));
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $generator->create_answer(['optionid' => $pilates, 'userid' => (int)$user->id]);
        singleton_service::destroy_instance();
        $this->setUser($user);

        $preflight = (new diagnose_cancellation_issue_skill())->preflight(
            ['question' => 'I want out of the course', 'optionquery' => 'the course I booked'],
            \context_module::instance($this->cmid)->id,
            (int)$user->id
        );

        $choice = $this->first_issue_with_candidates($preflight->issues);
        $this->assertNotNull($choice, json_encode($preflight->to_array()));
        $this->assertSame('optionid', (string)$choice['field']);
        $this->assertSame($pilates, (int)$choice['candidates'][0]['id']);
        $this->assertArrayHasKey('isoweekday', $choice['candidates'][0]);
        // A question, not an error; the text is the skill's own and carries neither the code nor the user's words.
        $this->assertSame('needs_clarification', (string)$choice['severity']);
        $this->assertStringNotContainsString((string)$choice['code'], (string)$choice['message']);
        $this->assertStringNotContainsString('the course I booked', (string)$choice['message']);
    }

    /**
     * DWL-2 (L30/L33/L35/L37/L38, e.g. L37 thread 11041): "die Wanderung" matches no title. The miss is a
     * preflight question with the options (waiting-list options first), not an execute error.
     */
    public function test_a_waitinglist_miss_offers_choices_in_preflight(): void {
        $this->option('Kochkurs Italienisch', strtotime('+5 days'));
        $hike = $this->option('Herbstwanderung Wienerwald', strtotime('+15 days'), ['maxanswers' => 5, 'maxoverbooking' => 3]);

        $preflight = (new diagnose_waitinglist_skill())->preflight(
            ['optionquery' => 'die Wanderung'],
            \context_module::instance($this->cmid)->id,
            (int)get_admin()->id
        );

        $choice = $this->first_issue_with_candidates($preflight->issues);
        $this->assertNotNull($choice, json_encode($preflight->to_array()));
        $this->assertSame($hike, (int)$choice['candidates'][0]['id']);
    }

    /**
     * Non-success path of DWL-2: an activity without options has nothing to offer. The miss stays a question
     * without candidates, never an execute error.
     */
    public function test_a_waitinglist_miss_without_options_is_a_question_without_choices(): void {
        $preflight = (new diagnose_waitinglist_skill())->preflight(
            ['optionquery' => 'die Wanderung'],
            \context_module::instance($this->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status, json_encode($preflight->to_array()));
        $this->assertContains('DIAGNOSE_WAITINGLIST_OPTION_NOT_FOUND', $preflight->issuecodes);
        $this->assertNull($this->first_issue_with_candidates($preflight->issues));
        $this->assertSame('needs_clarification', (string)($preflight->issues[0]['severity'] ?? ''));
    }

    /**
     * A resolvable waiting-list query still passes preflight with the resolved id.
     */
    public function test_a_waitinglist_hit_passes_with_the_option_id(): void {
        $hike = $this->option('Herbstwanderung Wienerwald', strtotime('+15 days'));

        $preflight = (new diagnose_waitinglist_skill())->preflight(
            ['optionquery' => 'Wanderung'],
            \context_module::instance($this->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertSame('pass', $preflight->status, json_encode($preflight->to_array()));
        $this->assertSame($hike, (int)($preflight->preparedinput['optionid'] ?? 0));
    }

    /**
     * SO-4 (L41 thread 12763, call 80549): a period ("im Herbst") is a first and a last day; options inside it
     * are listed, options outside are not.
     */
    public function test_search_options_filters_a_period(): void {
        $in = $this->option('Herbstlauf', strtotime('2046-10-15 10:00'));
        $out = $this->option('Sommerfest', strtotime('2046-07-15 10:00'));

        $rows = booking_skill_support::search_option_candidates_for_preview($this->cmid, '', 50, '2046-09-23', false, '2046-12-21');
        $ids = array_map(static fn(array $row): int => (int)$row['optionid'], $rows);

        $this->assertContains($in, $ids);
        $this->assertNotContains($out, $ids);

        // A last day before the first day is no period: the search stays on the single day.
        $rows = booking_skill_support::search_option_candidates_for_preview($this->cmid, '', 50, '2046-10-15', false, '2046-09-01');
        $ids = array_map(static fn(array $row): int => (int)$row['optionid'], $rows);
        $this->assertContains($in, $ids);
        $rows = booking_skill_support::search_option_candidates_for_preview($this->cmid, '', 50, '2046-10-16', false, '2046-09-01');
        $ids = array_map(static fn(array $row): int => (int)$row['optionid'], $rows);
        $this->assertNotContains($in, $ids);
        $properties = (array)((new search_options_skill())->get_schema()['properties'] ?? []);
        $this->assertArrayHasKey('whenuntil', $properties);
    }

    /**
     * CSB-3 (L41 call 79344, L43 thread 13130 call 82786), CSL-1 (L40 call 76859, L43 thread 13119 call 82735):
     * the rewritten field descriptions of the narrowed creation cards, the price category and the search are
     * printed WHOLE on the constructor card. The card cuts every description after 159 characters (agent
     * skill_input_schema_projection::MAX_DESCRIPTION_CHARS = 160); a rule behind the cut never reaches the
     * model. Structural invariant (length), no wording check.
     */
    public function test_rewritten_field_descriptions_fit_the_card_window(): void {
        $window = 159;
        $cases = [
            [new create_slotbooking_option_skill(), ['text', 'activityquery', 'cmid']],
            [new create_selflearning_option_skill(), ['text', 'activityquery', 'cmid']],
            [new add_price_category_skill(), ['identifier', 'name']],
            [new search_options_skill(), ['when', 'whenuntil']],
        ];
        foreach ($cases as [$skill, $fields]) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $properties, get_class($skill));
                $description = (string)preg_replace('/\s+/u', ' ', (string)($properties[$field]['description'] ?? ''));
                $this->assertNotSame('', $description, get_class($skill) . ':' . $field);
                $this->assertLessThanOrEqual($window, \core_text::strlen($description), get_class($skill) . ':' . $field);
            }
        }
        // The rewritten WHEN line of list_option_properties fits its 180-character budget.
        foreach ((new list_option_properties_skill())->get_message_triggers() as $trigger) {
            $when = (string)preg_replace('/\s+/u', ' ', (string)($trigger['description'] ?? ''));
            $this->assertLessThanOrEqual(180, \core_text::strlen($when));
        }
        // The creation cards keep their contract: the title stays required, activity and cmid stay optional.
        foreach ([new create_slotbooking_option_skill(), new create_selflearning_option_skill()] as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertTrue((bool)($properties['text']['required'] ?? false));
            $this->assertFalse((bool)($properties['activityquery']['required'] ?? true));
            $this->assertFalse((bool)($properties['cmid']['required'] ?? true));
        }
    }

    /**
     * First issue that carries structured choices.
     *
     * @param array $issues
     * @return array|null
     */
    private function first_issue_with_candidates(array $issues): ?array {
        foreach ($issues as $issue) {
            if (is_array($issue) && !empty($issue['candidates'])) {
                return $issue;
            }
        }
        return null;
    }
}
