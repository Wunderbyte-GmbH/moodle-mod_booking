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
 *
 * @package     local_adele
 * @author      Jacob Viertel
 * @copyright  2023 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Import needed libraries
import { createRouter, createWebHashHistory } from 'vue-router';
import notFound from '../components/NotFound';
import bookingDashboard from '../components/BookingDashboard'
import configForm from '../components/dashboard/ConfigForm'

// All available routes
const routes = [
    {
        path: '/booking',
        redirect: {
            name: 'booking-overview'
        }
    },
    {
        path: '/booking/overview',
        component: bookingDashboard,
        name: 'booking-overview',
    },
    {
        path: '/booking/context',
        component: configForm,
        name: 'booking-context',
    },
    {
        path: '/booking/:catchAll(.*)',
        name: 'not-found',
        component: notFound,
    },
];

// Creating router
const router = createRouter({
    history: createWebHashHistory(),
    routes,
});


export default router