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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * A pure governance denial produces a deterministic [UNAVAILABLE] observation that binds the reply
 * to the ONE actual deny reason. Issue #2223: the old framing offered a menu of possible causes
 * ("either not enabled or lacks permission"), which produced three different user explanations for
 * identical runs — including a wrong "contact an administrator" for an admin user.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder::build_observations
 */
final class skill_denied_observation_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Find the observation containing a marker.
     *
     * @param string[] $observations
     * @param string $marker
     * @return string
     */
    private function find_observation(array $observations, string $marker): string {
        foreach ($observations as $observation) {
            if (str_contains($observation, $marker)) {
                return $observation;
            }
        }
        return '';
    }

    /**
     * A pure SKILL_DENIED turn yields the neutral [UNAVAILABLE] framing carrying only the
     * deterministic deny message, with no cause menu the model could pick alternatives from.
     */
    public function test_pure_skill_denied_binds_reply_to_the_single_reason(): void {
        $reason = 'This capability is not available on this system because it is not enabled here.';
        $observations = (new synchronizer_input_builder())->build_observations([
            'response_type' => 'error',
            'issue_codes' => ['SKILL_DENIED'],
            'errors' => ['Command #1: ' . $reason],
        ]);

        $observation = $this->find_observation($observations, '[UNAVAILABLE]');
        $this->assertNotSame('', $observation, 'A pure governance denial must produce the [UNAVAILABLE] framing.');
        $this->assertStringContainsString('reason: ' . $reason, $observation);
        $this->assertStringContainsString('Convey ONLY the reason above', $observation);
        // No cause menu: the framing must not enumerate alternative explanations the model
        // could choose from instead of the actual reason.
        $this->assertStringNotContainsString('lacks permission', $observation);
        $this->assertStringNotContainsString('not enabled on this system or', $observation);
        $this->assertSame('', $this->find_observation($observations, '[ERROR]'));
    }

    /**
     * A denial mixed with other failure codes keeps the honest [ERROR] framing (a real error is
     * present), and the deny message still travels as one of the causes.
     */
    public function test_mixed_issue_codes_keep_error_framing_with_the_deny_cause(): void {
        $reason = 'This capability is not available on this system because it is not enabled here.';
        $observations = (new synchronizer_input_builder())->build_observations([
            'response_type' => 'error',
            'issue_codes' => ['SKILL_DENIED', 'SCAFFOLD_NO_COURSE'],
            'errors' => ['Command #1: ' . $reason, 'Command #2: Course content is generated inside a course.'],
        ]);

        $observation = $this->find_observation($observations, '[ERROR]');
        $this->assertNotSame('', $observation);
        $this->assertStringContainsString($reason, $observation);
        $this->assertSame('', $this->find_observation($observations, '[UNAVAILABLE]'));
    }

    /**
     * A PRO-gated denial keeps its dedicated upgrade framing — never the generic unavailable text.
     */
    public function test_requires_pro_keeps_upgrade_framing(): void {
        $observations = (new synchronizer_input_builder())->build_observations([
            'response_type' => 'error',
            'issue_codes' => ['REQUIRES_PRO'],
            'errors' => ['Command #1: This task is only available with a Wunderbyte Pro license.'],
        ]);

        $observation = $this->find_observation($observations, '[UPGRADE_REQUIRED]');
        $this->assertNotSame('', $observation);
        $this->assertSame('', $this->find_observation($observations, '[UNAVAILABLE]'));
        $this->assertSame('', $this->find_observation($observations, '[ERROR]'));
    }
}
