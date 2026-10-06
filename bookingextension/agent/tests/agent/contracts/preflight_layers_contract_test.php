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

use bookingextension_agent\local\wizard\services\preflight_domain_check_runner;
use bookingextension_agent\local\wizard\services\preflight_execution_gate;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for preflight L2/L3 layers.
 *
 * @covers \bookingextension_agent\local\wizard\services\preflight_domain_check_runner
 * @covers \bookingextension_agent\local\wizard\services\preflight_execution_gate
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preflight_layers_contract_test extends TestCase {
    /**
     * L2 hard-block contract for permission errors.
     */
    public function test_domain_runner_hard_blocks_permission_error(): void {
        $runner = new preflight_domain_check_runner();
        $result = $runner->run(['PERMISSION_ERROR'], microtime(true));

        $this->assertSame('hard_block', $result->status);
        $this->assertSame([preflight_result_v2::BLOCKING_LAYER_DOMAIN, 'domain'], [
            $result->blockinglayer,
            'domain',
        ]);
        $this->assertSame(['PERMISSION_ERROR'], $result->issuecodes);
    }

    /**
     * L2 soft-block contract for confirmation-like conflicts.
     */
    public function test_domain_runner_soft_blocks_duplicate_confirm_issue(): void {
        $runner = new preflight_domain_check_runner();
        $result = $runner->run(['DUPLICATE_TITLE_CONFIRM_REQUIRED'], microtime(true));

        $this->assertSame('soft_block', $result->status);
        $this->assertSame(preflight_result_v2::BLOCKING_LAYER_DOMAIN, $result->blockinglayer);
        $this->assertSame(['DUPLICATE_TITLE_CONFIRM_REQUIRED'], $result->issuecodes);
    }

    /**
     * L3 retry-hint contract for transient timeout class.
     */
    public function test_execution_gate_retry_hint_for_provider_timeout(): void {
        $gate = new preflight_execution_gate();
        $result = $gate->evaluate('provider_timeout', 0, ['PROVIDER_TIMEOUT']);

        $this->assertSame('retry_hint', $result->status);
        $this->assertSame(preflight_result_v2::BLOCKING_LAYER_EXECUTION_GATE, $result->blockinglayer);
        $this->assertGreaterThanOrEqual(500, $result->retryafterms);
        $this->assertLessThanOrEqual(700, $result->retryafterms);
        $this->assertSame(['PROVIDER_TIMEOUT', 'RETRY_DECISION_LAYER_EXECUTION', 'RETRY_CATEGORY_TECHNICAL'], $result->issuecodes);
    }

    /**
     * L3 hard-block contract once retry cap is exceeded.
     */
    public function test_execution_gate_hard_blocks_after_max_retries(): void {
        $gate = new preflight_execution_gate();
        $result = $gate->evaluate('transient_io', 4, ['TRANSIENT_IO']);

        $this->assertSame('hard_block', $result->status);
        $this->assertSame(preflight_result_v2::BLOCKING_LAYER_EXECUTION_GATE, $result->blockinglayer);
        $this->assertContains('MAX_RETRIES_EXCEEDED', $result->issuecodes);
        $this->assertContains('TRANSIENT_IO', $result->issuecodes);
    }
}
