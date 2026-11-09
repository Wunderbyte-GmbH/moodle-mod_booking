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
 * Admin setting that hides a list of settings while a checkbox is off.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\admin;

use html_writer;

/**
 * Hides a list of settings while a checkbox is off, like admin_settingpage::hide_if() does.
 *
 * Only used on cores without MDL-76975 (Moodle 4.5, 5.0.0-5.0.4, 5.1.0-5.1.1). There core passes all
 * hide_if rules of a page as one js_call_amd argument, which shows a developer warning when it is longer
 * than 1024 characters. This setting writes the same rules into a hidden element instead and passes only
 * the element id; bookingextension_agent/hide_when_disabled reads the rules and starts core's script.
 *
 * It is a heading, so nothing is stored and the "new settings" page skips it. It renders no visible row.
 */
class setting_hide_when_disabled extends \admin_setting_heading {
    /** @var string The checkbox the hidden settings depend on, in plugin/name format. */
    protected string $switchsetting;

    /** @var string[] The settings to hide while $switchsetting is not checked, in plugin/name format. */
    protected array $hiddensettings;

    /**
     * Constructor.
     *
     * @param string $name Unique name of this setting.
     * @param string $dependenton The checkbox the settings depend on, in plugin/name format.
     * @param string[] $hiddensettings The settings to hide while the checkbox is off, in plugin/name format.
     */
    public function __construct(string $name, string $dependenton, array $hiddensettings) {
        $this->switchsetting = $dependenton;
        $this->hiddensettings = array_values($hiddensettings);
        parent::__construct($name, '', '');
    }

    /**
     * Whether core still passes the hide_if rules of a page as a js_call_amd argument.
     *
     * MDL-76975 (5.0.5, 5.1.2, 5.2) moved the rules into a data attribute, rendered by the template
     * core_admin/settings_showhide, and changed core/showhidesettings init() to take an element id.
     *
     * @return bool True on cores without that change.
     */
    public static function core_passes_rules_as_js_argument(): bool {
        $admindir = \core_component::get_component_directory('core_admin');
        return !file_exists($admindir . '/templates/settings_showhide.mustache');
    }

    /**
     * The settings this setting hides.
     *
     * @return string[] Names in plugin/name format.
     */
    public function get_hidden_settings(): array {
        return $this->hiddensettings;
    }

    /**
     * The rules in the format of admin_settingpage::get_dependencies_for_javascript().
     *
     * @return array
     */
    public function get_dependencies_for_javascript(): array {
        $dependencies = [];
        foreach ($this->hiddensettings as $settingname) {
            $dependencies[] = new \admin_settingdependency($settingname, $this->switchsetting, 'notchecked', '1');
        }
        return \admin_settingdependency::prepare_for_javascript($dependencies);
    }

    /**
     * Render a hidden element with the rules and start the script with its id.
     *
     * @param mixed $data Unused.
     * @param string $query Unused.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $id = html_writer::random_id('bookingextension_agent_hidewhendisabled');
        $PAGE->requires->js_call_amd('bookingextension_agent/hide_when_disabled', 'init', [$id]);
        return html_writer::div('', 'd-none', [
            'id' => $id,
            'data-dependencies' => json_encode(['dependencies' => $this->get_dependencies_for_javascript()]),
        ]);
    }

    /**
     * Never listed in the admin search.
     *
     * @param string $query
     * @return bool
     */
    public function is_related($query) {
        return false;
    }
}
