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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wizard\options\skills\create_option_skill;

/**
 * create_option: header-image capability and honest schema-mismatch guidance.
 *
 * Two behaviours are pinned here, both pure (no DB): the create task must EXPOSE the header-image
 * capability (so "create an option with this image" is a supported request, not an invented key),
 * and its schema-mismatch guidance must offer an honest escape hatch instead of forcing another
 * task_call or promising an automatic retry that never happens.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class agent_create_option_image_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * The create task exposes headerimage_token in its prompt schema and the shared image
     * guidance pack, and check_structure accepts an attached image rather than rejecting it.
     */
    public function test_create_option_exposes_header_image_capability(): void {
        $skill = new create_option_skill();

        $schema = $skill->get_schema();
        $this->assertArrayHasKey(
            'headerimage_token',
            (array)($schema['properties'] ?? []),
            'create_option must expose headerimage_token so an image can be attached at creation.'
        );

        $packids = array_column($skill->get_contextual_prompt_packs(), 'id');
        $this->assertContains(
            'mod_booking.header_image_attachment',
            $packids,
            'create_option must ship the shared header-image guidance pack.'
        );

        // The point of the whole change: an image on create is no longer a schema mismatch.
        $accepted = $skill->check_structure(['text' => 'Testtitel', 'headerimage_token' => 'tok_x']);
        $this->assertTrue(
            (bool)($accepted['valid'] ?? false),
            'headerimage_token must be a valid create_option parameter, not an unknown key.'
        );
    }

    /**
     * A genuinely unsupported key still fails structurally, but the guidance now offers an honest
     * exit (do not invent a key; tell the user) instead of forcing a task_call or promising a
     * retry that the terminal clarification never performs.
     */
    public function test_schema_mismatch_guidance_offers_honest_escape_hatch(): void {
        $skill = new create_option_skill();

        $result = $skill->check_structure(['text' => 'Testtitel', 'attachmenturl' => 'https://example/x.png']);
        $this->assertFalse((bool)($result['valid'] ?? true), 'An unknown key must still fail structural validation.');

        $text = implode(' ', (array)($result['errors'] ?? [])) . ' ' . (string)($result['observation_full'] ?? '');
        $this->assertStringNotContainsString(
            'Resend exactly one corrected task_call',
            $text,
            'The guidance must not force another task_call — an honest decline is allowed.'
        );
        $this->assertStringContainsStringIgnoringCase(
            'do not invent',
            $text,
            'The guidance must tell the model not to invent a key.'
        );
        // The escape hatch explicitly disowns the false "automatic retry" promise.
        $this->assertStringContainsStringIgnoringCase(
            'do not promise an automatic retry',
            $text,
            'The guidance must disown the false automatic-retry promise.'
        );
    }
}
