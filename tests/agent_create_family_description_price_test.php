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

use context_module;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;
use stdClass;

/**
 * N3 gap guards beyond create_option: description/price across the whole create family.
 *
 * Complements agent_create_option_intuitive_keys_test (create_option acceptance): the
 * measured top offender was 'price' on create_SELFLEARNING (5 of 7 wrong-key events,
 * W2 baseline 2026-07-12), and description must be accepted family-wide per decision.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 */
final class agent_create_family_description_price_test extends \advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Selflearning end-to-end: 'price' scalar and 'description' pass preflight and land
     * in the created option (prices canonicalized to the default category).
     */
    public function test_selflearning_accepts_price_and_description_end_to_end(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$cmid, $contextid] = $this->create_booking_instance();
        set_config('selflearningcourseactive', 1, 'booking');

        $skill = new create_selflearning_option_skill();
        $result = $skill->preflight(
            [
                'text' => 'Lernkurs Wikinger',
                'description' => 'Selbstlernkurs über das Leben der Wikinger.',
                'price' => 20,
                'duration' => 30 * DAYSECS,
            ],
            $contextid,
            (int)get_admin()->id
        );

        $this->assertContains(
            (string)$result->status,
            ['pass', 'soft_block'],
            'price/description must not structurally block: ' . json_encode($result->issues)
        );
        $prepared = (array)$result->preparedinput;
        $this->assertSame(
            ['default' => 20.0],
            array_map('floatval', (array)($prepared['prices'] ?? [])),
            'The scalar price must be canonicalized to the default price category.'
        );
        $this->assertSame(
            'Selbstlernkurs über das Leben der Wikinger.',
            (string)($prepared['description'] ?? ''),
            'The description must survive preflight preparation.'
        );

        $execresult = $skill->execute($prepared, $contextid, (int)get_admin()->id);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(
            0,
            $optionid,
            'Execute must report the created option id: ' . json_encode($execresult)
        );
        $option = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $this->assertStringContainsString(
            'Selbstlernkurs über das Leben der Wikinger.',
            (string)$option->description,
            'The description must be persisted on the option.'
        );
        $price = $DB->get_record('booking_prices', [
            'itemid' => (int)$option->id,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
        ]);
        $this->assertNotFalse($price, 'The default price row must exist.');
        $this->assertEqualsWithDelta(20.0, (float)$price->price, 0.001);
    }

    /**
     * The scalar shape normalization is shared support-level behaviour (update paths
     * benefit too): a bare numeric prices value validates and canonicalizes.
     */
    public function test_scalar_prices_normalize_at_the_shared_support_layer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_booking_instance();

        $validation = booking_skill_support::validate_prices_input(['prices' => 30]);
        $this->assertSame([], (array)$validation['errors'], 'A scalar prices value must validate.');
        $this->assertSame([], (array)$validation['ambiguities']);

        $this->assertSame(
            ['default' => 30.0],
            booking_skill_support::normalize_prices_input_for_execute('30'),
            'Numeric strings canonicalize to the default category.'
        );
        $this->assertNull(
            booking_skill_support::normalize_prices_input_for_execute('30 Euro'),
            'Non-numeric scalars stay invalid (existing error path).'
        );
    }

    /**
     * The whole create family exposes description — a schema-level drift guard.
     */
    public function test_create_family_schemas_expose_description(): void {
        $this->resetAfterTest();

        foreach (
            [
                new \mod_booking\local\wizard\options\skills\create_option_skill(),
                new create_selflearning_option_skill(),
                new create_slotbooking_option_skill(),
            ] as $skill
        ) {
            $properties = array_keys((array)($skill->get_schema()['properties'] ?? []));
            $this->assertContains(
                'description',
                $properties,
                $skill->get_name() . ' must expose the description property.'
            );
        }
    }

    /**
     * Create a booking instance with a default price category; return [cmid, modulecontextid].
     *
     * @return array{0:int,1:int}
     */
    private function create_booking_instance(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Probe booking',
            'bookingmanager' => 'admin',
            'eventtype' => 'Test',
        ]);

        /** @var \mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $generator->create_pricecategory((object)[
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standard',
            'defaultvalue' => 25,
        ]);

        return [(int)$booking->cmid, (int)context_module::instance((int)$booking->cmid)->id];
    }
}
