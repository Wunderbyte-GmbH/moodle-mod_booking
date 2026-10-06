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

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for centralized runtime feature flags.
 *
 * @covers \bookingextension_agent\local\wizard\config\runtime_feature_flags
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runtime_feature_flags_test extends TestCase {
    /**
     * Defaults must be safe and disabled.
     */
    public function test_default_values_are_safe(): void {
        $this->assertFalse(runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_DISCOVERY_ENABLED));
        $this->assertFalse(runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED));
        $this->assertFalse(runtime_feature_flags::is_enabled(runtime_feature_flags::STAGED_DISCOVERY_ENABLED));
        $this->assertFalse(runtime_feature_flags::is_enabled(runtime_feature_flags::SYNCHRONIZER_STRICT_CONTRACT));
    }

    /**
     * Snapshot must expose all known flags as booleans.
     */
    public function test_known_flags_can_be_resolved(): void {
        $snapshot = runtime_feature_flags::snapshot();

        $this->assertArrayHasKey(runtime_feature_flags::FAMILY_DISCOVERY_ENABLED, $snapshot);
        $this->assertArrayHasKey(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED, $snapshot);
        $this->assertArrayHasKey(runtime_feature_flags::STAGED_DISCOVERY_ENABLED, $snapshot);
        $this->assertArrayHasKey(runtime_feature_flags::SYNCHRONIZER_STRICT_CONTRACT, $snapshot);
        $this->assertIsBool($snapshot[runtime_feature_flags::FAMILY_DISCOVERY_ENABLED]);
        $this->assertIsBool($snapshot[runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED]);
        $this->assertIsBool($snapshot[runtime_feature_flags::STAGED_DISCOVERY_ENABLED]);
        $this->assertIsBool($snapshot[runtime_feature_flags::SYNCHRONIZER_STRICT_CONTRACT]);
    }

    /**
     * Unknown flags must fail closed.
     */
    public function test_unknown_flag_returns_false(): void {
        $this->assertFalse(runtime_feature_flags::is_enabled('does_not_exist'));
    }

    /**
     * Core consumers must read from the same central source.
     */
    public function test_consumer_classes_read_same_flag_source(): void {
        $expected = runtime_feature_flags::snapshot();

        $this->assertSame($expected, agent_runtime::get_runtime_feature_flags_snapshot());
        $this->assertSame($expected, orchestrator::get_runtime_feature_flags_snapshot());
        $this->assertSame($expected, conversation_store::get_runtime_feature_flags_snapshot());
        $this->assertSame($expected, orchestrator_routing_service::get_runtime_feature_flags_snapshot());
    }
}
