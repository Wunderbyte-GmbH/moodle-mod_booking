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
 * AJAX helper for the inline editing a value.
 *
 * This script is automatically included from template core/inplace_editable
 * It registers a click-listener on [data-inplaceeditablelink] link (the "inplace edit" icon),
 * then replaces the displayed value with an input field. On "Enter" it sends a request
 * to web service core_update_inplace_editable, which invokes the specified callback.
 * Any exception thrown by the web service (or callback) is displayed as an error popup.
 *
 * @module     mod_booking/alluserbookings
 * @package    mod_booking
 * @copyright  2022 Andraž Prinčič
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      4.5
 */

define(['jquery', 'core/str', 'core/notification'], function ($, str, notification) {
    return {
        init: function () {
            function HideAll() {
                $('#downloadtype_block_transferoption').hide();
                $('#downloadtype_block_selectpresencestatus').hide();
                $('#downloadtype_block_booktootherbooking').hide();

                if ($('#downloadtype_massactions').val() == "transferheading") {
                    $('#downloadtype_block_transferoption').show();
                }

                if ($('#downloadtype_massactions').val() == "changepresencestatus") {
                    $('#downloadtype_block_selectpresencestatus').show();
                }

                if ($('#downloadtype_massactions').val() == "connectedbookings") {
                    $('#downloadtype_block_booktootherbooking').show();
                }
            }

            HideAll();

            $('#downloadtype_massactions').on('change', function () {
                HideAll();
            });

            $('#downloadtype_button_massactions').on('click', function () {
                if ($('#downloadtype_massactions').val() == "generaterecnum") {
                    notification.confirm(str.get_string('areyousure', 'mod_booking'),
                        str.get_string('generaterecnumareyousure', 'mod_booking'), str.get_string('yes'),
                        str.get_string('no'), function () {
                            $('#studentsform').submit();
                        });
                    return false;
                }
            });
        }
    };
});