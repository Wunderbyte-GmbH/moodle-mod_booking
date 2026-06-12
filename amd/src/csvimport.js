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
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {showNotification} from 'mod_booking/notifications';
import {get_string as getString} from 'core/str';
import Templates from 'core/templates';

const SELECTORS = {
    FORMCONTAINER: '#mbo_csv_import_form',
    PREVIEWCONTAINER: '#mbo_csv_import_preview',
    PREVIEWACTIONS: '#mbo_csv_preview_actions',
    PREVIEWMODE: '[name="previewmode"]',
    PREVIEWBUTTON: '[name="previewbutton"]',
    SUBMITBUTTON: '[name="submitbutton"]',
};

const DEFAULTLABELS = {
    back: 'Back',
    upload: 'Upload to database',
    rowsperpage: 'Rows per page:',
};

/**
 * Get translated labels for preview actions.
 *
 * @returns {Promise<object>}
 */
const getPreviewActionLabels = async() => {
    try {
        const [back, upload, rowsperpage] = await Promise.all([
            getString('back', 'moodle'),
            getString('importuploaddatabase', 'mod_booking'),
            getString('importrowsperpage', 'mod_booking'),
        ]);
        return {back, upload, rowsperpage};
    } catch (err) {
        return DEFAULTLABELS;
    }
};

/**
 * Render post-preview actions so users can either go back or submit directly.
 *
 * @param {object} options
 * @param {HTMLElement} options.formContainer
 * @param {HTMLElement} options.previewContainer
 * @param {object} options.labels
 * @param {Function} options.onUpload
 * @param {Function} options.onBack
 */
const renderPreviewActions = ({formContainer, previewContainer, labels, onUpload, onBack}) => {
    const currentActions = previewContainer.querySelector(SELECTORS.PREVIEWACTIONS);
    if (currentActions) {
        currentActions.remove();
    }

    const submitButton = formContainer.querySelector(SELECTORS.SUBMITBUTTON);

    if (!submitButton) {
        return null;
    }

    const actions = document.createElement('div');
    actions.className = 'd-flex gap-2 mt-3 mb-4';
    actions.id = 'mbo_csv_preview_actions';

    const backButton = document.createElement('button');
    backButton.type = 'button';
    backButton.className = 'btn btn-secondary';
    backButton.textContent = labels.back;
    backButton.addEventListener('click', () => {
        onBack();
    });

    const confirmSubmitButton = document.createElement('button');
    confirmSubmitButton.type = 'button';
    confirmSubmitButton.className = 'btn btn-primary';
    confirmSubmitButton.textContent = labels.upload;
    confirmSubmitButton.addEventListener('click', () => {
        onUpload();
    });

    actions.appendChild(backButton);
    actions.appendChild(confirmSubmitButton);
    previewContainer.appendChild(actions);

    return {
        backButton,
        confirmSubmitButton,
    };
};

/**
 * Add a "Rows per page" selector (10 / 100 / All) above each preview table.
 * Hides rows that exceed the chosen limit.  Defaults to showing the first 10.
 *
 * @param {HTMLElement} container - The element that contains the rendered preview.
 * @param {string} rowsperpageLabel - Translated label for the selector.
 */
const setupTablePagination = (container, rowsperpageLabel = DEFAULTLABELS.rowsperpage) => {
    container.querySelectorAll('table').forEach(table => {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        if (rows.length === 0) {
            return;
        }
        const tableWrapper = table.closest('.table-responsive') || table;

        const applyLimit = (limit) => {
            rows.forEach((row, i) => {
                row.classList.toggle('d-none', limit > 0 && i >= limit);
            });
        };

        const uid = `mbo-rpp-${Math.random().toString(36).slice(2)}`;
        const controls = document.createElement('div');
        controls.className = 'd-flex align-items-center gap-2 mb-2';

        const label = document.createElement('label');
        label.className = 'mb-0 small';
        label.textContent = rowsperpageLabel;
        label.htmlFor = uid;

        const select = document.createElement('select');
        select.id = uid;
        select.className = 'form-select form-select-sm w-auto';
        [['10', '10'], ['100', '100'], ['0', 'All']].forEach(([val, text]) => {
            const option = document.createElement('option');
            option.value = val;
            option.textContent = text;
            select.appendChild(option);
        });

        select.addEventListener('change', () => applyLimit(parseInt(select.value, 10)));
        applyLimit(10); // Default: show first 10 rows.

        controls.appendChild(label);
        controls.appendChild(select);
        tableWrapper.parentElement.insertBefore(controls, tableWrapper);
    });
};

/**
 * Build a template context object from a preview API response.
 *
 * @param {object} response
 * @returns {object}
 */
const buildPreviewContext = (response) => {
    const columns = (response.columns || []).map(col => ({name: col}));
    const validrows = (response.validrows || []).map(row => ({
        linenumber: row.linenumber,
        cells: (response.columns || []).map(col => ({value: row.data && row.data[col] !== undefined ? row.data[col] : ''}))
    }));
    const skippedrows = (response.skippedrows || []).map(row => ({
        linenumber: row.linenumber,
        cells: (response.columns || []).map(col => ({
            value: row.data && row.data[col] !== undefined ? row.data[col] : ''
        })),
        reason: row.reason || ''
    }));
    return {
        columns,
        validcount: validrows.length,
        skippedcount: skippedrows.length,
        validrows,
        skippedrows,
        hasvalidrows: validrows.length > 0,
        hasskippedrows: skippedrows.length > 0,
    };
};

/**
 * Add event listener to form.
 */
export const init = () => {

    const formContainer = document.querySelector(SELECTORS.FORMCONTAINER);

    // Create a preview container after the form container if it does not already exist.
    let previewContainer = document.querySelector(SELECTORS.PREVIEWCONTAINER);
    if (!previewContainer) {
        previewContainer = document.createElement('div');
        previewContainer.id = 'mbo_csv_import_preview';
        formContainer.insertAdjacentElement('afterend', previewContainer);
    }

    // Initialize the form - pass the container element and the form class name.
    const dynamicForm = new DynamicForm(formContainer,
        'mod_booking\\form\\csvimport'
    );

    const state = {
        uploadInProgress: false,
        uploadActionButton: null,
        backActionButton: null,
    };

    const setFormVisibility = (visible) => {
        formContainer.classList.toggle('d-none', !visible);
    };

    const resetUploadActionState = () => {
        state.uploadInProgress = false;
        if (state.uploadActionButton) {
            state.uploadActionButton.disabled = false;
        }
        if (state.backActionButton) {
            state.backActionButton.disabled = false;
        }
    };

    const clearPreview = () => {
        previewContainer.innerHTML = '';
        setFormVisibility(true);
        const previewButton = formContainer.querySelector(SELECTORS.PREVIEWBUTTON);
        const submitButton = formContainer.querySelector(SELECTORS.SUBMITBUTTON);

        if (previewButton) {
            previewButton.focus();
        } else if (submitButton) {
            submitButton.focus();
        }

        state.uploadActionButton = null;
        state.backActionButton = null;
        resetUploadActionState();
    };

    // Use event delegation to set previewmode before the dynamic form serialises the data.
    formContainer.addEventListener('click', (e) => {
        const previewField = formContainer.querySelector(SELECTORS.PREVIEWMODE);
        if (!previewField) {
            return;
        }
        if (e.target.matches(SELECTORS.PREVIEWBUTTON)) {
            previewField.value = '1';
        } else if (e.target.matches(SELECTORS.SUBMITBUTTON)) {
            previewField.value = '0';
        }
    }, true); // Capture phase so it fires before the form submit handler.

    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, () => {
        resetUploadActionState();
    });

    dynamicForm.addEventListener(dynamicForm.events.CLIENT_VALIDATION_ERROR, () => {
        resetUploadActionState();
    });

    // If a user imports an element, trigger treatment of input.
    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();

        resetUploadActionState();

        const response = e.detail;

        // Always reset the previewmode field so subsequent imports work normally.
        const previewField = formContainer.querySelector(SELECTORS.PREVIEWMODE);
        if (previewField) {
            previewField.value = '0';
        }

        if (response.preview) {
            // Preview mode: render the preview table without reloading the form.
            const errors = response.errors;
            if (errors && errors.generalerrors) {
                errors.generalerrors.forEach(
                    (error) => showNotification(error, 'danger', false));
            }

            const templateContext = buildPreviewContext(response);
            const renderPreview = async() => {
                const {html, js} = await Templates.renderForPromise('mod_booking/importer/csvpreview', templateContext);
                Templates.replaceNodeContents(previewContainer, html, js);
                const labels = await getPreviewActionLabels();
                setupTablePagination(previewContainer, labels.rowsperpage);
                const submitButton = formContainer.querySelector(SELECTORS.SUBMITBUTTON);
                if (!submitButton) {
                    return;
                }

                const actionButtons = renderPreviewActions({
                    formContainer,
                    previewContainer,
                    labels,
                    onBack: () => {
                        clearPreview();
                    },
                    onUpload: () => {
                        if (state.uploadInProgress) {
                            return;
                        }

                        state.uploadInProgress = true;
                        if (state.uploadActionButton) {
                            state.uploadActionButton.disabled = true;
                        }
                        if (state.backActionButton) {
                            state.backActionButton.disabled = true;
                        }

                        submitButton.click();
                    }
                });

                if (actionButtons) {
                    state.backActionButton = actionButtons.backButton;
                    state.uploadActionButton = actionButtons.confirmSubmitButton;
                }

                setFormVisibility(false);
                previewContainer.scrollIntoView({behavior: 'smooth', block: 'start'});
            };

            renderPreview().catch(err => {
                resetUploadActionState();
                // eslint-disable-next-line no-console
                console.error(err);
            });

        } else {
            // Normal import: clear any previous preview, reload form, show result notifications.
            clearPreview();

            const errors = response.errors;

            dynamicForm.load({
                id: response.id,
                cmid: response.cmid,
                settingscallback: response.settingscallback,
                previewcallback: response.previewcallback,
            });

            // Display errors notifications if defined.
            if (errors != [] && errors !== undefined) {

                // eslint-disable-next-line no-console
                console.log("errors.warnings: ", errors.warnings);

                if (errors.warnings !== undefined && errors.warnings != []) {
                    errors.warnings.forEach(
                        (warning) => showNotification(warning, "warning", false));
                }
                if (errors.lineerrors !== undefined) {
                    errors.lineerrors.forEach(
                        (error) => showNotification(error, "danger", false));
                }
                if (errors.generalerrors !== undefined) {
                    errors.generalerrors.forEach(
                        (error) => showNotification(error, "danger", false));
                }
            }

            // Display general success status.
            if (response.success == 1) {

                getString('importsuccess', 'mod_booking', response.numberofsuccessfullyupdatedrecords).then(message => {
                    showNotification(message, 'success', false);
                    return;
                }).catch(err => {
                    // eslint-disable-next-line no-console
                    console.error(err);
                });
                if (response.callbackresponse !== null && response.callbackresponse !== undefined
                        && response.callbackresponse.message !== null) {
                    showNotification(response.callbackresponse.message, 'success', false);
                }
            } else {
                getString('importfailed', 'mod_booking').then(message => {
                    showNotification(message, 'danger', false);
                    return;
                }).catch(err => {
                    // eslint-disable-next-line no-console
                    console.error(err);
                });
            }
        }
    });

    // Cancel button triggers reload of empty form.
    dynamicForm.addEventListener(dynamicForm.events.FORM_CANCELLED, (e) => {
        e.preventDefault();
        clearPreview();
        dynamicForm.load({});
    });

};