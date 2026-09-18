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
 * Inline editing of a booking note.
 *
 * Included from the template mod_booking/edit_bookingnotes. A click (or Enter) on a note replaces it
 * with a textarea; Enter saves through the web service mod_booking_update_bookingnotes, Escape or
 * leaving the field cancels. Any exception thrown by the web service is displayed as an error popup.
 *
 * @module     mod_booking/edit_note
 * @copyright  2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      3.3
 */
import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Config from 'core/config';
import Url from 'core/url';

const SELECTOR = '[data-inplaceeditable]';
const EDITINGCLASS = 'inplaceeditingon';

/**
 * An element id that is not used on the page yet.
 *
 * @param {String} prefix
 * @param {Number} idlength
 * @return {String}
 */
const uniqueId = (prefix, idlength) => {
    let uniqid = prefix;
    for (let i = 0; i < idlength; i++) {
        uniqid += String(Math.floor(Math.random() * 10));
    }
    return document.getElementById(uniqid) === null ? uniqid : uniqueId(prefix, idlength);
};

const addSpinner = (element) => {
    element.classList.add('updating');
    let spinner = element.querySelector('img.spinner');
    if (spinner) {
        spinner.hidden = false;
        return;
    }
    spinner = document.createElement('img');
    spinner.src = Url.imageUrl('i/loading_small');
    spinner.alt = '';
    spinner.classList.add('spinner', 'smallicon');
    element.appendChild(spinner);
};

const removeSpinner = (element) => {
    element.classList.remove('updating');
    const spinner = element.querySelector('img.spinner');
    if (spinner) {
        spinner.hidden = true;
    }
};

/**
 * Save the note and replace the element with the freshly rendered one.
 *
 * @param {HTMLElement} mainelement
 * @param {String} value
 */
const updateValue = async(mainelement, value) => {
    const baid = mainelement.getAttribute('data-baid');
    const pendingId = [baid, 'pending'].join('-');
    const oldvalue = mainelement.getAttribute('data-value');
    M.util.js_pending(pendingId);
    addSpinner(mainelement);

    try {
        // Note: core/ajax returns a jQuery promise; await turns it into a native one.
        const data = await Ajax.call([{
            methodname: 'mod_booking_update_bookingnotes',
            args: {baid: baid, note: value},
        }])[0];
        const {html, js} = await Templates.renderForPromise('mod_booking/edit_bookingnotes', data);
        const newnodes = Templates.replaceNode(mainelement, html, js);
        const newelement = newnodes.find((node) => node.nodeType === Node.ELEMENT_NODE);
        if (newelement) {
            const note = newelement.querySelector('[data-note]');
            if (note) {
                note.focus();
            }
            newelement.dispatchEvent(new CustomEvent('updated', {
                bubbles: true,
                detail: {ajaxreturn: data, oldvalue: oldvalue},
            }));
        }
    } catch (exception) {
        removeSpinner(mainelement);
        const event = new CustomEvent('updatefailed', {
            bubbles: true,
            cancelable: true,
            detail: {exception: exception, newvalue: value},
        });
        // A listener may handle the failure itself by preventing the default.
        if (mainelement.dispatchEvent(event)) {
            Notification.exception(exception);
        }
    } finally {
        M.util.js_complete(pendingId);
    }
};

const turnEditingOff = (el) => {
    el.innerHTML = el.getAttribute('data-oldcontent');
    el.removeAttribute('data-oldcontent');
    el.classList.remove(EDITINGCLASS);
    const note = el.querySelector('[data-note]');
    if (note) {
        note.focus();
    }
};

const turnEditingOn = async(el) => {
    el.classList.add(EDITINGCLASS);
    el.setAttribute('data-oldcontent', el.innerHTML);
    const currentnote = el.querySelector('.bookingnote');
    const notetext = currentnote ? currentnote.textContent : '';

    const instructionstext = await getString('edittitleinstructions');

    const instructions = document.createElement('span');
    instructions.className = 'editinstructions';
    instructions.id = uniqueId('id_editinstructions_', 20);
    instructions.textContent = instructionstext;

    const input = document.createElement('textarea');
    input.rows = 4;
    input.cols = 50;
    input.id = uniqueId('id_inplacevalue_', 20);
    input.value = notetext;
    input.setAttribute('aria-describedby', instructions.id);
    input.classList.add('ignoredirty', 'form-control');

    const label = document.createElement('label');
    label.className = 'accesshide';
    label.textContent = el.getAttribute('data-editlabel') || '';
    label.setAttribute('for', input.id);

    el.innerHTML = '';
    el.append(instructions, label, input);
    input.focus();
    input.select();

    let finished = false;
    const handler = (e) => {
        if (finished) {
            return;
        }
        if (Config.behatsiterunning && e.type === 'focusout') {
            // Behat triggers focusout too often.
            return;
        }
        if (e.type === 'keypress' && e.key === 'Enter') {
            // We need 'keypress' for Enter because keyup/keydown would catch Enter that was
            // pressed in other fields.
            finished = true;
            const value = input.value;
            turnEditingOff(el);
            updateValue(el, value);
            return;
        }
        if ((e.type === 'keyup' && e.key === 'Escape') || e.type === 'focusout') {
            // We need 'keyup' for Escape because keypress does not work with Escape.
            finished = true;
            turnEditingOff(el);
        }
    };
    ['keyup', 'keypress', 'focusout'].forEach((type) => input.addEventListener(type, handler));
};

const start = (e) => {
    if (e.type === 'keypress' && e.key !== 'Enter') {
        return;
    }
    const mainelement = e.target.closest ? e.target.closest(SELECTOR) : null;
    // While editing, clicks and key presses belong to the textarea.
    if (!mainelement || mainelement.classList.contains(EDITINGCLASS)) {
        return;
    }
    e.stopImmediatePropagation();
    e.preventDefault();
    turnEditingOn(mainelement).catch(Notification.exception);
};

// The template requires this module once per rendered note: bind the delegated listeners only once.
if (!document.body.dataset.modBookingEditNote) {
    document.body.dataset.modBookingEditNote = '1';
    document.body.addEventListener('click', start);
    document.body.addEventListener('keypress', start);
}
