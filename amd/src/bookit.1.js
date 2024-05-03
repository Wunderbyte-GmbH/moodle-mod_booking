import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {reloadAllTables} from 'local_wunderbyte_table/reload';
import {SELECTORS} from './bookit';

/**
 *
 * @param {int} itemid
 * @param {string} area
 * @param {int} userid
 * @param {object} data
 */
export function bookit(itemid, area, userid, data) {

    // eslint-disable-next-line no-console
    console.log('run bookit');

    Ajax.call([{
        methodname: "mod_booking_bookit",
        args: {
            'itemid': itemid,
            'area': area,
            'userid': userid,
            'data': JSON.stringify(data),
        },
        done: function(res) {

            var skipreload = false;

            if (document.querySelector('.booking-elective-component')) {
                window.location.reload();
            }

            const jsonarray = JSON.parse(res.json);

            // We might have more than one template to render.
            const templates = res.template.split(',');

            // There might be more than one button area.
            const buttons = document.querySelectorAll(SELECTORS.BOOKITBUTTON +
                '[data-itemid=\'' + itemid + '\']' +
                '[data-area=\'' + area + '\']');

            const promises = [];

            // We run through every button. and render the data.
            buttons.forEach(button => {

                // eslint-disable-next-line no-console
                console.log('bookit values', button.dataset.nojs, res.status);
                skipreload = true;
                if (button.dataset.nojs == 1
                    && res.status == 0) {
                    // eslint-disable-next-line no-console
                    console.log('bookit skip', button.dataset.nojs, res.status);
                } else {
                    // For every button, we need a new jsonarray.
                    const arraytoreduce = [...jsonarray];
                    if (res.status == 1) {
                        skipreload = false;
                    }
                    templates.forEach(template => {

                        const data = arraytoreduce.shift();

                        const datatorender = data.data ?? data;

                        const promise = Templates.renderForPromise(template, datatorender).then(({html, js}) => {

                            Templates.replaceNode(button, html, js);

                            return true;
                        }).catch(ex => {
                            Notification.addNotification({
                                message: 'failed rendering ' + ex,
                                type: "danger"
                            });
                        });

                        promises.push(promise);
                    });
                }
            });

            Promise.all(promises).then(() => {

                const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);

                // eslint-disable-next-line no-console
                console.log('skipreload', skipreload);

                if (!backdrop && !skipreload) {
                    reloadAllTables();
                }

                // The actions on successful booking are executed elsewhere.
                return true;
            }).catch(e => {
                // eslint-disable-next-line no-console
                console.log(e);
            });
        }
    }]);
}
