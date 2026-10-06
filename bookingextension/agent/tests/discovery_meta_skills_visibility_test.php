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
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\introspection\skill_introspection_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use context_course;

/**
 * The discovery meta-skills (wizard.list_skills / wizard.search_skills) are only meaningful while the
 * catalog is a semantic subset. They are excluded from the full slim catalog — both the static
 * (slim_all) planner catalog and the listing wizard.list_skills produces (thread 565).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::exclude_discovery_meta_skills
 * @covers \bookingextension_agent\local\wizard\services\introspection\skill_introspection_service::render_full_skill_catalog
 */
final class discovery_meta_skills_visibility_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * exclude_discovery_meta_skills drops list_skills + search_skills, keeps the rest.
     */
    public function test_exclude_discovery_meta_skills_drops_only_meta(): void {
        $catalogsvc = new planner_catalog_service(new assistant_state_guidance_service());

        $catalog = [
            ['skill' => 'wizard.list_skills', 'readonly' => true],
            ['skill' => 'mod_booking.create_option', 'readonly' => false],
            ['skill' => 'wizard.search_skills', 'readonly' => true],
            ['skill' => 'course.diagnose_user_in_course', 'readonly' => true],
            'not-an-array',
        ];

        $filtered = $catalogsvc->exclude_discovery_meta_skills($catalog);
        $names = array_map(static fn(array $e): string => (string)$e['skill'], $filtered);

        $this->assertNotContains('wizard.list_skills', $names);
        $this->assertNotContains('wizard.search_skills', $names);
        $this->assertContains('mod_booking.create_option', $names);
        $this->assertContains('course.diagnose_user_in_course', $names);
        $this->assertCount(2, $filtered, 'Junk and meta-skills are dropped.');
    }

    /**
     * render_full_skill_catalog returns the compact slim catalog text and never re-advertises the
     * discovery meta-skills themselves.
     */
    public function test_render_full_skill_catalog_excludes_meta_skills(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int)context_course::instance($course->id)->id;

        $text = (new skill_introspection_service())->render_full_skill_catalog((int)$USER->id, $contextid, 'all');

        $this->assertIsString($text);
        // Match the CARD header, not the bare name. Sibling skills name the meta-skills inside their own
        // description on purpose ("Not a capability search (wizard.search_skills)") to steer routing; those
        // sentences sit past character 160 and only became visible when the second, hard truncation of the
        // rendered card was removed (2026-09-19). What must not happen is the meta-skill being OFFERED as an
        // entry of its own — that is the header.
        $this->assertStringNotContainsString('## wizard.list_skills', $text);
        $this->assertStringNotContainsString('## wizard.search_skills', $text);
        // It is the compact "## <skill> [..]" rendering, and it lists real skills.
        $this->assertStringContainsString('## ', $text);
    }
}
