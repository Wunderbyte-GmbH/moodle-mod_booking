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

namespace local_wizard;

use advanced_testcase;
use local_wizard\local\wizard\services\agent_access_service;

/**
 * The standalone Wizard is a free plugin: no license key, no license check, no license text.
 *
 * Full access (the write skills of the PRO-gated components) comes only from the Wunderbyte LLM
 * gateway here; the PRO license of the engine bundled with mod_booking does not exist in this plugin
 * (Wunderbyte-GmbH/moodle-local_wizard#2).
 *
 * This file is an OVERLAY maintained in the source plugin under tools/wizard_sync/overlays/; it exists only in the
 * generated local_wizard artifact. The bundled engine keeps its license.
 *
 * @package    local_wizard
 * @covers     \local_wizard\local\wizard\services\agent_access_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_no_license_test extends advanced_testcase {
    /** @var string[] Strings of the bundled engine's license key setting; none may exist here. */
    private const LICENSE_STRINGS = [
        'licenseactivated',
        'licenseactivatedviabooking',
        'licenseexpired',
        'licenseinvalid',
        'licensekey',
        'licensekeydesc',
        'licensenotrequiredviaendpoint',
    ];

    /**
     * The license class of the bundled engine is not shipped, so nothing can check a license.
     */
    public function test_no_license_class(): void {
        $this->assertFalse(class_exists('\local_wizard\local\wb_license'));
        $this->assertFileDoesNotExist(\core_component::get_component_directory('local_wizard') . '/classes/local/wb_license.php');
    }

    /**
     * The settings page has no license key field.
     */
    public function test_settings_page_has_no_license_key(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = admin_get_root(true, true)->locate('local_wizard_aisettings');
        $this->assertNotNull($page, 'The Wizard settings page exists.');
        // The settings of an admin page are an object keyed by the setting name.
        $names = array_map(static fn($setting) => $setting->name, array_values((array)$page->settings));
        $this->assertNotEmpty($names, 'The Wizard settings page lists its settings.');
        $this->assertNotContains('licensekey', $names);
        $this->assertFalse(get_config('local_wizard', 'licensekey'));
    }

    /**
     * No license string exists, and no shipped string speaks of a license.
     */
    public function test_no_license_text(): void {
        foreach (self::LICENSE_STRINGS as $key) {
            $this->assertFalse(get_string_manager()->string_exists($key, 'local_wizard'), $key);
        }

        $dir = \core_component::get_component_directory('local_wizard');
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($dir . "/lang/{$lang}/local_wizard.php");
            foreach ($string as $key => $text) {
                $this->assertDoesNotMatchRegularExpression('/licen[cs]e|lizenz/i', $text, "lang/{$lang}: {$key}");
            }
        }
    }

    /**
     * Test runs keep full access without the license class, so every skill stays testable.
     */
    public function test_test_run_has_full_access(): void {
        $this->assertTrue(agent_access_service::has_full_access());
    }
}
