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
 * Hides the agent settings while "Enable agent" is off, on cores without MDL-76975
 * (Moodle 4.5, 5.0.0-5.0.4, 5.1.0-5.1.1).
 *
 * There core/showhidesettings init() takes the rules themselves. Passed through js_call_amd they
 * are longer than its 1024 character limit, so the PHP setting
 * bookingextension_agent\admin\setting_hide_when_disabled writes them into a hidden element and
 * passes only the element id.
 *
 * @module     bookingextension_agent/hide_when_disabled
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ShowHide from 'core/showhidesettings';

/**
 * Start core's show/hide script with the rules stored on the element.
 *
 * @param {string} id Id of the hidden element whose data-dependencies attribute holds the rules.
 */
export const init = (id) => {
    const element = document.getElementById(id);
    if (element) {
        ShowHide.init(JSON.parse(element.dataset.dependencies));
    }
};
