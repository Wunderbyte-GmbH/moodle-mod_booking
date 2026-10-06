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
use bookingextension_agent\local\wizard\course\skills\add_activity_skill;
use context_course;

/**
 * C6 (E2E audit): truthful add_activity error messages, no unresolved placeholders.
 *
 * When the acting user holds moodle/course:manageactivities but NOT mod/page:addinstance,
 * course/modlib.php can_add_moduleinfo() throws moodle_exception('moduledisable') WITHOUT a
 * parameter. Two defects surface in the skill's error result:
 *  (a) the literal, unresolved '{$a}' placeholder reaches the user, and
 *  (b) the stated cause is wrong — the module is NOT disabled, a capability is missing
 *      (core's course_allowed_module() is purely a capability check).
 * The message must name the missing capability instead.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_activity_skill
 */
final class add_activity_error_message_test extends advanced_testcase {
    /**
     * Missing mod/page:addinstance (with manageactivities present): the surfaced error must not
     * contain a literal '{$a}', must not claim the module is disabled, and must name the missing
     * capability. Red today: the raw core 'moduledisable' message is passed through verbatim.
     */
    public function test_missing_addinstance_cap_message_is_truthful(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $coursecontext = context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Tailor the role: keep manageactivities, prohibit only the page addinstance capability.
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/page:addinstance', CAP_PROHIBIT, $roleid, (int)$coursecontext->id, true);

        // The creation path checks the CURRENT user (course_allowed_module reads $USER).
        $this->setUser($teacher);

        // Preconditions that define this scenario: manage yes, addinstance no.
        $this->assertTrue(has_capability('moodle/course:manageactivities', $coursecontext, $teacher));
        $this->assertFalse(has_capability('mod/page:addinstance', $coursecontext, $teacher));

        // Drive execute with prepared input — the path the E2E audit saw surface the message.
        $result = (new add_activity_skill())->execute(
            [
                'courseid' => (int)$course->id,
                'modname' => 'page',
                'sectionnum' => 0,
                'name' => 'Welcome',
                'intro' => '',
                'settings' => ['content' => '<p>Hello.</p>'],
            ],
            (int)$coursecontext->id,
            (int)$teacher->id
        );

        $this->assertSame('error', $result['status'], (string)($result['detail'] ?? ''));
        $message = (string)($result['usermessage'] ?? '');

        // Defect (a): no unresolved placeholder may ever reach the user.
        $this->assertStringNotContainsString('{$a}', $message, 'unresolved {$a} placeholder in: ' . $message);
        // Defect (b): the cause is a missing capability — the module is NOT disabled.
        $this->assertStringNotContainsStringIgnoringCase(
            'disabled',
            $message,
            'misleading cause (module is not disabled, a capability is missing) in: ' . $message
        );
        // Positive contract: the message names the missing capability.
        $this->assertStringContainsString(
            'mod/page:addinstance',
            $message,
            'the error must name the missing capability, got: ' . $message
        );
    }

    /**
     * Module page disabled site-wide: the user-facing message must name the module readably and
     * must not carry a literal '{$a}'. Today this scenario is caught by preflight's catalog
     * filter (the clarification names "page" and carries no placeholder) — if that holds, this
     * test is green today and only guards the contract against regressions.
     */
    public function test_disabled_module_message_names_module(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $coursecontext = context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        // Disable the page module site-wide through the proper API — a raw modules.visible
        // set_field left the enabled-modules state stale after the DB rollback and turned
        // every later add_activity/module_catalog test in the same run red (C6 pollution).
        \core\plugininfo\mod::enable_plugin('page', 0);
        // The actual leak is core get_module_types_names(): it caches the VISIBLE module names
        // in a function-local static that no reset hook (plugin manager, MUC, DB rollback) ever
        // clears — rebuild it explicitly on both sides of this test.
        get_module_types_names(false, true);

        try {
            $result = (new add_activity_skill())->preflight(
                ['modname' => 'page', 'section' => 'top', 'name' => 'Welcome', 'settings' => ['content' => 'Hi.']],
                (int)$coursecontext->id,
                (int)$teacher->id
            );

            $this->assertNotSame('pass', $result->status, 'a disabled module must not pass preflight');
            $message = (string)($result->issues[0]['message'] ?? '');
            $this->assertNotSame('', $message);

            $this->assertStringNotContainsString('{$a}', $message, 'unresolved {$a} placeholder in: ' . $message);
            $this->assertStringContainsStringIgnoringCase(
                'page',
                $message,
                'the message must name the affected module readably, got: ' . $message
            );
        } finally {
            // The DB change rolls back with resetAfterTest, but neither the plugin caches nor
            // the get_module_types_names() static do — restore both through the same APIs (even
            // on assertion failure) so they agree with the rolled-back DB state again.
            \core\plugininfo\mod::enable_plugin('page', 1);
            get_module_types_names(false, true);
        }
    }
}
