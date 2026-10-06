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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\mcp\mcp_execution_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Serialization contract of the MCP tool result: observation_full shipping, capping and dropped keys.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 */
final class mcp_result_serialization_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Run a raw executor result through the private build_mcp_result() mapper.
     *
     * @param array $result
     * @return array
     */
    private function build(array $result): array {
        $service = new mcp_execution_service(
            skill_registry::make_default(),
            new conversation_store(),
            new authorization_service()
        );
        $method = new \ReflectionMethod($service, 'build_mcp_result');
        $method->setAccessible(true);
        return (array)$method->invoke($service, $result);
    }

    /**
     * When both usermessage and observation_full exist, the text carries both and
     * structuredContent keeps observation_full (it is no longer a dropped key).
     */
    public function test_observation_full_ships_in_text_and_structured_content(): void {
        $this->resetAfterTest();

        $mcp = $this->build([
            'status' => 'executed',
            'usermessage' => 'One line for the user.',
            'observation_full' => '[RESULT] Rich verbatim observation with all rows.',
            'detail' => 'Short detail.',
            'items' => [1, 2, 3],
        ]);

        $this->assertFalse($mcp['isError']);
        $this->assertSame(
            "One line for the user.\n\n[RESULT] Rich verbatim observation with all rows.",
            $mcp['content'][0]['text']
        );
        $structured = (array)$mcp['structuredContent'];
        $this->assertSame('[RESULT] Rich verbatim observation with all rows.', $structured['observation_full']);
        $this->assertArrayNotHasKey('usermessage', $structured);
        $this->assertSame([1, 2, 3], $structured['items']);
    }

    /**
     * The fallback order of the text channel is unchanged when one of the channels is empty.
     */
    public function test_text_fallback_order_is_preserved(): void {
        $this->resetAfterTest();

        $onlyusermessage = $this->build(['status' => 'executed', 'usermessage' => 'Only the user message.']);
        $this->assertSame('Only the user message.', $onlyusermessage['content'][0]['text']);

        $onlyobservation = $this->build(['status' => 'executed', 'observation_full' => 'Only the observation.']);
        $this->assertSame('Only the observation.', $onlyobservation['content'][0]['text']);

        $onlydetail = $this->build(['status' => 'executed', 'detail' => 'Only the detail.']);
        $this->assertSame('Only the detail.', $onlydetail['content'][0]['text']);
    }

    /**
     * An observation_full beyond the cap is truncated with an explicit marker, in both channels.
     */
    public function test_observation_full_is_capped_with_truncation_marker(): void {
        $this->resetAfterTest();

        $totalchars = mcp_execution_service::MCP_OBSERVATION_FULL_MAX + 4000;
        $mcp = $this->build([
            'status' => 'executed',
            'usermessage' => 'Summary line.',
            'observation_full' => str_repeat('A', $totalchars),
        ]);

        $marker = " …[truncated, {$totalchars} chars total]";
        $structuredobservation = (string)$mcp['structuredContent']['observation_full'];
        $this->assertStringEndsWith($marker, $structuredobservation);
        $this->assertSame(
            mcp_execution_service::MCP_OBSERVATION_FULL_MAX + \core_text::strlen($marker),
            \core_text::strlen($structuredobservation)
        );
        $this->assertStringEndsWith($marker, (string)$mcp['content'][0]['text']);
        $this->assertStringStartsWith("Summary line.\n\nAAA", (string)$mcp['content'][0]['text']);
    }

    /**
     * An observation_full exactly at the cap passes through unmodified.
     */
    public function test_observation_full_at_limit_is_not_truncated(): void {
        $this->resetAfterTest();

        $exact = str_repeat('B', mcp_execution_service::MCP_OBSERVATION_FULL_MAX);
        $mcp = $this->build(['status' => 'executed', 'observation_full' => $exact]);

        $this->assertSame($exact, $mcp['structuredContent']['observation_full']);
        $this->assertStringNotContainsString('[truncated', (string)$mcp['content'][0]['text']);
    }

    /**
     * usermessage, preview and the scaffold ZIP payload never reach structuredContent.
     */
    public function test_dropped_keys_never_reach_structured_content(): void {
        $this->resetAfterTest();

        $mcp = $this->build([
            'status' => 'executed',
            'usermessage' => 'User message.',
            'preview' => ['type' => 'skill_scaffold', 'html' => '<a href="data:application/zip;base64,QUJD">x</a>'],
            'scaffold_zip_base64' => base64_encode('zipbytes'),
            'scaffold_zip_filename' => 'demo.skill.zip',
        ]);

        $structured = (array)$mcp['structuredContent'];
        $this->assertArrayNotHasKey('usermessage', $structured);
        $this->assertArrayNotHasKey('preview', $structured);
        $this->assertArrayNotHasKey('scaffold_zip_base64', $structured);
        $this->assertSame('demo.skill.zip', $structured['scaffold_zip_filename']);
    }
}
