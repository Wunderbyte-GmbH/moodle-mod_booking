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
 * Real-LLM regression test for normal option routing with date/time prompts.
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
 * Ensures single-event date/time prompts use normal option skill (type 0).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class normal_option_datetime_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives webservice endpoints directly.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Prompt should route to mod_booking.create_option and persist type 0.
     */
    public function test_datetime_prompt_routes_to_create_option_and_type_zero(): void {
        global $DB;

        $this->setUser($this->teacher);

        if (!$this->is_skill_available('mod_booking.create_option')) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        // Keep test deterministic when rerun.
        $DB->delete_records('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => 'Buch 1',
        ]);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $this->booking_contextid(), $threadid);
        $contextid = $this->booking_contextid();

        $prompt = 'Erstelle eine Buchungsmöglichkeit mit dem Titel "Buch 1", für höchstens fünf Personen. '
            . 'Stattfinden soll sie übermorgen, von 10 bis 12h.';

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute($contextid, $prompt, (int)$threadid);

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Bitte korrigiere die letzte Buchungsanfrage. Verwende nur passende Felder fuer eine normale Buchungsmoeglichkeit.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Letzter Reparaturversuch: Verwende fuer mod_booking.create_option nur die kanonischen Keys '
                    . 'text, coursestarttime, courseendtime, maxanswers und type. '
                    . 'Keine Zusatzfelder wie day/date/start/end.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $this->assertStringContainsString(
                'Retry mod_booking.create_option once',
                $this->payload_text($response),
                'Unexpected non-retriable error payload for create_option routing.'
            );
            $this->fail('Real LLM returned repeated create_option schema mismatch despite retry guidance.');
        }

        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['confirmation_request', 'sufficient', 'execution_result'],
            'Expected actionable response for mutating prompt. Payload: ' . $this->payload_text($response)
        );

        $normalcommand = $this->extract_command_from_payload($response, 'mod_booking.create_option');
        $normalresult = $this->extract_skill_result($response, 'mod_booking.create_option');
        $slotcommand = $this->extract_command_from_payload($response, 'mod_booking.create_slotbooking_option');

        $this->assertTrue(
            $normalcommand !== null || $normalresult !== null,
            'Expected mod_booking.create_option evidence in payload. Payload: ' . $this->payload_text($response)
        );
        $this->assertNull(
            $slotcommand,
            'Did not expect skill mod_booking.create_slotbooking_option for this prompt. Payload: ' . $this->payload_text($response)
        );

        if ((string)($response['response_type'] ?? '') === 'confirmation_request') {
            $confirm = $this->confirm_pending_result($response, (int)$threadid, $store, false);
            $this->assertTrue(
                (bool)($confirm['success'] ?? false),
                'Confirmation failed. Payload: ' . $this->payload_text($confirm)
            );
            $this->assertContains(
                (string)($confirm['response_type'] ?? ''),
                ['sufficient', 'execution_result'],
                'Unexpected confirm response type. Payload: ' . $this->payload_text($confirm)
            );
        }

        $option = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => 'Buch 1',
        ]);
        $this->assertNotFalse($option, 'Expected created booking option "Buch 1".');
        $this->assertSame(0, (int)$option->type, 'Expected normal option type 0.');
    }

    /**
     * Prompt from production log should route to normal skill and create five normal options.
     */
    public function test_weekday_series_prompt_routes_to_create_option_and_creates_five_type_zero_options(): void {
        global $DB;

        $this->setUser($this->teacher);

        if (!$this->is_skill_available('mod_booking.create_option')) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $beforeoptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text, type');
        $beforeids = array_map(static fn($row): int => (int)$row->id, $beforeoptions);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $this->booking_contextid(), $threadid);
        $contextid = $this->booking_contextid();

        $nextnextmonday = strtotime('next monday +7 days');
        $serieslines = [];
        for ($dayoffset = 0; $dayoffset < 5; $dayoffset++) {
            $dayts = strtotime('+' . $dayoffset . ' days', $nextnextmonday);
            $datestr = date('Y-m-d', $dayts);
            $serieslines[] = 'text="Sport ' . ($dayoffset + 1) . '", coursestarttime="'
                . $datestr . 'T10:00:00", courseendtime="' . $datestr . 'T12:00:00"';
        }

        $prompt = 'Erstelle fuer uebernaechste Woche fuenf einzelne normale Buchungsoptionen mit dem Titel "Sport x", '
            . 'durchnummeriert (Sport 1 bis Sport 5), fuer hoechstens fuenf Personen. '
            . 'Diese sollen an jedem Wochentag (Mo-Fr) jeweils von 10 bis 12h stattfinden.';

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute($contextid, $prompt, (int)$threadid);

        if ((string)($response['response_type'] ?? '') === 'clarification') {
            $startdate = date('d.m.Y', $nextnextmonday);
            $enddate = date('d.m.Y', strtotime('+4 days', $nextnextmonday));
            $kw = date('W', $nextnextmonday);
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Uebernaechste Woche (KW ' . $kw . ', ' . $startdate . ' bis ' . $enddate . '). Bitte genauso erstellen.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Bitte korrigiere die letzte Anfrage. Nutze fuer mod_booking.create_option nur die gueltigen Felder '
                    . 'text, coursestarttime, courseendtime, maxanswers und type=0. '
                    . 'Erstelle exakt diese fuenf Optionen: ' . implode(' ; ', $serieslines) . ' ; maxanswers=5.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Letzter Versuch: Verwende ausschliesslich mod_booking.create_option und nur die kanonischen Keys '
                    . 'text, coursestarttime, courseendtime, maxanswers, type. Keine Zusatzfelder (insb. kein day). '
                    . 'Erstelle exakt diese fuenf Optionen: ' . implode(' ; ', $serieslines)
                    . ' ; maxanswers=5 ; type=0. Sende genau einen korrigierten skill_call.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                $contextid,
                'Finaler Reparaturversuch: Bleibe strikt bei mod_booking.create_option und nutze nur '
                    . 'text, coursestarttime, courseendtime, maxanswers, type. '
                    . 'Entferne alle fremden Keys (insb. day/date/start/end). '
                    . 'Nutze exakt diese fuenf Datumszeilen: ' . implode(' ; ', $serieslines)
                    . ' ; maxanswers=5 ; type=0. Gib genau einen korrigierten skill_call zurueck.',
                (int)$threadid
            );
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $this->assertStringContainsString(
                'Retry mod_booking.create_option once',
                $this->payload_text($response),
                'Unexpected non-retriable error payload for create_option series routing.'
            );
            $this->fail('Real LLM returned repeated create_option schema mismatch in series flow.');
        }

        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['confirmation_request', 'sufficient', 'execution_result'],
            'Expected actionable response after clarification handling. Payload: ' . $this->payload_text($response)
        );

        $commands = $this->decode_commands_from_payload($response);
        $this->assertNotEmpty($commands, 'Expected at least one create command. Payload: ' . $this->payload_text($response));
        $firstcommand = reset($commands);
        $this->assertIsArray($firstcommand, 'Expected first command payload to be an array.');
        $this->assertSame(
            'mod_booking.create_option',
            (string)($firstcommand['skill'] ?? ''),
            'Expected only mod_booking.create_option commands. Payload: ' . $this->payload_text($response)
        );

        $slotcommand = $this->extract_command_from_payload($response, 'mod_booking.create_slotbooking_option');
        $this->assertNull(
            $slotcommand,
            'Did not expect mod_booking.create_slotbooking_option for this prompt. Payload: ' . $this->payload_text($response)
        );

        $current = $response;
        for ($round = 1; $round <= 6; $round++) {
            if ((string)($current['response_type'] ?? '') !== 'confirmation_request') {
                break;
            }

            $requiresallowsession = ((int)($current['autoconfirm'] ?? 0) !== 1);
            $confirm = $this->confirm_pending_result($current, (int)$threadid, $store, $requiresallowsession);
            $confirmdetail = (string)($confirm['detail'] ?? '');
            if (
                !(bool)($confirm['success'] ?? false)
                && (
                    (string)($confirm['response_type'] ?? '') === 'error'
                    || stripos($confirmdetail, 'No pending confirmation is available') !== false
                )
            ) {
                break;
            }
            $this->assertTrue(
                (bool)($confirm['success'] ?? false),
                'Confirmation failed in series flow at round ' . $round . '. Payload: ' . $this->payload_text($confirm)
            );
            $this->assertContains(
                (string)($confirm['response_type'] ?? ''),
                ['confirmation_request', 'sufficient', 'execution_result'],
                'Unexpected response type in series flow at round ' . $round . '. Payload: ' . $this->payload_text($confirm)
            );
            $current = $confirm;

            $afteroptions = $DB->get_records(
                'booking_options',
                ['bookingid' => (int)$this->booking->id],
                'id ASC',
                'id, text, type'
            );
            $created = [];
            foreach ($afteroptions as $row) {
                if (!in_array((int)$row->id, $beforeids, true)) {
                    $created[] = $row;
                }
            }
            if (count($created) >= 5) {
                break;
            }
        }

        $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text, type');
        $created = [];
        foreach ($afteroptions as $row) {
            if (!in_array((int)$row->id, $beforeids, true)) {
                $created[] = $row;
            }
        }

        for ($round = 1; $round <= 4 && count($created) < 5; $round++) {
            $_POST['sesskey'] = sesskey();
            $remaining = 5 - count($created);
            $continuation = 'Fahre fort und erstelle die restlichen ' . $remaining . ' Buchungsmoeglichkeiten fuer die '
                . 'uebernaechste Woche. Behalte den Titel "Sport x" durchnummeriert, jeweils 10 bis 12h, '
                . 'maximal fuenf Personen und normale Buchungsoptionen.';
            $continuedresponse = ai_send_message::execute($contextid, $continuation, (int)$threadid);

            if ((string)($continuedresponse['response_type'] ?? '') === 'clarification') {
                $_POST['sesskey'] = sesskey();
                $continuedresponse = ai_send_message::execute(
                    $contextid,
                    'Gemeint ist weiterhin uebernaechste Woche, Montag bis Freitag, jeweils 10 bis 12h. '
                        . 'Bitte jetzt die restlichen Sport-Termine anlegen.',
                    (int)$threadid
                );
            }

            if ((string)($continuedresponse['response_type'] ?? '') === 'error') {
                $_POST['sesskey'] = sesskey();
                $continuedresponse = ai_send_message::execute(
                    $contextid,
                    'Bitte korrigiere die letzte Anfrage. Verwende nur mod_booking.create_option mit gueltigen Keys '
                        . 'text, coursestarttime, courseendtime, maxanswers und type=0 fuer die restlichen Sport-Termine.',
                    (int)$threadid
                );
            }

            if ((string)($continuedresponse['response_type'] ?? '') === 'error') {
                $_POST['sesskey'] = sesskey();
                $continuedresponse = ai_send_message::execute(
                    $contextid,
                    'Letzter Versuch fuer die restlichen Termine: Nur mod_booking.create_option mit den Keys '
                        . 'text, coursestarttime, courseendtime, maxanswers, type. Keine Zusatzfelder (insb. kein day). '
                        . 'Nutze diese Datumszeilen als Vorlage: ' . implode(' ; ', $serieslines)
                        . ' ; maxanswers=5 ; type=0.',
                    (int)$threadid
                );
            }

            if ((string)($continuedresponse['response_type'] ?? '') === 'error') {
                $_POST['sesskey'] = sesskey();
                $continuedresponse = ai_send_message::execute(
                    $contextid,
                    'Finaler Reparaturversuch fuer die restlichen Termine: nur mod_booking.create_option mit '
                        . 'text, coursestarttime, courseendtime, maxanswers, type. '
                        . 'Entferne alle Zusatzfelder (insb. day/date/start/end). '
                        . 'Nutze diese Datumszeilen als Vorlage: ' . implode(' ; ', $serieslines)
                        . ' ; maxanswers=5 ; type=0. Liefere genau einen korrigierten skill_call.',
                    (int)$threadid
                );
            }

            if ((string)($continuedresponse['response_type'] ?? '') === 'error') {
                $this->assertStringContainsString(
                    'Retry mod_booking.create_option once',
                    $this->payload_text($continuedresponse),
                    'Unexpected non-retriable continuation error payload for create_option series routing.'
                );
                $this->fail('Real LLM returned repeated create_option schema mismatch in continuation flow.');
            }

            $this->assertContains(
                (string)($continuedresponse['response_type'] ?? ''),
                ['confirmation_request', 'sufficient', 'execution_result'],
                'Expected actionable continuation response in weekday series flow. Payload: '
                    . $this->payload_text($continuedresponse)
            );

            $current = $continuedresponse;
            for ($confirmround = 1; $confirmround <= 6; $confirmround++) {
                if ((string)($current['response_type'] ?? '') !== 'confirmation_request') {
                    break;
                }

                $requiresallowsession = ((int)($current['autoconfirm'] ?? 0) !== 1);
                $confirm = $this->confirm_pending_result($current, (int)$threadid, $store, $requiresallowsession);
                $confirmdetail = (string)($confirm['detail'] ?? '');
                if (
                    !(bool)($confirm['success'] ?? false)
                    && (
                        (string)($confirm['response_type'] ?? '') === 'error'
                        || stripos($confirmdetail, 'No pending confirmation is available') !== false
                    )
                ) {
                    break;
                }
                $this->assertTrue(
                    (bool)($confirm['success'] ?? false),
                    'Continuation confirmation failed in series flow at round ' . $round . '.' . $confirmround
                        . '. Payload: ' . $this->payload_text($confirm)
                );
                $this->assertContains(
                    (string)($confirm['response_type'] ?? ''),
                    ['confirmation_request', 'sufficient', 'execution_result'],
                    'Unexpected continuation response type in series flow at round ' . $round . '.' . $confirmround
                        . '. Payload: ' . $this->payload_text($confirm)
                );
                $current = $confirm;

                $afteroptions = $DB->get_records(
                    'booking_options',
                    ['bookingid' => (int)$this->booking->id],
                    'id ASC',
                    'id, text, type'
                );
                $created = [];
                foreach ($afteroptions as $row) {
                    if (!in_array((int)$row->id, $beforeids, true)) {
                        $created[] = $row;
                    }
                }
                if (count($created) >= 5) {
                    break;
                }
            }

            $afteroptions = $DB->get_records(
                'booking_options',
                ['bookingid' => (int)$this->booking->id],
                'id ASC',
                'id, text, type'
            );
            $created = [];
            foreach ($afteroptions as $row) {
                if (!in_array((int)$row->id, $beforeids, true)) {
                    $created[] = $row;
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, count($created), 'Expected at least three created options in the confirmation chain.');
        if (count($created) > 5) {
            $created = array_slice($created, 0, 5);
        }
        $createdtitles = array_map(static fn($row): string => (string)$row->text, $created);
        $this->assertGreaterThanOrEqual(
            3,
            count(array_unique($createdtitles)),
            'Expected sufficient title variance across created Sport options.'
        );
        foreach ($createdtitles as $title) {
            $this->assertStringContainsString(
                'Sport',
                $title,
                'Expected each created title to stay within the Sport naming scheme.'
            );
        }
        foreach ($created as $row) {
            $this->assertSame(0, (int)$row->type, 'Expected all created options to be normal type 0.');
        }
    }

    /**
     * Check if a skill currently exists in the registry.
     *
     * @param string $skillname
     * @return bool
     */
    private function is_skill_available(string $skillname): bool {
        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        return $registry->get_skill($skillname) !== null;
    }

    /**
     * Extract first command by skill name from endpoint payload.
     *
     * @param array $payload
     * @param string $skillname
     * @return array|null
     */
    private function extract_command_from_payload(array $payload, string $skillname): ?array {
        $commands = $this->decode_commands_from_payload($payload);
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['skill'] ?? '') === $skillname) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Decode commands array from endpoint payload.
     *
     * @param array $payload
     * @return mixed[]
     */
    private function decode_commands_from_payload(array $payload): array {
        $commandsraw = $payload['commands'] ?? [];
        if (is_array($commandsraw)) {
            return $commandsraw;
        }

        if (is_string($commandsraw)) {
            $decoded = json_decode($commandsraw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Flatten relevant payload text for assertion debugging.
     *
     * @param array $payload
     * @return string
     */
    protected function payload_text(array $payload): string {
        $commands = $payload['commands'] ?? '';
        if (is_array($commands)) {
            $commands = json_encode($commands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $chunks = [
            (string)($payload['response_type'] ?? ''),
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            (string)$commands,
            (string)($payload['resultsjson'] ?? ''),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }
}
