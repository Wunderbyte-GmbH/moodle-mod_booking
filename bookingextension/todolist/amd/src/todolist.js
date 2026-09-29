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
 * Todo list interactions for booking extension.
 *
 * @module     bookingextension_todolist/todolist
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';

const ROOT_SELECTOR = '[data-region="bookingextension-todolist"]';
const CHECKBOX_SELECTOR = '[data-action="toggle-todolist-item"]';
let initialized = false;

const handleToggle = async(checkbox) => {
    const root = checkbox.closest(ROOT_SELECTOR);
    if (!root) {
        return;
    }

    if (root.dataset.cancheck !== '1') {
        return;
    }

    const itemid = parseInt(checkbox.dataset.itemid, 10);
    const optionid = parseInt(checkbox.dataset.optionid || root.dataset.optionid, 10);
    const checked = !!checkbox.checked;

    if (!Number.isInteger(itemid) || itemid <= 0 || !Number.isInteger(optionid) || optionid <= 0) {
        checkbox.checked = !checked;
        Notification.exception(new Error('Invalid todo list item or option identifier.'));
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'bookingextension_todolist_toggle_todolist_item',
            args: {
                itemid: itemid,
                optionid: optionid,
                checked: checked,
            },
        }])[0];

        const rendered = await Templates.renderForPromise('bookingextension_todolist/todolist', response.context);
        Templates.replaceNode(root, rendered.html, rendered.js);

        if (response.notification && response.notificationtype) {
            Notification.addNotification({
                message: response.notification,
                type: response.notificationtype,
            });
        }
    } catch (error) {
        checkbox.checked = !checked;
        Notification.exception(error);
    }
};

/**
 * Initialize listeners for todo list interactions.
 */
export const init = () => {
    if (initialized) {
        return;
    }
    initialized = true;

    document.addEventListener('change', (event) => {
        const checkbox = event.target.closest(CHECKBOX_SELECTOR);
        if (!checkbox) {
            return;
        }
        handleToggle(checkbox);
    });
};
