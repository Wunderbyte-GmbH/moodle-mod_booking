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

/**
 * Real-LLM regression test for core.get_current_user.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_send_message;

/**
 * Real provider regression test for the current-user skill.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class get_current_user_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    public function test_get_current_user_observation_contains_full_user_payload(): void {
        $this->setUser($this->teacher);

        [$store, , $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $this->booking_contextid(), (int)$threadid);

        $prompt = 'Bitte schau im Buchungssystem nach, wer ich gerade bin, und zeig mir mein Profil.';
        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute($this->booking_contextid(), $prompt, (int)$threadid);

        $message = $this->payload_text($response);
        $responsetype = (string)($response['response_type'] ?? '');
        $this->assertContains(
            $responsetype,
            ['sufficient', 'execution_result', 'confirmation_request'],
            'Expected an actionable profile response. Payload: ' . $message
        );

        $this->assertTrue(
            $this->has_skill_evidence($response, 'core.get_current_user')
                || str_contains($message, (string)$this->teacher->email)
                || str_contains($message, (string)$this->course->fullname)
                || str_contains(mb_strtolower($message), 'profil wurde gefunden')
                || str_contains(mb_strtolower($message), 'profile was found'),
            'Expected either skill evidence or a direct profile answer. Payload: ' . $message
        );

        $result = $this->exec_command('core.get_current_user', [], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertSame((int)$this->teacher->id, (int)($result['resultid'] ?? 0));

        $user = (array)($result['user'] ?? []);
        $users = (array)($result['users'] ?? []);
        $observation = trim((string)($result['observation_full'] ?? ''));

        $this->assertNotEmpty($user, 'Expected structured current-user payload.');
        $this->assertCount(1, $users, 'Expected one current-user entry in users list.');
        $this->assertSame((int)$this->teacher->id, (int)($user['userid'] ?? 0));
        $this->assertSame((string)$this->teacher->email, (string)($user['email'] ?? ''));
        $this->assertNotEmpty((array)($user['enrolledcourses'] ?? []), 'Expected enrolledcourses in current-user payload.');
        $this->assertNotEmpty((array)($user['roles'] ?? []), 'Expected roles in current-user payload.');

        $coursepayload = json_encode($user['enrolledcourses'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rolepayload = json_encode($user['roles'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString((string)$this->course->fullname, (string)$coursepayload);
        $this->assertStringContainsString('editingteacher', (string)$rolepayload);

        $this->assertStringContainsString('Found 1 user(s):', $observation);
        $this->assertStringContainsString('profile=', $observation);
        $this->assertStringContainsString('enrolledcourses=[', $observation);
        $this->assertStringContainsString('roles=[', $observation);
        $this->assertStringContainsString((string)$this->teacher->email, $observation);
        $this->assertStringContainsString((string)$this->course->fullname, $observation);
        $this->assertStringContainsString('editingteacher', $observation);
    }

    /**
     * Flatten payload content for assertion diagnostics.
     *
     * @param array $payload
     * @return string
     */
    protected function payload_text(array $payload): string {
        $chunks = [
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            json_encode($payload['commands'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($payload['results'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }

    /**
     * Check whether the LLM response references the expected skill.
     *
     * @param array $payload
     * @param string $skillname
     * @return bool
     */
    private function has_skill_evidence(array $payload, string $skillname): bool {
        return $this->extract_command($payload, $skillname) !== null
            || $this->extract_skill_result($payload, $skillname) !== null;
    }
}
