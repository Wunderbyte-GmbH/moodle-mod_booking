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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\preflight_contract_validator;
use bookingextension_agent\local\wizard\services\preflight_schema_validator;
use bookingextension_agent\local\wizard\services\preflight_version_validator;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for consolidated L1 preflight contract validator.
 *
 * @covers \bookingextension_agent\local\wizard\services\preflight_contract_validator
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preflight_contract_validator_contract_test extends TestCase {
    /**
     * Consolidated validator surfaces schema errors unchanged.
     */
    public function test_validator_propagates_schema_error_contract(): void {
        $registry = $this->createMock(skill_registry::class);
        $schemavalidator = $this->createMock(preflight_schema_validator::class);
        $schemavalidator->expects($this->once())
            ->method('validate')
            ->with(['input' => ['x' => 1]])
            ->willReturn([
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => ['SCHEMA_ERROR', 'SCHEMA_ERROR'],
                'errors' => ['Missing required field "skill".', 'Missing required field "skill".'],
            ]);

        $validator = new preflight_contract_validator($registry, $schemavalidator);
        $result = $validator->validate(['input' => ['x' => 1]]);

        $this->assertFalse((bool)$result['valid']);
        $this->assertSame('schema_error', (string)$result['error_class']);
        $this->assertSame(['SCHEMA_ERROR'], array_values((array)$result['issue_codes']));
        $this->assertSame(['Missing required field "skill".'], array_values((array)$result['errors']));
    }

    /**
     * Consolidated validator keeps version deprecation issue codes when validation passes.
     */
    public function test_validator_preserves_deprecation_issue_codes(): void {
        $registry = $this->createMock(skill_registry::class);
        $schemavalidator = $this->createMock(preflight_schema_validator::class);
        $versionvalidator = $this->createMock(preflight_version_validator::class);
        $schemavalidator->expects($this->once())
            ->method('validate')
            ->with([
                'skill' => 'booking.create_option',
                'version' => 1,
                'input' => ['title' => 'Demo'],
            ])
            ->willReturn([
                'valid' => true,
                'error_class' => '',
                'issue_codes' => [],
                'errors' => [],
            ]);

        $versionvalidator->expects($this->once())
            ->method('validate')
            ->with([
                'skill' => 'booking.create_option',
                'version' => 1,
                'input' => ['title' => 'Demo'],
            ])
            ->willReturn([
                'valid' => true,
                'error_class' => '',
                'issue_codes' => ['SKILL_VERSION_DEPRECATED'],
                'errors' => [],
            ]);

        $validator = new preflight_contract_validator($registry, $schemavalidator, $versionvalidator);
        $result = $validator->validate([
            'skill' => 'booking.create_option',
            'version' => 1,
            'input' => ['title' => 'Demo'],
        ]);

        $this->assertTrue((bool)$result['valid']);
        $this->assertSame('', (string)$result['error_class']);
        $this->assertSame(['SKILL_VERSION_DEPRECATED'], array_values((array)$result['issue_codes']));
        $this->assertSame([], array_values((array)$result['errors']));
    }

    /**
     * Consolidated validator hard-blocks unsupported versions from version layer.
     */
    public function test_validator_blocks_unsupported_version(): void {
        $registry = $this->createMock(skill_registry::class);
        $schemavalidator = $this->createMock(preflight_schema_validator::class);
        $versionvalidator = $this->createMock(preflight_version_validator::class);

        $schemavalidator->expects($this->once())
            ->method('validate')
            ->willReturn([
                'valid' => true,
                'error_class' => '',
                'issue_codes' => [],
                'errors' => [],
            ]);

        $versionvalidator->expects($this->once())
            ->method('validate')
            ->willReturn([
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => ['SKILL_VERSION_UNSUPPORTED'],
                'errors' => ['Unsupported skill version "99" for skill "booking.create_option". Supported version is "1".'],
            ]);

        $validator = new preflight_contract_validator($registry, $schemavalidator, $versionvalidator);
        $result = $validator->validate([
            'skill' => 'booking.create_option',
            'version' => 99,
            'input' => ['title' => 'Demo'],
        ]);

        $this->assertFalse((bool)$result['valid']);
        $this->assertSame('schema_error', (string)$result['error_class']);
        $this->assertSame(['SKILL_VERSION_UNSUPPORTED'], array_values((array)$result['issue_codes']));
        $this->assertNotEmpty((array)$result['errors']);
    }
}
