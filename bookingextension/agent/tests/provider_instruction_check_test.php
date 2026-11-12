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
use bookingextension_agent\local\wizard\aiready;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\provider_instruction_check;
use bookingextension_agent\local\wizard\skill_registry_factory;
use core\di;
use core_ai\manager as ai_manager;

/**
 * The wizard names a system instruction on an AI provider action it uses (Wunderbyte-GmbH/Wunderbyte-GmbH#2724).
 *
 * Without the Wunderbyte provider the wizard routes selection and construction to "Summarise text" and the reply to
 * "Generate text". Core sends the provider action's system instruction with every call; with Moodle's default
 * ("Your task is to summarize the provided text…") Gemini summarised the wizard prompt instead of answering it
 * (Moodle 5.3, 4 of 4 answers). The routing stays as it is; the wizard shows the setting so an admin can empty it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\provider_instruction_check
 * @covers     \bookingextension_agent\local\wizard\aiready
 */
final class provider_instruction_check_test extends advanced_testcase {
    /** @var string Summarise text action. */
    private const SUMMARISE = 'core_ai\\aiactions\\summarise_text';

    /** @var string Generate text action. */
    private const GENERATE = 'core_ai\\aiactions\\generate_text';

    /**
     * A provider view as provider_compat::get_provider_views() returns it.
     *
     * @param string $name
     * @param bool $enabled
     * @param array $actions action class => [enabled, system instruction]
     * @return object
     */
    private function view(string $name, bool $enabled, array $actions): object {
        $actionconfig = [];
        foreach ($actions as $actionclass => [$actionenabled, $instruction]) {
            $actionconfig[$actionclass] = ['enabled' => $actionenabled, 'settings' => ['systeminstruction' => $instruction]];
        }
        return (object)[
            'id' => 7,
            'provider' => 'aiprovider_gemini\\provider',
            'name' => $name,
            'enabled' => $enabled,
            'config' => [],
            'actionconfig' => $actionconfig,
        ];
    }

    /**
     * An enabled action of an enabled provider with a system instruction is reported, with a link to its settings.
     */
    public function test_an_action_with_a_system_instruction_is_reported(): void {
        $this->resetAfterTest();
        $views = [$this->view('gemini-temp', true, [
            self::SUMMARISE => [true, 'Your task is to summarize the provided text.'],
            self::GENERATE => [true, ''],
        ])];

        $conflicts = provider_instruction_check::find_conflicts([self::SUMMARISE, self::GENERATE], $views);

        $this->assertCount(1, $conflicts);
        $this->assertSame(self::SUMMARISE, $conflicts[0]['action']);
        $this->assertSame('gemini-temp', $conflicts[0]['provider']);
        $this->assertSame(\core_ai\aiactions\summarise_text::get_name(), $conflicts[0]['actionname']);
        $url = new \moodle_url($conflicts[0]['settingsurl']);
        $this->assertStringEndsWith('/ai/configure_actions.php', $url->get_path(false));
        $this->assertSame('aiprovider_gemini', $url->get_param('provider'));
        $this->assertSame(self::SUMMARISE, $url->get_param('action'));
        $this->assertSame('7', (string)$url->get_param('providerid'));
    }

    /**
     * No report without an instruction, for a switched-off action or provider, or for an action the wizard does not use.
     */
    public function test_nothing_is_reported_when_the_instruction_cannot_reach_the_wizard(): void {
        $this->resetAfterTest();
        $cases = [
            'empty instruction' => [$this->view('p', true, [self::SUMMARISE => [true, '']]), [self::SUMMARISE]],
            'only whitespace' => [$this->view('p', true, [self::SUMMARISE => [true, " \n "]]), [self::SUMMARISE]],
            'action switched off' => [$this->view('p', true, [self::SUMMARISE => [false, 'x']]), [self::SUMMARISE]],
            'provider switched off' => [$this->view('p', false, [self::SUMMARISE => [true, 'x']]), [self::SUMMARISE]],
            'action not used' => [$this->view('p', true, [self::SUMMARISE => [true, 'x']]), [self::GENERATE]],
        ];
        foreach ($cases as $label => [$view, $actions]) {
            $this->assertSame([], provider_instruction_check::find_conflicts($actions, [$view]), $label);
        }
    }

    /**
     * Every enabled provider that offers the action is reported: core falls back to the next one when one fails.
     */
    public function test_each_provider_with_an_instruction_is_reported(): void {
        $this->resetAfterTest();
        $views = [
            $this->view('first', true, [self::SUMMARISE => [true, 'x']]),
            $this->view('second', true, [self::SUMMARISE => [true, 'y']]),
        ];
        $conflicts = provider_instruction_check::find_conflicts([self::SUMMARISE], $views);
        $this->assertSame(['first', 'second'], array_column($conflicts, 'provider'));
    }

    /**
     * Create an enabled core OpenAI instance with the given system instructions (Moodle 5.x).
     *
     * @param string $summarise Instruction of Summarise text.
     * @param string $generate Instruction of Generate text.
     * @return \core_ai\provider
     */
    private function openai_instance(string $summarise, string $generate): \core_ai\provider {
        if (
            !method_exists(ai_manager::class, 'create_provider_instance')
            || !\core_component::get_plugin_directory('aiprovider', 'openai')
        ) {
            $this->markTestSkipped('needs Moodle 5.x provider instances and aiprovider_openai');
        }
        $settings = fn(string $instruction): array => [
            'enabled' => true,
            'settings' => ['endpoint' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-4o',
                'systeminstruction' => $instruction],
        ];
        return di::get(ai_manager::class)->create_provider_instance(
            classname: 'aiprovider_openai\\provider',
            name: 'My OpenAI',
            enabled: true,
            config: ['apikey' => 'sk-test1234567890abcdef'],
            actionconfig: [self::SUMMARISE => $settings($summarise), self::GENERATE => $settings($generate)],
        );
    }

    /**
     * Without the Wunderbyte provider the wizard uses Summarise text and Generate text, and only the action with an
     * instruction is reported.
     */
    public function test_the_routed_actions_of_a_core_provider_are_checked(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->openai_instance('Your task is to summarize the provided text.', '');
        $contextid = (int)\context_system::instance()->id;
        $registry = skill_registry_factory::get_default();
        $orchestrator = new orchestrator($registry, new interpreter($registry), new conversation_store());

        $routed = $orchestrator->get_routed_action_classes($contextid);
        if (in_array('aiprovider_wunderbyte\\aiactions\\planner_decide', $routed, true)) {
            $this->markTestSkipped('a Wunderbyte provider instance is active in this environment');
        }
        $this->assertSame(self::SUMMARISE, $routed['selection']);
        $this->assertSame(self::SUMMARISE, $routed['construction']);
        $this->assertSame(self::GENERATE, $routed['reply']);

        $conflicts = provider_instruction_check::find_conflicts(array_values($routed));
        $this->assertSame([self::SUMMARISE], array_column($conflicts, 'action'));
        $this->assertSame(['My OpenAI'], array_column($conflicts, 'provider'));
    }

    /**
     * The wizard panel shows the admin which action to fix, with the link; other users get a short hint only.
     */
    public function test_the_panel_names_the_setting_to_the_admin_and_hints_to_others(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->openai_instance('Your task is to summarize the provided text.', '');
        set_config('agent_enabled', 1, 'bookingextension_agent');
        $contextid = (int)\context_system::instance()->id;

        $admin = (new aiready($contextid, (int)get_admin()->id))->export_for_template();
        if (empty($admin['provider_available'])) {
            $this->markTestSkipped('no active provider in this environment');
        }
        $this->assertCount(1, $admin['instruction_warnings']);
        $this->assertStringContainsString('My OpenAI', $admin['instruction_warnings'][0]['text']);
        $this->assertStringContainsString('/ai/configure_actions.php', $admin['instruction_warnings'][0]['url']);

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $other = (new aiready($contextid, (int)$manager->id))->export_for_template();
        $this->assertSame(
            [['text' => get_string('aiready_instruction_warning_user', 'bookingextension_agent'), 'url' => '']],
            $other['instruction_warnings']
        );
    }

    /**
     * No warning in the panel when the routed actions carry no instruction.
     */
    public function test_the_panel_shows_no_warning_without_an_instruction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->openai_instance('', '');
        set_config('agent_enabled', 1, 'bookingextension_agent');

        $data = (new aiready((int)\context_system::instance()->id, (int)get_admin()->id))->export_for_template();
        if (empty($data['provider_available'])) {
            $this->markTestSkipped('no active provider in this environment');
        }
        $this->assertSame([], $data['instruction_warnings']);
    }
}
