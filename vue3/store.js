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
 * Validate if the string does excist.
 *
 * @package     local_berta
 * @author      Jacob Viertel
 * @copyright  2023 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Import needed libraries
import { createStore } from 'vuex';
import moodleAjax from 'core/ajax';
import moodleStorage from 'core/localstorage';
import Notification from 'core/notification';
import $ from 'jquery';

// Defining store for application
export function createAppStore() {
    return createStore({
        state() {
            return {
                strings: {},
                tabs: [],
                content: [],
                configlist: [],
                compareConfiglist: [],
                cmid: null,
            };
        },
        mutations: {
            // Mutations are synchronous.
            setStrings(state, strings) {
                state.strings = strings;
            },
            setTabs(state, tabs) {
                state.tabs = tabs;
            },
            setContent(state, content) {
              state.content = content;
            },
            setConfigList(state, configlist) {
              state.configlist = configlist;
              state.compareConfiglist = JSON.parse(JSON.stringify(configlist));
            },
            setCM(state, cmid) {
              state.cmid = cmid;
            },
        },
        actions: {
            // Actions are asynchronous.
            async loadLang(context) {
                const lang = $('html').attr('lang').replace(/-/g, '_');
                context.commit('setLang', lang);
            },
            async loadComponentStrings(context) {
                const lang = $('html').attr('lang').replace(/-/g, '_');
                const cacheKey = 'mod_booking/strings/' + lang;
                const cachedStrings = moodleStorage.get(cacheKey);
                if (cachedStrings) {
                    context.commit('setStrings', JSON.parse(cachedStrings));
                } else {
                    const request = {
                        methodname: 'core_get_component_strings',
                        args: {
                            'component': 'mod_booking',
                            lang,
                        },
                    };
                    const loadedStrings = await moodleAjax.call([request])[0];
                    let strings = {};
                    loadedStrings.forEach((s) => {
                        strings[s.stringid] = s.string;
                    });
                    context.commit('setStrings', strings);
                    moodleStorage.set(cacheKey, JSON.stringify(strings));
                }
            },
            async fetchTab(context, params) {
                const content = await ajax('mod_booking_get_parent_categories', {
                  coursecategoryid: params.coursecategoryid
                });
                if (params.coursecategoryid === 0) {
                    context.commit('setTabs', content);
                }
                // we get back an array, so we need to get the first element for tab.
                const tabcontent = content[0];

                // We have all the Data at once.
                // if (content.length > 1) {
                    
                // }
                if (tabcontent.json.length > 3) {
                  tabcontent.json = JSON.parse(tabcontent.json)
                } else if (content.length > 1) {
                  // combine all arrays for general:
                  let jsonCombined = [];
                  content.forEach((ce, index) => {
                    if (ce.json.length > 3) {
                        const parsejson =  JSON.parse(ce.json);
                        jsonCombined.push(parsejson);
                    }
                  });
                  content[0].json = jsonCombined;
                }
                context.commit('setContent', tabcontent);
                const configlist = await ajax('mod_booking_get_option_field_config', {
                  contextid: params.contextid
                });
                context.commit('setConfigList', configlist);
                return configlist;
            },
            async setParentContent(context, index) {
              return await ajax('mod_booking_set_parent_content', {
                capability: index.capability,
                id: index.id,
                json: index.json,
              });
            },
            async setCheckedBookingInstance(context, index) {
              await ajax('mod_booking_set_checked_booking_instance', {
                id: index.bookingid,
              });
            },
        }
    });
}

/**
 * Single ajax call to Moodle.
 */
export async function ajax(method, args) {
    const request = {
        methodname: method,
        args: Object.assign( args ),
    };

    try {
        return await moodleAjax.call([request])[0];
    } catch (e) {
        Notification.exception(e);
        throw e;
    }
}