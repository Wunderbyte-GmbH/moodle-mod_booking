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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;
use mod_booking\local\wizard\options\skills\list_instance_settings_skill;
use mod_booking\utils\wb_payment;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The view settings of a booking instance through configure_booking_instance (Wunderbyte-GmbH/Wunderbyte-GmbH#2495).
 *
 * viewparam, switchtemplates and switchtemplatesselection live in the instance JSON and take view ids. A value that
 * is not a view id is answered with the views as offered choices (engine path PREFLIGHT_CHOICES_OFFERED), a view
 * beyond the list view on a site without Booking PRO with the PRO hint, never with an error.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_instance_settings_skill
 */
final class wizard_configure_instance_view_settings_test extends booking_advanced_testcase {
    /** @var int Booking instance id. */
    private int $bookingid = 0;

    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    /**
     * Setup: one booking instance in list view with a JSON setting that must survive.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'View Test', 'bookingmanager' => 'admin',
        ]);
        $this->bookingid = (int)$booking->id;
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $DB->set_field('booking', 'json', json_encode(['viewparam' => 0, 'disablecancel' => 1]), ['id' => $this->bookingid]);
        booking::purge_cache_for_booking_instance_by_cmid($this->cmid);
    }

    /**
     * Release the PRO override.
     */
    protected function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * Preflight one change.
     *
     * @param string $field
     * @param mixed $value
     * @return \stdClass Preflight DTO.
     */
    private function preflight_change(string $field, $value) {
        global $USER;
        return (new configure_booking_instance_skill())->preflight(
            ['action' => 'update', 'changes' => [['field' => $field, 'value' => $value]], 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
    }

    /**
     * Preflight and execute changes, as the engine does after the confirmation.
     *
     * @param array $changes [{field, value}]
     * @return array Stored instance JSON.
     */
    private function apply(array $changes): array {
        global $DB, $USER;
        $skill = new configure_booking_instance_skill();
        $dto = $skill->preflight(
            ['action' => 'update', 'changes' => $changes, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $result = $skill->execute((array)$dto->preparedinput, $this->contextid, (int)$USER->id);
        $this->assertSame('executed', $result['status'], json_encode($result));
        return (array)json_decode((string)$DB->get_field('booking', 'json', ['id' => $this->bookingid]), true);
    }

    /**
     * Issues of a preflight DTO as arrays.
     *
     * @param \stdClass $dto
     * @return array
     */
    private static function issues($dto): array {
        return json_decode(json_encode($dto->issues), true);
    }

    /**
     * With PRO, the cards view is set by its id; the other JSON settings stay.
     */
    public function test_cards_view_is_set_by_its_id(): void {
        wb_payment::override_pro_version_for_tests(true);
        $json = $this->apply([['field' => 'viewparam', 'value' => (string)MOD_BOOKING_VIEW_PARAM_CARDS]]);
        $this->assertSame(MOD_BOOKING_VIEW_PARAM_CARDS, (int)$json['viewparam']);
        $this->assertSame(1, (int)$json['disablecancel'], 'an unrelated JSON setting stays');
    }

    /**
     * A word instead of a view id offers every view as a choice for the value's path; the user text names the
     * setting by its label, never by its identifier or an issue code.
     */
    public function test_a_word_offers_the_views_as_choices(): void {
        wb_payment::override_pro_version_for_tests(true);
        $dto = $this->preflight_change('viewparam', 'cards');
        $this->assertNotContains((string)$dto->status, ['pass', 'soft_block']);
        $issue = self::issues($dto)[0] ?? [];
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''), json_encode($issue));
        $this->assertSame('CONFIGURE_INSTANCE_VALUE_NOT_A_CHOICE', (string)($issue['code'] ?? ''));
        $this->assertSame('changes[0].value', (string)($issue['field'] ?? ''));
        $this->assertSame(
            array_keys(booking::get_array_of_all_views()),
            array_map(static fn(array $c): int => (int)$c['id'], (array)($issue['candidates'] ?? []))
        );
        $question = (string)($issue['user_question'] ?? '');
        $this->assertStringContainsString(get_string('viewparam:cards', 'mod_booking'), $question);
        $this->assertStringContainsString(get_string('viewparam', 'mod_booking'), $question);
        $this->assertStringNotContainsString('viewparam', $question, 'no field identifier in the user text');
        $this->assertStringNotContainsString('CONFIGURE_', $question, 'no issue code in the user text');
    }

    /**
     * An id that is no view, or several ids for the single default view, is also answered with the choices.
     */
    public function test_an_unknown_id_or_several_ids_offer_the_choices(): void {
        wb_payment::override_pro_version_for_tests(true);
        foreach (['99', '0,1'] as $value) {
            $issue = self::issues($this->preflight_change('viewparam', $value))[0] ?? [];
            $this->assertSame('CONFIGURE_INSTANCE_VALUE_NOT_A_CHOICE', (string)($issue['code'] ?? ''), $value);
        }
    }

    /**
     * Without PRO a view beyond the list view ends as the PRO hint with its link - not as an unknown value and
     * without choices; the list view itself stays possible.
     */
    public function test_views_beyond_the_list_view_need_pro(): void {
        wb_payment::override_pro_version_for_tests(false);
        $issue = self::issues($this->preflight_change('viewparam', (string)MOD_BOOKING_VIEW_PARAM_CARDS))[0] ?? [];
        $this->assertSame('CONFIGURE_INSTANCE_REQUIRES_PRO', (string)($issue['code'] ?? ''), json_encode($issue));
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        $this->assertArrayNotHasKey('candidates', $issue);
        $this->assertStringContainsString('https://showroom.wunderbyte.at/', (string)($issue['user_question'] ?? ''));
        $this->assertStringNotContainsString('viewparam', (string)($issue['user_question'] ?? ''));

        $json = $this->apply([['field' => 'viewparam', 'value' => (string)MOD_BOOKING_VIEW_PARAM_LIST]]);
        $this->assertSame(MOD_BOOKING_VIEW_PARAM_LIST, (int)$json['viewparam']);
    }

    /**
     * Without PRO the view switcher ends as the PRO hint, whatever its value.
     */
    public function test_the_view_switcher_needs_pro(): void {
        wb_payment::override_pro_version_for_tests(false);
        foreach ([['switchtemplates', '1'], ['switchtemplatesselection', '0,1']] as [$field, $value]) {
            $issue = self::issues($this->preflight_change($field, $value))[0] ?? [];
            $this->assertSame('CONFIGURE_INSTANCE_REQUIRES_PRO', (string)($issue['code'] ?? ''), $field);
        }
    }

    /**
     * With PRO, the switcher and its views are set; turning the switcher off drops the selection as the form does.
     */
    public function test_the_view_switcher_and_its_views(): void {
        wb_payment::override_pro_version_for_tests(true);
        $json = $this->apply([
            ['field' => 'switchtemplates', 'value' => '1'],
            ['field' => 'switchtemplatesselection', 'value' => '0,1,4'],
        ]);
        $this->assertSame(1, (int)$json['switchtemplates']);
        $this->assertSame([0, 1, 4], array_map('intval', (array)$json['switchtemplatesselection']));

        $json = $this->apply([['field' => 'switchtemplates', 'value' => '0']]);
        $this->assertSame(0, (int)$json['switchtemplates']);
        $this->assertArrayNotHasKey('switchtemplatesselection', $json);
        $this->assertSame(1, (int)$json['disablecancel']);
    }

    /**
     * The confirm card names the chosen view by its label.
     */
    public function test_the_card_names_the_view(): void {
        wb_payment::override_pro_version_for_tests(true);
        $skill = new configure_booking_instance_skill();
        $dto = $this->preflight_change('viewparam', (string)MOD_BOOKING_VIEW_PARAM_CARDS);
        $descriptor = $skill->describe_proposed_action((array)$dto->preparedinput);
        $values = array_column((array)($descriptor['rows'] ?? []), 'value');
        $this->assertContains(get_string('viewparam:cards', 'mod_booking'), $values, json_encode($descriptor));
    }

    /**
     * The read-only sibling shows the current view from the JSON.
     */
    public function test_list_instance_settings_shows_the_current_view(): void {
        global $USER;
        wb_payment::override_pro_version_for_tests(true);
        $this->apply([['field' => 'viewparam', 'value' => (string)MOD_BOOKING_VIEW_PARAM_CARDS]]);
        $result = (new list_instance_settings_skill())->execute([], $this->contextid, (int)$USER->id);
        $this->assertStringContainsString(
            'viewparam (Default view, choice): 1 (' . get_string('viewparam:cards', 'mod_booking') . ')',
            (string)($result['observation_full'] ?? $result['detail'] ?? '')
        );
    }

    /**
     * A value of the wrong type is a localized clarification that names no field identifier.
     */
    public function test_a_type_error_is_a_localized_question_without_field_name(): void {
        $issue = self::issues($this->preflight_change('maxperuser', 'many'))[0] ?? [];
        $this->assertSame('CONFIGURE_INSTANCE_FIELD_TYPE_ERROR', (string)($issue['code'] ?? ''));
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        $this->assertSame(get_string('agent_booking_configure_value_invalid', 'mod_booking'), (string)$issue['message']);
    }
}
