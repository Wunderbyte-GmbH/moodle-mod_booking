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

define(['core_form/modalform', 'core/notification'], function(ModalForm, Notification) {
    return {
        /**
         * Initialize add-rule modal button.
         *
         * @param {String} selector CSS selector.
         * @param {Number} cmid Course module id.
         * @param {Number} optionid Booking option id.
         */
        init: function(addSelector, actionSelector, cmid, optionid, addTitle, editTitle, deleteTitle) {
            const showModal = function(formClass, args, title, focusElement) {
                const modalForm = new ModalForm({
                    formClass: formClass,
                    args: args,
                    modalConfig: {title: title || ''},
                    returnFocus: focusElement || document.body,
                });

                modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, function(e) {
                    const detail = e && e.detail ? e.detail : {};
                    if (detail.feedbackmessage) {
                        Notification.addNotification({
                            message: detail.feedbackmessage,
                            type: 'success',
                        });
                    }
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 500);
                });

                modalForm.show();
            };

            const addButton = document.querySelector(addSelector);
            if (addButton) {
                addButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    showModal(
                        'mod_booking\\form\\sync_rule_form',
                        {cmid: cmid, optionid: optionid, ruleid: 0},
                        addTitle || 'Add sync rule',
                        addButton
                    );
                });
            }

            const actionButtons = document.querySelectorAll(actionSelector);
            actionButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();

                    const action = button.dataset.action;
                    const ruleid = parseInt(button.dataset.ruleid || '0', 10);
                    if (!ruleid) {
                        return;
                    }

                    if (action === 'delete') {
                        showModal(
                            'mod_booking\\form\\sync_rule_delete_form',
                            {cmid: cmid, optionid: optionid, ruleid: ruleid},
                            deleteTitle || 'Delete sync rule',
                            button
                        );
                        return;
                    }

                    showModal(
                        'mod_booking\\form\\sync_rule_form',
                        {cmid: cmid, optionid: optionid, ruleid: ruleid},
                        editTitle || 'Edit sync rule',
                        button
                    );
                });
            });
        }
    };
});
