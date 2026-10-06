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

use bookingextension_agent\local\wizard\services\finalization_template_service;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * Technical texts never become user material (agent baseline run 8, F61).
 *
 * Thread 1512 (ACS-4): a provider transport failure surfaced as "Invalid error code: 0" in the
 * reply. Thread 1463 (DBI-3): the synchronizer quoted RUN_ABANDONED_ALL_STEPS_FAILED because the
 * observations listed the raw issue codes.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\provider_error_result_trait
 * @covers     \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @covers     \bookingextension_agent\local\wizard\services\finalization_template_service
 */
final class provider_error_user_cause_test extends \advanced_testcase {
    /**
     * Build a provider error payload through the shared trait.
     *
     * @param array $call Provider call result.
     * @return array
     */
    private function build_provider_error(array $call): array {
        $builder = new class {
            use \bookingextension_agent\local\wizard\provider_error_result_trait;

            /**
             * Expose the trait method.
             *
             * @param array $call
             * @return array
             */
            public function run(array $call): array {
                return $this->build_provider_error_result($call);
            }
        };
        return $builder->run($call);
    }

    /**
     * The raw provider exception text is an admin diagnostic, never a user cause.
     */
    public function test_provider_exception_text_is_not_a_user_cause(): void {
        $this->resetAfterTest();
        $raw = 'Invalid error code: 0';
        $result = $this->build_provider_error(['errormessage' => $raw, 'errorcode' => 0, 'errorname' => '']);

        $this->assertNotEmpty($result['errors']);
        foreach ((array)$result['errors'] as $error) {
            $this->assertStringNotContainsString($raw, (string)$error);
        }
        $observations = implode("\n", (new synchronizer_input_builder())->build_observations($result));
        $this->assertStringNotContainsString($raw, $observations);

        $template = new finalization_template_service();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertStringNotContainsString($raw, $template->resolve_message($result));
        $this->setAdminUser();
        $this->assertStringContainsString($raw, $template->resolve_message($result), 'admins keep the raw detail');
    }

    /**
     * Synchronizer observations carry causes and error classes, never the raw issue codes.
     */
    public function test_observations_never_list_raw_issue_codes(): void {
        $builder = new synchronizer_input_builder();
        foreach (['error', 'sufficient', 'clarification'] as $responsetype) {
            $observations = implode("\n", $builder->build_observations([
                'response_type' => $responsetype,
                'message' => 'Nobody matched the name.',
                'error_class' => 'skill_exception',
                'issue_codes' => ['RUN_ABANDONED_ALL_STEPS_FAILED', 'SOME_ENGINE_CODE'],
                'errors' => ['No user matched the query.'],
            ]));
            $this->assertStringNotContainsString('RUN_ABANDONED_ALL_STEPS_FAILED', $observations, $responsetype);
            $this->assertStringNotContainsString('SOME_ENGINE_CODE', $observations, $responsetype);
        }
    }
}
