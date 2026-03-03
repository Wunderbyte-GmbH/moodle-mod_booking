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

/*
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import ModalForm from 'core_form/modalform';

export const init = (selector) => {
    const element = document.querySelector(selector);
    if (!element) {
        return;
    }

    element.addEventListener('click', e => {
        const trigger = e.target.closest('[data-action][data-id][data-certificate="1"]');
        if (!trigger) {
            return;
        }
        editCertificateModal(trigger);
    });
};

/**
 * Show certificate condition modal.
 * @param {HTMLElement} element
 */
function editCertificateModal(element) {
    const conditionid = element.dataset.id;
    const name = element.dataset.name;
    const action = element.dataset.action;
    const contextid = element.dataset.contextid;

    if (!conditionid) {
        return;
    }

    if (action === 'delete') {
        const deleteForm = new ModalForm({
            formClass: 'mod_booking\\form\\deletecertificateconditionform',
            args: {id: conditionid, name: name, contextid: contextid},
            modalConfig: {title: getString('deletecertificatecondition', 'mod_booking')},
            returnFocus: element
        });

        deleteForm.addEventListener(deleteForm.events.FORM_SUBMITTED, () => {
            window.location.reload();
        });

        deleteForm.show();
        return;
    }

    if (action === 'edit-or-new') {
        const modalForm = new ModalForm({
            formClass: 'mod_booking\\form\\certificateconditionsform',
            args: {
                id: conditionid,
                contextid: contextid
            },
            modalConfig: {title: getString('editcertificatecondition', 'mod_booking')},
            returnFocus: element
        });

        modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {
            window.location.reload();
        });

        modalForm.addEventListener('change', (e) => {
            if (!e.target.name) {
                return;
            }

            if (e.target.name === 'certificatefiltertype') {
                window.skipClientValidation = true;
                const button = document.querySelector('[name="btn_certificatefiltertype"]');
                modalForm.processNoSubmitButton(button);
            }
            if (e.target.name === 'certificatelogictype') {
                window.skipClientValidation = true;
                const button = document.querySelector('[name="btn_certificatelogictype"]');
                modalForm.processNoSubmitButton(button);
            }
            if (e.target.name === 'certificateactiontype') {
                window.skipClientValidation = true;
                const button = document.querySelector('[name="btn_certificateactiontype"]');
                modalForm.processNoSubmitButton(button);
            }
            if (e.target.name === 'certificatetemplate') {
                window.skipClientValidation = true;
                const button = document.querySelector('[name="btn_certificatetemplates"]');
                modalForm.processNoSubmitButton(button);
            }
        });

        modalForm.show();
    }
}
