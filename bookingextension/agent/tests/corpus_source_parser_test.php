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
use bookingextension_agent\local\wizard\services\lookup\corpus_source_parser;

/**
 * Tests for the documentation corpus textarea parser.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\corpus_source_parser
 */
final class corpus_source_parser_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * A bare component resolves to its docs folder with the component name as the corpus id.
     */
    public function test_bare_component(): void {
        global $CFG;
        $parsed = corpus_source_parser::parse('mod_booking');

        $this->assertContains('mod_booking', $parsed['declared']);
        $this->assertArrayHasKey('mod_booking', $parsed['resolvable']);
        $this->assertSame($CFG->dirroot . '/mod/booking/docs', $parsed['resolvable']['mod_booking']);
        $this->assertEmpty($parsed['warnings']);
    }

    /**
     * The seeded default (agent + mod_booking) resolves both corpora out of the box.
     */
    public function test_seeded_default(): void {
        $parsed = corpus_source_parser::parse("bookingextension_agent\nmod_booking");

        $this->assertEqualsCanonicalizing(['bookingextension_agent', 'mod_booking'], $parsed['declared']);
        $this->assertArrayHasKey('bookingextension_agent', $parsed['resolvable']);
        $this->assertArrayHasKey('mod_booking', $parsed['resolvable']);
    }

    /**
     * An explicit corpus id with a dirroot-relative path resolves to that path.
     */
    public function test_explicit_id_with_relative_path(): void {
        global $CFG;
        $parsed = corpus_source_parser::parse('quizdocs = mod/booking/docs');

        $this->assertContains('quizdocs', $parsed['declared']);
        $this->assertSame($CFG->dirroot . '/mod/booking/docs', $parsed['resolvable']['quizdocs']);
    }

    /**
     * Comments and blank lines are ignored.
     */
    public function test_comments_and_blanks_ignored(): void {
        $parsed = corpus_source_parser::parse("# a comment\n\n   \nmod_booking   # trailing comment\n");

        $this->assertSame(['mod_booking'], $parsed['declared']);
        $this->assertEmpty($parsed['warnings']);
    }

    /**
     * The first declaration of a corpus id wins; the duplicate is reported and dropped.
     */
    public function test_collision_first_wins(): void {
        $parsed = corpus_source_parser::parse("mod_booking\nmod_booking = mod/booking/docs");

        $this->assertSame(['mod_booking'], $parsed['declared']);
        $this->assertNotEmpty($parsed['warnings']);
    }

    /**
     * An absolute path outside $CFG->dirroot is rejected (declared in neither set).
     */
    public function test_outside_dirroot_rejected(): void {
        $parsed = corpus_source_parser::parse('intern = /etc');

        $this->assertNotContains('intern', $parsed['declared']);
        $this->assertArrayNotHasKey('intern', $parsed['resolvable']);
        $this->assertNotEmpty($parsed['warnings']);
    }

    /**
     * A traversal that escapes dirroot is rejected even though it is written relative.
     */
    public function test_traversal_escape_rejected(): void {
        $parsed = corpus_source_parser::parse('x = mod/booking/../../../../etc');

        $this->assertNotContains('x', $parsed['declared']);
        $this->assertNotEmpty($parsed['warnings']);
    }

    /**
     * A line inside dirroot whose directory is missing is declared but not resolvable.
     */
    public function test_declared_but_unreadable(): void {
        $parsed = corpus_source_parser::parse('ghost = mod/booking/this_does_not_exist');

        $this->assertContains('ghost', $parsed['declared']);
        $this->assertArrayNotHasKey('ghost', $parsed['resolvable']);
        $this->assertEmpty($parsed['warnings'], 'A missing-but-confined dir is a notice, not a hard warning.');
        $this->assertNotEmpty($parsed['notices']);
    }

    /**
     * Corpus ids are normalized to [a-z0-9_].
     */
    public function test_corpus_id_normalized(): void {
        $parsed = corpus_source_parser::parse('My Docs! = mod/booking/docs');

        $this->assertContains('my_docs', $parsed['declared']);
        $this->assertArrayHasKey('my_docs', $parsed['resolvable']);
    }
}
