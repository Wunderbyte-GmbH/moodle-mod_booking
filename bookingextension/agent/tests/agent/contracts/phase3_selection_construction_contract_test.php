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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\construction\parameter_constructor;
use bookingextension_agent\local\wizard\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wizard\services\selection\lazy_skill_loader;
use bookingextension_agent\local\wizard\services\selection\skill_selector;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for phase-3 selection and parameter construction.
 *
 * @covers \bookingextension_agent\local\wizard\services\selection\lazy_skill_loader
 * @covers \bookingextension_agent\local\wizard\services\selection\skill_selector
 * @covers \bookingextension_agent\local\wizard\services\construction\parameter_constructor
 * @covers \bookingextension_agent\local\wizard\services\construction\parameter_contract_validator
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase3_selection_construction_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Lazy loader must reject skills outside the phase allow-list.
     */
    public function test_lazy_skill_loader_respects_allowed_skills(): void {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill'])
            ->getMock();

        $registry->expects($this->never())->method('get_skill');

        $loader = new lazy_skill_loader($registry);
        $loaded = $loader->load_skill('mod_booking.create_booking', ['mod_booking.update_booking']);

        $this->assertNull($loaded);
    }

    /**
     * Unique suffix selection should resolve to the canonical skill name.
     */
    public function test_skill_selector_resolves_unique_suffix(): void {
        $skill = $this->createMock(skill_interface::class);

        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(static function (string $skillname) use ($skill): ?skill_interface {
            if ($skillname === 'mod_booking.create_booking') {
                return $skill;
            }
            return null;
        });

        $selector = new skill_selector(new lazy_skill_loader($registry));
        $result = $selector->select(
            ['skill' => 'create_booking', 'version' => 2, 'input' => ['foo' => 'bar']],
            ['mod_booking.create_booking'],
            'Command #1'
        );

        $this->assertTrue($result->valid);
        $this->assertSame('mod_booking.create_booking', $result->skillname);
        $this->assertSame(2, $result->version);
        $this->assertSame($skill, $result->skill);
    }

    /**
     * Parameter construction should apply the registry normalizer, hydrate schema fields flagged
     * `from_user_message`, and prune empties — without carrying any domain field heuristics
     * (audit 05-F01: the engine names no domain field).
     */
    public function test_parameter_constructor_normalizes_and_hydrates_question(): void {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('get_schema')->willReturn([
            'properties' => [
                // Schema-driven hydration: only a field flagged from_user_message is filled.
                'question' => ['type' => 'string', 'from_user_message' => true],
            ],
        ]);

        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['normalize_skill_input', 'get_skill'])
            ->getMock();

        $registry->method('normalize_skill_input')->willReturnCallback(static function (string $skillname, array $input): array {
            $input['normalized_by_registry'] = $skillname;
            return $input;
        });
        $registry->method('get_skill')->willReturn($skill);

        $constructor = new parameter_constructor($registry);
        $result = $constructor->build('mod_booking.create_booking', [
            'search_queries' => 'alpha, beta',
            'question' => '',
            'empty_list' => [],
        ], 'Need help with the booking flow');

        $this->assertTrue($result->valid);
        // Schema-flagged field is hydrated from the last user message.
        $this->assertSame('Need help with the booking flow', $result->input['question']);
        // The engine no longer splits domain fields such as search_queries — that responsibility moved
        // to the owning skill, so the engine passes the raw value through untouched (audit 05-F01).
        $this->assertSame('alpha, beta', $result->input['search_queries']);
        $this->assertSame('mod_booking.create_booking', $result->input['normalized_by_registry']);
        $this->assertArrayNotHasKey('empty_list', $result->input);
    }

    /**
     * Structural validation should surface skill-level errors without mutation.
     */
    public function test_parameter_contract_validator_propagates_structural_errors(): void {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('check_structure')->willReturn([
            'valid' => false,
            'errors' => ['Missing required field.'],
            'issue_codes' => ['REQUIRED_FIELD_MISSING'],
        ]);

        $validator = new parameter_contract_validator();
        $result = $validator->validate($skill, ['foo' => 'bar'], 'Command #1');

        $this->assertFalse($result->valid);
        $this->assertSame(['Command #1: Missing required field.'], $result->errors);
        $this->assertSame(['REQUIRED_FIELD_MISSING'], $result->issuecodes);
    }
}
