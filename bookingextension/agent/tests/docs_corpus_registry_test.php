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
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;

/**
 * Tests the config-driven docs corpus registry (textarea parsing, declared vs resolvable).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry
 */
final class docs_corpus_registry_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * The registry resolves corpora from the aidocsroot textarea setting.
     */
    public function test_resolves_from_setting(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('docscorpora', "bookingextension_agent\nmod_booking", 'bookingextension_agent');

        $registry = new docs_corpus_registry();

        $this->assertSame($CFG->dirroot . '/mod/booking/docs', $registry->resolve_root('mod_booking'));
        $this->assertTrue($registry->is_known('bookingextension_agent'));
        $this->assertSame('bookingextension_agent', $registry->primary());
        $this->assertEqualsCanonicalizing(
            ['bookingextension_agent', 'mod_booking'],
            $registry->declared_corpus_ids()
        );
    }

    /**
     * A declared but unreadable corpus stays in declared_corpus_ids() but is not resolvable,
     * so the prune logic (B1) will never delete it.
     */
    public function test_declared_but_unreadable_is_kept_declared(): void {
        $this->resetAfterTest();
        set_config('docscorpora', "mod_booking\nghost = mod/booking/this_does_not_exist", 'bookingextension_agent');

        $registry = new docs_corpus_registry();

        $this->assertContains('ghost', $registry->declared_corpus_ids());
        $this->assertNull($registry->resolve_root('ghost'));
        $this->assertArrayNotHasKey('ghost', $registry->list());
        $this->assertArrayHasKey('mod_booking', $registry->list());
    }
}
