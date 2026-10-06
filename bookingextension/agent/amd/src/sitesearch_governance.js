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
 * Site-search governance: launches the add-scope-rule modal (core_form/modalform).
 *
 * @module     bookingextension_agent/sitesearch_governance
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';

export const init = () => {
    document.querySelectorAll('[data-bxagent-addscoperule]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const modalForm = new ModalForm({
                formClass: 'bookingextension_agent\\form\\sitesearch_scope_rule_form',
                args: {
                    area: button.dataset.area,
                    scopetype: button.dataset.scopetype,
                },
                modalConfig: {title: button.dataset.title || ''},
                returnFocus: button,
            });
            modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {
                // The saved rule (incl. its freshly cached estimate) renders on reload.
                window.location.reload();
            });
            modalForm.show();
        });
    });
};
