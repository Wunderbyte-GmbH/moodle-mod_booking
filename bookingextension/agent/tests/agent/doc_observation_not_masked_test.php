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

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\execution\execution_feedback_service;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * L43, ED-1 thread 13343 (George 2026-09-26: documentation observations are exempt from masking): the waiting-list
 * excerpt reached every later prompt as "ANON_USER_2_firstname. number of participants" - the site has a user called
 * Max, and "Max." in the shipped documentation was masked as her first name. explain_docs declares its observation
 * engine-static, but execution_feedback_service dropped the flag while sanitizing the results, so no masking layer
 * ever saw it. The flag now survives, and only a skill whose observation carries no user-provided or personal text
 * declares it: search_skills echoes the (de-anonymized) query and diagnose_user_in_course names the person, so both
 * stay masked - exactly as they were, because their flag never took effect either.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\execution\execution_feedback_service
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class doc_observation_not_masked_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Provider, capabilities, strict privacy and a site user whose first name is a word of the documentation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        $this->getDataGenerator()->create_user(['firstname' => 'Max', 'lastname' => 'Quellhuber']);
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The sanitized result keeps the skill's engine-static declaration.
     */
    public function test_sanitized_results_keep_the_engine_static_flag(): void {
        $service = new execution_feedback_service(new conversation_store());
        $method = new \ReflectionMethod(execution_feedback_service::class, 'sanitize_results_for_client');
        $method->setAccessible(true);

        $sanitized = $method->invoke($service, [
            ['status' => 'executed', 'skill' => 'wizard.explain_docs', 'observation_full' => 'Doc text',
                'observation_engine_static' => true],
            ['status' => 'executed', 'skill' => 'core.search_users', 'observation_full' => 'Person text'],
        ]);

        $this->assertTrue($sanitized[0]['observation_engine_static'] ?? false);
        $this->assertArrayNotHasKey('observation_engine_static', $sanitized[1]);
    }

    /**
     * Thread 13343: the real explain_docs result passes the result sanitizer and both masking layers (next planner
     * step, synchronizer) with the documentation text intact; a word that is also a user's first name stays a word.
     */
    public function test_the_documentation_excerpt_passes_both_masking_layers(): void {
        global $USER;
        $this->setAdminUser();
        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $anonymizer = new privacy_anonymizer($store);
        $anonymizer->precheck_user_message($threadid, 'Ich check das Wartelisten-Verhalten nicht - wie ist das gedacht?');

        $raw = (new explain_docs_skill())->execute([
            'question' => 'Ich check das Wartelisten-Verhalten nicht - wie ist das im Plugin gedacht?',
            'doc_path' => 'booking-option/09-waitinglist.md',
        ], (int)\context_system::instance()->id, (int)$USER->id);
        $raw['skill'] = explain_docs_skill::SKILL_NAME;
        $this->assertStringContainsString(
            'Max. number of participants',
            (string)($raw['observation_full'] ?? ''),
            'Precondition: the excerpt carries the word.'
        );
        $this->assertMatchesRegularExpression(
            '/ANON_USER_\\d+_firstname\\. number/',
            (string)$anonymizer->anonymize_value_for_llm($threadid, 'Max. number of participants'),
            'Precondition: without the exemption the word is masked as a first name (thread 13343).'
        );

        $method = new \ReflectionMethod(execution_feedback_service::class, 'sanitize_results_for_client');
        $method->setAccessible(true);
        $sanitized = $method->invoke(new execution_feedback_service($store), [$raw]);

        $runtime = (new \ReflectionClass(agent_runtime::class))->newInstanceWithoutConstructor();
        $storeprop = new \ReflectionProperty(agent_runtime::class, 'store');
        $storeprop->setAccessible(true);
        $storeprop->setValue($runtime, $store);
        $mask = new \ReflectionMethod(agent_runtime::class, 'mask_step_observation_for_llm');
        $mask->setAccessible(true);
        $planner = (string)$mask->invoke($runtime, $threadid, $sanitized, (string)$sanitized[0]['observation_full']);
        $this->assertStringContainsString('Max. number of participants', $planner, 'next planner step');

        $builder = new synchronizer_input_builder();
        $masked = $builder->mask_for_llm(['response_type' => 'sufficient', 'results' => $sanitized], $anonymizer, $threadid);
        $this->assertStringContainsString(
            'Max. number of participants',
            (string)$masked['results'][0]['observation_full'],
            'synchronizer'
        );
    }

    /**
     * Observations that carry user-provided or personal text do not declare themselves engine-static.
     */
    public function test_person_and_query_observations_stay_masked(): void {
        $root = __DIR__ . '/../../classes/local/wizard/';
        foreach (
            [
                'wizard/skills/search_skills_skill.php',
                'course/skills/diagnose_user_in_course_skill.php',
            ] as $file
        ) {
            $this->assertStringNotContainsString(
                "'observation_engine_static' => true",
                (string)file_get_contents($root . $file),
                $file
            );
        }
    }
}
