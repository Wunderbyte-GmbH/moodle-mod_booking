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
 * @author     Bernhard Fischer
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic holidays form.
 *
 * @module     mod_booking/dynamicholidaysform
 * @copyright  2022 Wunderbyte GmbH
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';

export const init = (selector, formClass) => {

    const formelement = document.querySelector(selector);
    const jsonstring = window.atob(formelement.dataset.data);
    const existingholidays = JSON.parse(jsonstring);

    const form = new DynamicForm(formelement, formClass);

    // We need this in order to fix a bug with repeat elements.
    // Clicking on labels does not work correctly, so we remove the "for" attribut if one is clicked.
    document.getElementById("holidaysform").addEventListener("click", function(e) {
        if (e.target && e.target.matches(".form-check label")) {
            e.target.removeAttribute("for");
        }
    });

    form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;
        form.load({...existingholidays, response});
    });

    // Cancel button does not make much sense in such forms but since it's there we'll just reload.
    form.addEventListener(form.events.FORM_CANCELLED, (e) => {
        e.preventDefault();
        // eslint-disable-next-line promise/catch-or-return
        form.notifyResetFormChanges()
            .then(() => form.load(existingholidays));
    });
};
