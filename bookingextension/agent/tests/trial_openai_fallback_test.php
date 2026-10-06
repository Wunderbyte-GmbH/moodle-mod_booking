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

use bookingextension_agent\local\wizard\services\provider_compat;
use bookingextension_agent\local\wizard\services\trial\trial_provisioner;

/**
 * Trial on the core OpenAI provider (no Wunderbyte provider installed).
 *
 * demo4.wunderbyte.at (Moodle 4.5, local_wizard, 2026-10-06): the trial key was minted and worked against the gateway,
 * but every agent turn failed with "authentication failed". Without the Wunderbyte provider the agent routes selection
 * and construction to summarise_text; the trial configured only generate_text, so summarise_text still sent the
 * Wunderbyte key to api.openai.com (401). On 4.5 the empty system instruction was also skipped, so Moodle's default
 * "summarise / generate" instruction stayed in front of every agent prompt.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\trial\trial_provisioner
 * @covers     \bookingextension_agent\local\wizard\services\provider_compat
 */
final class trial_openai_fallback_test extends \advanced_testcase {
    /**
     * Every core_ai action the agent routes to on the OpenAI provider points at the trial gateway.
     */
    public function test_openai_trial_routes_every_agent_action_to_the_gateway(): void {
        if (!class_exists('\\core_ai\\aiactions\\summarise_text')) {
            $this->markTestSkipped('core_ai not available');
        }
        $build = new \ReflectionMethod(trial_provisioner::class, 'build_actionconfig');
        $config = $build->invoke(new trial_provisioner(), 'openai', 'https://llm.wunderbyte.at');

        foreach ([\core_ai\aiactions\generate_text::class, \core_ai\aiactions\summarise_text::class] as $action) {
            $this->assertArrayHasKey($action, $config, "$action must be configured for the trial");
            $settings = (array)($config[$action]['settings'] ?? []);
            $this->assertTrue((bool)($config[$action]['enabled'] ?? false), $action);
            $this->assertSame('https://llm.wunderbyte.at/v1/chat/completions', $settings['endpoint'] ?? null, $action);
            $this->assertSame('', $settings['systeminstruction'] ?? null, "$action carries no core instruction");
            $this->assertNotSame('', (string)($settings['model'] ?? ''), $action);
        }
    }

    /**
     * On Moodle 4.5 (flat provider config) an empty system instruction replaces the core default.
     */
    public function test_legacy_config_writes_an_empty_system_instruction(): void {
        $this->resetAfterTest();
        if (!\core_component::get_plugin_directory('aiprovider', 'openai')) {
            $this->markTestSkipped('aiprovider_openai not installed');
        }
        set_config('action_summarise_text_systeminstruction', 'core default', 'aiprovider_openai');
        set_config('action_summarise_text_endpoint', 'https://api.openai.com/v1/chat/completions', 'aiprovider_openai');

        $legacy = new \ReflectionMethod(provider_compat::class, 'configure_legacy_provider');
        $legacy->invoke(null, 'aiprovider_openai\\provider', ['apikey' => 'sk-test'], [
            \core_ai\aiactions\summarise_text::class => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => 'https://llm.wunderbyte.at/v1/chat/completions',
                    'model' => 'wunderbyte-trial',
                    'systeminstruction' => '',
                ],
            ],
        ]);

        $this->assertSame('', (string)get_config('aiprovider_openai', 'action_summarise_text_systeminstruction'));
        $this->assertSame(
            'https://llm.wunderbyte.at/v1/chat/completions',
            get_config('aiprovider_openai', 'action_summarise_text_endpoint')
        );
        $this->assertSame('wunderbyte-trial', get_config('aiprovider_openai', 'action_summarise_text_model'));
    }
}
