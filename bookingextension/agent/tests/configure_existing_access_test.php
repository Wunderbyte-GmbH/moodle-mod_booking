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

use bookingextension_agent\local\wizard\aiready;
use bookingextension_agent\local\wizard\services\trial\trial_provisioner;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Configuring the Wunderbyte provider when the site already has Wunderbyte access (#2569).
 *
 * demo2.wunderbyte.at (Moodle 5.2, 2026-10-06): a trial ran on the core OpenAI provider, then aiprovider_wunderbyte
 * was installed. The "Configure" dialog offered a second trial (the trial service allows one per site) next to
 * "use credentials from " with an empty name; the clone was called "Wunderbyte (aiprovider_openai)", and the old
 * trial instance stayed active next to it.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\trial\trial_provisioner
 * @covers     \bookingextension_agent\local\wizard\aiready
 */
final class configure_existing_access_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (
            !method_exists(ai_manager::class, 'get_provider_instances')
            || !\core_component::get_plugin_directory('aiprovider', 'wunderbyte')
            || !\core_component::get_plugin_directory('aiprovider', 'openai')
        ) {
            $this->markTestSkipped('needs Moodle 5.x provider instances with aiprovider_wunderbyte and aiprovider_openai');
        }
        $this->setAdminUser();
    }

    /**
     * An enabled core OpenAI instance.
     *
     * @param string $name
     * @param string $endpoint
     * @return \core_ai\provider
     */
    private function openai_instance(string $name, string $endpoint): \core_ai\provider {
        return di::get(ai_manager::class)->create_provider_instance(
            classname: 'aiprovider_openai\\provider',
            name: $name,
            enabled: true,
            config: ['apikey' => 'sk-test1234567890abcdef'],
            actionconfig: [
                'core_ai\\aiactions\\generate_text' => [
                    'enabled' => true,
                    'settings' => ['endpoint' => $endpoint, 'model' => 'wunderbyte-trial', 'systeminstruction' => ''],
                ],
            ],
        );
    }

    /**
     * All provider instances of one provider class.
     *
     * @param string $classname
     * @return array
     */
    private function instances(string $classname): array {
        return array_values(array_filter(
            di::get(ai_manager::class)->get_provider_instances(),
            static fn($i) => get_class($i) === $classname
        ));
    }

    /**
     * Cloning existing Wunderbyte access: the new instance is "Wunderbyte", the old gateway instance is switched off.
     */
    public function test_clone_from_wunderbyte_access_names_the_instance_and_retires_the_source(): void {
        $source = $this->openai_instance('Wunderbyte', 'https://llm.wunderbyte.at/v1/chat/completions');

        $result = (new trial_provisioner())->configure_from_existing_provider(\context_system::instance()->id);
        $this->assertTrue($result['success'], json_encode($result));

        $wb = $this->instances('aiprovider_wunderbyte\\provider');
        $this->assertCount(1, $wb);
        $this->assertSame('Wunderbyte', $wb[0]->name);
        $this->assertNotEmpty($wb[0]->enabled);
        $after = di::get(ai_manager::class)->get_provider_instances(['id' => $source->id]);
        $this->assertEmpty(reset($after)->enabled, 'the old gateway instance must be switched off');
    }

    /**
     * A third-party provider is only the key source: it stays as it is, and the message names it.
     */
    public function test_clone_from_a_third_party_provider_leaves_it_alone(): void {
        $source = $this->openai_instance('My OpenAI', 'https://api.openai.com/v1/chat/completions');

        $result = (new trial_provisioner())->configure_from_existing_provider(\context_system::instance()->id);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(get_string('aitrial_clone_success', 'bookingextension_agent', 'My OpenAI'), $result['message']);
        $after = di::get(ai_manager::class)->get_provider_instances(['id' => $source->id]);
        $this->assertNotEmpty(reset($after)->enabled, 'a third-party provider stays enabled');
    }

    /**
     * With existing Wunderbyte access the configure dialog offers exactly that, never a second trial.
     */
    public function test_existing_wunderbyte_access_offers_configuration_without_a_trial(): void {
        $this->openai_instance('Wunderbyte', 'https://llm.wunderbyte.at/v1/chat/completions');
        set_config('agent_enabled', 1, 'bookingextension_agent');

        $data = (new aiready(\context_system::instance()->id, (int)get_admin()->id))->export_for_template();
        if (empty($data['provider_configure_available'])) {
            $this->markTestSkipped('configure dialog not offered in this environment: ' . json_encode([
                'provider_available' => $data['provider_available'] ?? null,
            ]));
        }
        $this->assertTrue((bool)($data['configure_from_existing_access'] ?? false));
        $this->assertFalse((bool)($data['configure_offer_trial'] ?? true));
        $this->assertSame('Wunderbyte', (string)($data['source_provider_name'] ?? ''));
    }

    /**
     * A third-party provider keeps both choices: take its credentials, or start a trial.
     */
    public function test_third_party_provider_keeps_the_trial_choice(): void {
        $this->openai_instance('My OpenAI', 'https://api.openai.com/v1/chat/completions');
        set_config('agent_enabled', 1, 'bookingextension_agent');

        $data = (new aiready(\context_system::instance()->id, (int)get_admin()->id))->export_for_template();
        if (empty($data['provider_configure_available'])) {
            $this->markTestSkipped('configure dialog not offered in this environment');
        }
        $this->assertFalse((bool)($data['configure_from_existing_access'] ?? true));
        $this->assertTrue((bool)($data['configure_offer_trial'] ?? false));
        $this->assertSame('My OpenAI', (string)($data['source_provider_name'] ?? ''));
    }

    /**
     * Configuring twice updates the one Wunderbyte instance instead of adding a second one.
     */
    public function test_configuring_twice_keeps_one_wunderbyte_instance(): void {
        $this->openai_instance('My OpenAI', 'https://api.openai.com/v1/chat/completions');
        $provisioner = new trial_provisioner();
        $this->assertTrue($provisioner->configure_from_existing_provider(\context_system::instance()->id)['success']);
        $this->assertTrue($provisioner->configure_from_existing_provider(\context_system::instance()->id)['success']);
        $this->assertCount(1, $this->instances('aiprovider_wunderbyte\\provider'));
    }
}
