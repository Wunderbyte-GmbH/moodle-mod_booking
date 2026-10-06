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

namespace bookingextension_agent;

use advanced_testcase;

/**
 * Invariant guard: the generic add_activity feature must not leak activity/module knowledge into the engine.
 *
 * The engine only ever speaks generic skill contracts; everything that means "create an activity" lives in
 * the skill and its services/activities/* helpers. This test pins that boundary (blueprint §0 + §10).
 *
 * @package    bookingextension_agent
 * @coversNothing
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_cleanliness_activity_test extends advanced_testcase {
    /**
     * Engine files must contain no activity/module-specific knowledge.
     */
    public function test_engine_files_are_activity_agnostic(): void {
        global $CFG;
        $base = $CFG->dirroot . '/mod/booking/bookingextension/agent/classes/local/wizard';

        $enginefiles = [
            $base . '/executor.php',
            $base . '/agent_runtime.php',
            $base . '/orchestrator.php',
            $base . '/services/preflight_pipeline.php',
            $base . '/services/preview_passthrough.php',
            $base . '/services/decision/agent_decision_service.php',
        ];

        // Tokens that would betray activity-specific knowledge having leaked into the engine.
        $forbidden = [
            'add_moduleinfo',
            'module_form_contract',
            'module_catalog_service',
            'activity_creation_service',
            'prepare_new_moduleinfo_data',
            'course.add_activity',
        ];

        foreach ($enginefiles as $file) {
            $this->assertFileExists($file);
            $contents = (string)file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "Engine file " . basename($file) . " must not reference activity-specific token '{$needle}'."
                );
            }
        }
    }
}
