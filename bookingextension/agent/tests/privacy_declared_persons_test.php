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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * Skill observations that DECLARE their persons (Wunderbyte-GmbH#2614).
 *
 * An observation is skill template text plus data. The engine could not tell the two apart and masked every
 * word that is also a site account's name: "Admin action:" became "ANON_USER_1_firstname action:" (thread 31983,
 * accounts "Admin Torvaldsen" and "Note Raimundi"). A result entry now names the persons its observation
 * contains (`observation_persons`, user ids); the engine masks exactly those - every variant, the single first
 * name included - plus every value the thread map already knows and full names of other accounts, and runs
 * no single-word fallback on such an entry. An entry without the declaration keeps the full detection (P2).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 * @covers     \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 */
final class privacy_declared_persons_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Accounts whose names are template words ("Admin User" is the phpunit admin, "Note Raimundi" the second),
     * a trainer, a thread of the admin, the anonymizer and the synchronizer input builder.
     *
     * @return array{0: conversation_store, 1: privacy_anonymizer, 2: int, 3: int, 4: synchronizer_input_builder}
     */
    private function prepare(): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user([
            'firstname' => 'Note',
            'lastname' => 'Raimundi',
            'email' => 'note.raimundi@example.com',
        ]);
        $trainer = $this->getDataGenerator()->create_user([
            'firstname' => 'Gerda',
            'lastname' => 'Huber',
            'email' => 'gerda.huber@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        set_config('aiprivacyprotectedwords', implode(', ', privacy_anonymizer::PROTECTED_WORDS_DEFAULT), 'bookingextension_agent');
        \cache::make('bookingextension_agent', 'aiprivacynames')->purge();

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;

        return [$store, new privacy_anonymizer($store), $threadid, (int)$trainer->id, new synchronizer_input_builder()];
    }

    /**
     * Number of entries in the thread's token map.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return int
     */
    private function map_size(conversation_store $store, int $threadid): int {
        $map = $store->get_thread_metadata_value($threadid, 'privacy_anon_map');
        if (is_string($map)) {
            $map = json_decode($map, true);
        }
        return is_array($map) && is_array($map['entries'] ?? null) ? count($map['entries']) : 0;
    }

    /**
     * The ticket's observation with no person in it: declared empty, the template words stay and no token is minted.
     */
    public function test_declared_observation_without_persons_keeps_template_words(): void {
        [$store, $anonymizer, $threadid, , $builder] = $this->prepare();
        $observation = 'Concrete state: User (id=65) is not enrolled in the course. Admin action: Verify the option '
            . 'conditions. Supplementary note: the enrolment is checked by the admin.';
        $result = ['response_type' => 'execution_result', 'results' => [[
            'skill' => 'mod_booking.diagnose_booking_issue',
            'status' => 'executed',
            'observation_full' => $observation,
            privacy_anonymizer::RESULT_OBSERVATION_PERSONS => [],
        ]]];

        $masked = $builder->mask_for_llm($result, $anonymizer, $threadid);

        $this->assertSame($observation, (string)$masked['results'][0]['observation_full'], 'Template words are no persons.');
        $this->assertSame(0, $this->map_size($store, $threadid), 'No token was minted.');
    }

    /**
     * A declared person is masked in every variant, the single first name included, while the template words
     * around it ("will", "note" are account names too) stay.
     */
    public function test_declared_person_is_masked_in_every_variant(): void {
        [, $anonymizer, $threadid, $trainerid, $builder] = $this->prepare();
        $result = ['response_type' => 'execution_result', 'results' => [[
            'skill' => 'mod_booking.get_option_details',
            'status' => 'executed',
            'observation_full' => 'Trainer: Gerda will send the note. Contact: Gerda Huber, gerda.huber@example.com.',
            privacy_anonymizer::RESULT_OBSERVATION_PERSONS => [$trainerid],
        ]]];

        $masked = (string)$builder->mask_for_llm($result, $anonymizer, $threadid)['results'][0]['observation_full'];

        $this->assertStringNotContainsString('Gerda', $masked);
        $this->assertStringNotContainsString('Huber', $masked);
        $this->assertStringNotContainsString('example.com', $masked);
        $this->assertMatchesRegularExpression('/Trainer: ANON_USER_\d+_firstname will send the note\./', $masked);
        $this->assertMatchesRegularExpression('/Contact: ANON_USER_\d+_both, ANON_USER_\d+@anon\.invalid\./', $masked);
    }

    /**
     * Negative case (P2): an entry WITHOUT the declaration keeps the full detection, template words included.
     */
    public function test_undeclared_observation_keeps_the_full_detection(): void {
        [, $anonymizer, $threadid, , $builder] = $this->prepare();
        $result = ['response_type' => 'execution_result', 'results' => [[
            'skill' => 'mod_booking.diagnose_booking_issue',
            'status' => 'executed',
            'observation_full' => 'Admin action: Verify the option conditions.',
        ]]];

        $masked = (string)$builder->mask_for_llm($result, $anonymizer, $threadid)['results'][0]['observation_full'];

        $this->assertMatchesRegularExpression('/^ANON_USER_\d+_firstname action:/', $masked, 'Undeclared: as before.');
    }

    /**
     * The step observation (agent_runtime) follows the same rule: all observation-carrying entries declared ->
     * declared treatment; one undeclared entry -> full detection; engine-static entries stay as they are.
     */
    public function test_step_observation_follows_the_same_rule(): void {
        [, $anonymizer, $threadid, $trainerid] = $this->prepare();
        $observation = "Step 1: Admin action: Verify. Trainer: Gerda.";
        $declared = [
            ['skill' => 'a', 'status' => 'executed', 'observation_full' => 'Admin action: Verify.',
                privacy_anonymizer::RESULT_OBSERVATION_PERSONS => []],
            ['skill' => 'b', 'status' => 'executed', 'observation_full' => 'Trainer: Gerda.',
                privacy_anonymizer::RESULT_OBSERVATION_PERSONS => [$trainerid]],
        ];
        $masked = $anonymizer->mask_observation_for_llm($threadid, $declared, $observation);
        $this->assertMatchesRegularExpression(
            '/^Step 1: Admin action: Verify\. Trainer: ANON_USER_\d+_firstname\.$/',
            $masked
        );

        $mixed = $declared;
        unset($mixed[0][privacy_anonymizer::RESULT_OBSERVATION_PERSONS]);
        $masked = $anonymizer->mask_observation_for_llm($threadid, $mixed, $observation);
        $this->assertMatchesRegularExpression(
            '/^Step 1: ANON_USER_\d+_firstname action:/',
            $masked,
            'One undeclared entry: full detection.'
        );

        $static = [['skill' => 'wizard.search_skills', 'status' => 'executed', 'observation_engine_static' => true,
            'observation_full' => 'Admin action: catalog text.']];
        $this->assertSame(
            'Admin action: catalog text.',
            $anonymizer->mask_observation_for_llm($threadid, $static, 'Admin action: catalog text.')
        );
    }

    /**
     * The observation ledger carries the declaration into the next calls' runtime context: a declared result
     * yields a row with the same persons, an undeclared one a row without the key (so the context block can
     * apply the same three-way rule one call later - where "Admin" used to be re-minted).
     */
    public function test_ledger_rows_carry_the_declaration(): void {
        [$store, , $threadid, $trainerid] = $this->prepare();
        $ledger = new \bookingextension_agent\local\wizard\services\execution_observation_ledger($store);
        $ledger->append_from_results($threadid, [
            ['skill' => 'mod_booking.diagnose_booking_issue', 'status' => 'executed',
                'observation_full' => 'Admin action: Verify. Trainer: Gerda.',
                privacy_anonymizer::RESULT_OBSERVATION_PERSONS => [$trainerid]],
            ['skill' => 'mod_booking.search_options', 'status' => 'executed', 'observation_full' => '3 options found.'],
        ], ['commands' => [['skill' => 'mod_booking.diagnose_booking_issue'], ['skill' => 'mod_booking.search_options']]]);

        $rows = $ledger->get_recent_for_runtime($threadid, 12);

        $this->assertCount(2, $rows);
        $this->assertSame([$trainerid], $rows[0]['declared_persons']);
        $this->assertArrayNotHasKey('declared_persons', $rows[1]);
    }

    /**
     * The result sanitizer that feeds the loop, the ledger and the client keeps the declaration (it whitelists
     * result keys; without this the step observation never saw it and minted "Admin" as before).
     */
    public function test_the_result_sanitizer_keeps_the_declaration(): void {
        global $USER;
        [$store, , $threadid, $trainerid] = $this->prepare();
        $service = new \bookingextension_agent\local\wizard\services\execution\execution_feedback_service($store);
        $results = [
            ['skill' => 'mod_booking.diagnose_booking_issue', 'status' => 'executed', 'detail' => 'Diagnosis done.',
                'observation_full' => 'Admin action: Verify. Trainer: Gerda.',
                privacy_anonymizer::RESULT_OBSERVATION_PERSONS => [$trainerid, (string)$trainerid, 0]],
            ['skill' => 'mod_booking.search_options', 'status' => 'executed', 'detail' => 'Found.',
                'observation_full' => '3 options found.'],
        ];

        $feedback = $service->build_completion_feedback($threadid, 0, (int)$USER->id, [], $results);

        $this->assertSame([$trainerid], $feedback['results'][0][privacy_anonymizer::RESULT_OBSERVATION_PERSONS]);
        $this->assertArrayNotHasKey(privacy_anonymizer::RESULT_OBSERVATION_PERSONS, $feedback['results'][1]);
    }
}
