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

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\finalization_classifier;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for risk-class aware synchronizer finalization guards.
 *
 * @covers \bookingextension_agent\local\wizard\services\finalization_classifier
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_risk_contract_test extends TestCase {
    /**
     * R3 sufficient outputs must require an irreversibility notice.
     */
    public function test_r3_sync_candidate_requires_irreversibility_notice(): void {
        $classifier = new finalization_classifier();

        $this->assertTrue($classifier->requires_irreversibility_notice([
            'response_type' => 'sufficient',
            'risk_class' => skill_risk_class::R3,
        ]));
        $this->assertFalse($classifier->requires_irreversibility_notice([
            'response_type' => 'sufficient',
        ]));
    }

    /**
     * R2 sufficient outputs must carry an affected scope summary.
     */
    public function test_r2_sync_candidate_requires_scope_summary(): void {
        $classifier = new finalization_classifier();

        $this->assertTrue($classifier->requires_affected_scope_summary([
            'response_type' => 'sufficient',
            'risk_class' => skill_risk_class::R2,
        ]));
        $this->assertFalse($classifier->requires_affected_scope_summary([
            'response_type' => 'sufficient',
        ]));
    }
}
