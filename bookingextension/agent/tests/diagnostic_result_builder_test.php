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
use moodle_url;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;

/**
 * S6: the diagnose_* skills' shared row/glyph/error builders.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_result_builder_test extends advanced_testcase {
    /**
     * A row carries status/check/finding and renders the url (or null).
     */
    public function test_row(): void {
        $row = diagnostic_result_builder::row('ok', 'Enrolment', 'User is enrolled');
        $this->assertSame(
            ['status' => 'ok', 'check' => 'Enrolment', 'finding' => 'User is enrolled', 'url' => null],
            $row
        );

        $withurl = diagnostic_result_builder::row('fail', 'Access', 'No access', new moodle_url('/course/view.php', ['id' => 5]));
        $this->assertSame('fail', $withurl['status']);
        $this->assertStringContainsString('/course/view.php', (string)$withurl['url']);
        $this->assertStringContainsString('id=5', (string)$withurl['url']);
    }

    /**
     * Glyphs map known statuses; anything else is the warn glyph.
     */
    public function test_glyph(): void {
        $this->assertSame('[OK]', diagnostic_result_builder::glyph('ok'));
        $this->assertSame('[X]', diagnostic_result_builder::glyph('fail'));
        $this->assertSame('[!]', diagnostic_result_builder::glyph('warn'));
        $this->assertSame('[!]', diagnostic_result_builder::glyph('unknown-status'));
    }

    /**
     * The error result has the uniform shape and the skill-specific observation prefix.
     */
    public function test_error_result(): void {
        $result = diagnostic_result_builder::error_result('boom', 'internal_status', 'Access diagnosis could not run: ');

        $this->assertSame('error', $result['status']);
        $this->assertSame('boom', $result['detail']);
        $this->assertSame('boom', $result['usermessage']);
        $this->assertNull($result['resultid']);
        $this->assertSame('internal_status', $result['error_class']);
        $this->assertSame('Access diagnosis could not run: boom', $result['observation_full']);
    }
}
