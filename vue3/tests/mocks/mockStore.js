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
 * Vuex store mock used by the Vue 3 unit tests of mod_booking.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// mockStore.js

import { createStore } from 'vuex';

const mockState = {
  view: 'default', // or any default state you want to set
  strings: {
    fromlearningtitel: 'Goal Title',
    goalnameplaceholder: 'Enter Goal Name',
    fromlearningdescription: 'Goal Description',
    goalsubjectplaceholder: 'Enter Goal Description',
  },
  learningpath: {
    name: 'Testing',
    description: 'Testing description',
  }
};

const store = createStore({
  state() {
    return mockState;
  },
});

export default store;