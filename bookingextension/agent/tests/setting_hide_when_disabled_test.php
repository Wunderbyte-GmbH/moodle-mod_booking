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
 * Tests for the setting that hides the agent settings while the agent is off on older cores.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\admin\setting_hide_when_disabled;

/**
 * The setting carries the same rules as core's hide_if, but passes only an element id to JavaScript.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\admin\setting_hide_when_disabled
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class setting_hide_when_disabled_test extends advanced_testcase {
    /** @var string The checkbox the hidden settings depend on. */
    private const SWITCH = 'bookingextension_agent/agent_enabled';

    /**
     * Load the admin library for the core setting classes.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * Long setting names, so that the rule list is longer than js_call_amd's 1024 character limit.
     *
     * @return string[]
     */
    private static function long_names(): array {
        $names = [];
        for ($i = 1; $i <= 25; $i++) {
            $names[] = 'bookingextension_agent/aiinitialprompt_parameter_construction_' . $i;
        }
        return $names;
    }

    /**
     * The rules have exactly the format core builds for hide_if($name, $switch).
     */
    public function test_dependencies_match_core_hide_if(): void {
        $names = self::long_names();
        $page = new \admin_settingpage('bookingextension_agent_testpage', 'Test');
        $page->add(new \admin_setting_configcheckbox(self::SWITCH, 'Switch', '', 0));
        foreach ($names as $name) {
            $page->add(new \admin_setting_configcheckbox($name, $name, '', 0));
            $page->hide_if($name, self::SWITCH);
        }

        $setting = new setting_hide_when_disabled('bookingextension_agent_hidewhendisabled', self::SWITCH, $names);

        $this->assertSame($names, $setting->get_hidden_settings());
        $this->assertSame($page->get_dependencies_for_javascript(), $setting->get_dependencies_for_javascript());
    }

    /**
     * The output is one hidden, empty element that carries the rules; JavaScript only gets its id,
     * so the 1024 character warning of js_call_amd is not triggered even for a long list.
     */
    public function test_output_is_hidden_element_and_js_gets_only_its_id(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/admin/settings.php', ['section' => 'bookingextension_agent_aisettings']);

        $setting = new setting_hide_when_disabled('bookingextension_agent_hidewhendisabled', self::SWITCH, self::long_names());
        $rules = ['dependencies' => $setting->get_dependencies_for_javascript()];
        $this->assertGreaterThan(1024, strlen(json_encode($rules)));

        $html = $setting->output_html($setting->get_setting());
        $this->assertDebuggingNotCalled();

        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);
        $body = $doc->getElementsByTagName('body')->item(0);
        $this->assertSame(1, $body->childNodes->length);
        $element = $body->firstChild;
        $this->assertSame('div', $element->nodeName);
        $this->assertSame('d-none', $element->getAttribute('class'));
        $this->assertSame('', $element->textContent);
        $this->assertSame($rules, json_decode($element->getAttribute('data-dependencies'), true));

        $id = $element->getAttribute('id');
        $this->assertNotSame('', $id);
        $endcode = $PAGE->requires->get_end_code();
        $this->assertStringContainsString('bookingextension_agent/hide_when_disabled', $endcode);
        $this->assertStringContainsString(json_encode($id), $endcode);
        $this->assertStringNotContainsString('aiinitialprompt_parameter_construction', $endcode);
    }

    /**
     * It is a heading: nothing is stored, it is never a "new setting" and admin search never lists it.
     */
    public function test_stores_nothing_and_is_not_searchable(): void {
        $setting = new setting_hide_when_disabled('bookingextension_agent_hidewhendisabled', self::SWITCH, self::long_names());

        $this->assertInstanceOf(\admin_setting_heading::class, $setting);
        $this->assertTrue($setting->nosave);
        $this->assertNotNull($setting->get_setting());
        $this->assertSame('', $setting->write_setting('1'));
        $this->assertFalse($setting->is_related('hidewhendisabled'));
        $this->assertFalse($setting->is_related('aiinitialprompt'));
    }
}
