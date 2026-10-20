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
 * Enhanced content generation: the content services run on the content model only behind the admin switch.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\course\course_content_generation_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\wb_action_names;
use core_ai\aiactions\generate_text;

/**
 * The switch routes outlines, chapters and questions to generate_content; a failed content call falls back to the
 * default model and the services say so.
 *
 * @covers \bookingextension_agent\local\wizard\services\llm\llm_call_service
 * @covers \bookingextension_agent\local\wizard\services\course\course_content_generation_service
 */
final class content_model_route_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * Off by default: the content services call generate_text.
     */
    public function test_content_runs_on_the_default_model_while_the_switch_is_off(): void {
        set_config('contentmodel_enabled', 0, 'bookingextension_agent');

        $this->assertFalse(llm_call_service::content_model_enabled());
        $this->assertSame(generate_text::class, llm_call_service::content_action_class());
    }

    /**
     * The switch alone changes nothing while no provider instance offers the action.
     */
    public function test_the_switch_needs_a_provider_that_offers_the_action(): void {
        set_config('contentmodel_enabled', 1, 'bookingextension_agent');

        $this->assertFalse(llm_call_service::content_model_enabled());
        $this->assertSame(generate_text::class, llm_call_service::content_action_class());
    }

    /**
     * Switched on with an instance that offers generate_content, the content services call that action.
     */
    public function test_content_runs_on_the_content_model_when_switched_on_and_offered(): void {
        set_config('contentmodel_enabled', 1, 'bookingextension_agent');
        $this->create_provider_with_content_action();

        $this->assertTrue(llm_call_service::content_model_enabled());
        $this->assertSame(wb_action_names::GENERATE_CONTENT, llm_call_service::content_action_class());
    }

    /**
     * A failed content call is repeated once on the default model; the result carries the fallback.
     */
    public function test_a_failed_content_call_falls_back_to_the_default_model(): void {
        llm_call_service::set_test_responder(static function (string $actionclass, string $prompt, string $source): array {
            if (ltrim($actionclass, '\\') === wb_action_names::GENERATE_CONTENT) {
                return ['success' => false, 'errormessage' => 'alias not in key'];
            }
            return ['content' => 'OK'];
        });

        $call = (new llm_call_service(new conversation_store()))->invoke_for_context(
            0,
            \context_system::instance()->id,
            2,
            'probe',
            'Reply with OK.',
            wb_action_names::GENERATE_CONTENT
        );

        $this->assertTrue($call['success']);
        $this->assertSame('OK', $call['rawcontent']);
        $this->assertTrue($call['fallback']);
        $this->assertSame('alias not in key', $call['fallbackerror']);
    }

    /**
     * The outline service reports whether the default model had to step in.
     */
    public function test_the_outline_service_reports_the_fallback(): void {
        $outline = json_encode([
            'welcometitle' => 'Willkommen',
            'welcomehtml' => '<p>Hallo</p>',
            'overviewhtml' => '<p>Überblick</p>',
            'chapters' => [['title' => 'Kapitel 1'], ['title' => 'Kapitel 2']],
            'summarytitle' => 'Zusammenfassung',
            'summaryhtml' => '<p>Ende</p>',
        ]);
        llm_call_service::set_test_responder(static function (string $actionclass) use ($outline): array {
            if (ltrim($actionclass, '\\') === wb_action_names::GENERATE_CONTENT) {
                return ['success' => false, 'errormessage' => 'alias not in key'];
            }
            return ['content' => $outline];
        });
        $service = new course_content_generation_service(new conversation_store());
        $contextid = \context_system::instance()->id;

        set_config('contentmodel_enabled', 0, 'bookingextension_agent');
        $result = $service->generate_outline(0, $contextid, 2, 'Wikinger', 2, 'de');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['fallback']);

        set_config('contentmodel_enabled', 1, 'bookingextension_agent');
        $this->create_provider_with_content_action();
        $result = $service->generate_outline(0, $contextid, 2, 'Wikinger', 2, 'de');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['fallback']);
        $this->assertSame(['Kapitel 1', 'Kapitel 2'], $result['outline']['chapters']);
    }

    /**
     * The confirmation note names the content model only for content skills and only while the switch works.
     */
    public function test_the_card_note_applies_to_content_skills_only_while_switched_on(): void {
        $scaffold = [['skill' => 'course.scaffold_course_content', 'input' => []]];
        $questions = [['skill' => 'question.generate_questions', 'input' => []]];
        $option = [['skill' => 'mod_booking.create_option', 'input' => []]];

        set_config('contentmodel_enabled', 0, 'bookingextension_agent');
        $this->assertFalse(llm_call_service::content_model_note_applies($scaffold));

        set_config('contentmodel_enabled', 1, 'bookingextension_agent');
        $this->create_provider_with_content_action();
        $this->assertTrue(llm_call_service::content_model_note_applies($scaffold));
        $this->assertTrue(llm_call_service::content_model_note_applies($questions));
        $this->assertFalse(llm_call_service::content_model_note_applies($option));
        $this->assertFalse(llm_call_service::content_model_note_applies([]));
    }

    /**
     * A Wunderbyte provider instance with the content action enabled.
     *
     * @return \core_ai\provider
     */
    private function create_provider_with_content_action(): \core_ai\provider {
        $manager = \core\di::get(\core_ai\manager::class);
        $settings = ['endpoint' => 'https://llm.wunderbyte.at/v1/chat/completions'];
        return $manager->create_provider_instance(
            classname: '\aiprovider_wunderbyte\provider',
            name: 'content',
            enabled: true,
            config: ['apikey' => '123'],
            actionconfig: [
                generate_text::class => ['enabled' => true, 'settings' => $settings + ['model' => 'wunderbyte-privat']],
                wb_action_names::GENERATE_CONTENT => [
                    'enabled' => true,
                    'settings' => $settings + ['model' => 'wunderbyte-privat-content'],
                ],
            ],
        );
    }
}
