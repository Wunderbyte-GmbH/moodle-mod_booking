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
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use context_course;

/**
 * Preflight de-anonymization must use the RUN's thread — channel threads included.
 *
 * Root cause behind the 2026-07-14 #11 teacher-step observations: preflight de-anonymized via
 * get_active_thread(userid, contextid), which filters on status='active' and is therefore blind
 * to MCP channel threads (status = session channel) — their commands reached skill resolution
 * with raw ANON_USER_* tokens (and with a chat thread open at the same context, the WRONG map
 * could even be read). The pipeline receives the real threadid; it must de-anonymize against
 * exactly that thread's token map.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class preflight_dean_channel_thread_test extends advanced_testcase {
    /**
     * A token minted on an MCP channel thread resolves in that thread's preflight run.
     */
    public function test_channel_thread_token_resolves_in_preflight(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int)context_course::instance($course->id)->id;
        $userid = (int)$USER->id;

        $store = new conversation_store();
        $channelthread = $store->get_or_create_channel_thread($userid, $contextid, 'mcptestsession');
        $channelthreadid = (int)$channelthread->id;

        // Mint the email token on the CHANNEL thread's map (as backend anonymization does).
        $anonymizer = new privacy_anonymizer($store);
        $masked = (string)$anonymizer->anonymize_value_for_llm(
            $channelthreadid,
            'Contact billy.trainer@example.com for details.'
        );
        $this->assertSame(1, preg_match('/ANON_USER_\d+@anon\.invalid/', $masked, $m));
        $token = $m[0];

        $pipeline = new preflight_pipeline($this->build_registry(), $store);
        $result = $pipeline->run(
            [[
                'skill' => 'demo.deanecho',
                'input' => ['teacheremail' => $token],
                '_structural_validated' => true,
            ]],
            $channelthreadid,
            $contextid,
            $userid
        );

        $prepared = (array)(($result['prepared_commands'][0] ?? [])['input'] ?? []);
        $this->assertSame(
            'billy.trainer@example.com',
            (string)($prepared['teacheremail'] ?? ''),
            'Preflight must de-anonymize against the run thread\'s map — channel threads have no '
                . '"active" status, so an active-thread lookup leaves the raw token in the input.'
        );
    }

    /**
     * Registry with a pass-through skill whose prepared input mirrors what preflight received.
     *
     * @return skill_registry
     */
    private function build_registry(): skill_registry {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(
            static fn(string $name): ?skill_interface => $name === 'demo.deanecho' ? self::echo_skill() : null
        );
        $registry->method('get_skill_contract')->willReturnCallback(
            static fn(string $name): ?array => ['skill' => $name, 'version' => 1]
        );

        return $registry;
    }

    /**
     * Minimal ambient-context skill: preflight passes and echoes its input as prepared input.
     *
     * @return skill_interface
     */
    private static function echo_skill(): skill_interface {
        return new class implements skill_interface {
            /**
             * Return the unique skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'demo.deanecho';
            }

            /**
             * Return the input schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['version' => 1, 'properties' => []];
            }

            /**
             * Return an example input payload.
             *
             * @return array
             */
            public function get_example_input(): array {
                return [];
            }

            /**
             * Return the prompt contract describing this skill.
             *
             * @return skill_prompt_contract
             */
            public function get_prompt_contract(): skill_prompt_contract {
                return new skill_prompt_contract([
                    'intent' => 'demo',
                    'anchors' => [],
                    'minimal_input' => [],
                    'example_input' => [],
                    'namespace' => 'demo',
                    'version' => 1,
                    'capabilities' => [],
                    'context_scopes' => ['course', 'module', 'system'],
                    'risk_class' => skill_risk_class::R2,
                ]);
            }

            /**
             * Return the risk class of this skill.
             *
             * @return string
             */
            public function get_risk_class(): string {
                return skill_risk_class::R2;
            }

            /**
             * Validate the raw input structure.
             *
             * @param array $input
             * @return array
             */
            public function check_structure(array $input): array {
                return ['valid' => true, 'errors' => []];
            }

            /**
             * Pass and mirror the (de-anonymized) input as prepared input.
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return preflight_result_v2
             */
            public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
                return preflight_result_v2::ok($input);
            }

            /**
             * Execute the skill against the prepared input.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }

            /**
             * Report whether the skill is read-only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return false;
            }
        };
    }
}
