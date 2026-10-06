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
use context_course;
use context_module;
use ReflectionMethod;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;

/**
 * C3 (F7/B9, thread 590): the operating-context note on confirmation cards must name the target at
 * the LEVEL the persisted operating context actually has. A module-level mutation must name the
 * target ACTIVITY (today the note collapses every context to its surrounding course: "course 'ai'
 * (ID 11)" instead of the activity 'no content'); a site/system-level context must carry site/system
 * wording (today it renders "course '<sitename>' (ID 1)"). The existing
 * confirm_target_context_note_test covers only the course level.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirm_context_note_level_test extends advanced_testcase {
    /**
     * Build a decision service with real (test-instantiable) dependencies.
     *
     * @return agent_decision_service
     */
    private function make_service(): agent_decision_service {
        return new agent_decision_service(
            skill_registry::make_default(),
            new conversation_store(),
            new authorization_service()
        );
    }

    /**
     * Invoke the private note builder.
     *
     * @param agent_decision_service $service
     * @param array $commands
     * @param int $ambientcontextid
     * @return string
     */
    private function note(agent_decision_service $service, array $commands, int $ambientcontextid): string {
        $method = new ReflectionMethod($service, 'build_operating_context_note');
        $method->setAccessible(true);
        return (string)$method->invoke($service, $commands, $ambientcontextid, '');
    }

    /**
     * A command with a MODULE-level operating context (persisted since agent commit 9689807) must
     * name the target activity itself, not only the surrounding course — thread 590 showed a
     * mutation of the option in activity 'no content' confirmed as "course 'ai' (ID 11)".
     */
    public function test_note_names_module_level_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Level Host Course']);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Target Activity Q17']
        );
        $modulectxid = (int)context_module::instance($page->cmid)->id;

        $note = $this->note(
            $this->make_service(),
            [['skill' => 'mod_booking.update_option', 'operating_contextid' => $modulectxid]],
            999999
        );

        // Today's actual output (calibrated 2026-07-12):
        // 'Note: this will be carried out in: course "Level Host Course" (ID <n>).'.
        $this->assertNotSame('', $note);
        $this->assertStringContainsString(
            'Target Activity Q17',
            $note,
            'C3 (thread 590): a module-level operating context must name the target ACTIVITY in the '
            . 'confirmation note; today describe_target_context() collapses it to the surrounding course '
            . '("course \'Level Host Course\' (ID n)"), so the user cannot see WHICH activity is mutated.'
        );
    }

    /**
     * A site/system-level operating context (the ambient context of a site-level chat is the front
     * page course, id 1) must carry site/system wording — not the course label
     * "course '<sitename>' (ID 1)" thread 590 showed.
     */
    public function test_note_uses_system_label_for_system_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sitectxid = (int)context_course::instance(SITEID)->id;

        $note = $this->note(
            $this->make_service(),
            [['skill' => 'course.add_activity', 'operating_contextid' => $sitectxid]],
            999999
        );

        // Today's actual output (calibrated 2026-07-12):
        // 'Note: this will be carried out in: course "PHPUnit test site" (ID 1).'.
        $this->assertNotSame('', $note, 'A system-level target must still be named in the note.');

        // The exact wrong label the note carries today: the site rendered as a plain course with id 1.
        $site = get_site();
        $wrongsitelabel = get_string('agent_confirm_target_course', 'bookingextension_agent', (object)[
            'name' => format_string($site->fullname),
            'id' => SITEID,
        ]);
        $this->assertStringNotContainsString(
            $wrongsitelabel,
            $note,
            'C3 (thread 590): a site/system-level operating context must use site/system wording; today the '
            . 'note renders the front page as "' . $wrongsitelabel . '", which reads like an ordinary course.'
        );
    }

    /**
     * The empirically most common case (thread 591): course.create_course, a context-free system
     * skill, ran in the admin's CONTEXT_USER (operating_contextid=5) and the note read
     * "User: Admin User" — a user profile is not where the course lands. Such place-less contexts
     * must carry site/system wording, not the user's name.
     */
    public function test_note_uses_site_label_for_user_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Administrina']);
        $userctxid = (int)\context_user::instance($user->id)->id;

        $note = $this->note(
            $this->make_service(),
            [['skill' => 'course.create_course', 'operating_contextid' => $userctxid]],
            999999
        );

        $this->assertNotSame('', $note, 'A context-free system skill must still name a target level.');
        $this->assertStringNotContainsString(
            'Administrina',
            $note,
            'C3 (thread 591): a user-context ambient must not surface as "User: <name>" — that is doubly '
            . 'wrong (user profile is not where the write lands). It must read as the site/system.'
        );
        $this->assertStringContainsString(
            get_string('agent_confirm_target_site', 'bookingextension_agent'),
            $note,
            'A place-less (user/block) operating context must render as the site.'
        );
    }
}
