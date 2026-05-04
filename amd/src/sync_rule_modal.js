// This file is part of Moodle - https://moodle.org/
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
 * Sync rule modal launcher.
 *
 * @module      mod_booking/sync_rule_modal
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core_form/modalform'], function(ModalForm) {
    return {
        /**
         * Initialize add-rule modal button.
         *
         * @param {String} selector CSS selector.
         * @param {Number} cmid Course module id.
         * @param {Number} optionid Booking option id.
         */
        init: function(selector, cmid, optionid, title) {
            const element = document.querySelector(selector);
            if (!element) {
                return;
            }

            element.addEventListener('click', function(e) {
                e.preventDefault();

                const modalForm = new ModalForm({
                    formClass: 'mod_booking\\form\\sync_rule_form',
                    args: {
                        cmid: cmid,
                        optionid: optionid,
                    },
                    modalConfig: {
                        title: title || 'Add sync rule',
                    },
                    returnFocus: element,
                });

                modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, function() {
                    window.location.reload();
                });

                modalForm.show();
            });
        }
    };
});
