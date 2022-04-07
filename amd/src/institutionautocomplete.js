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
 * Javascript controller for booking module
 *
 * @module mod_booking/institutionautocomplete
 * @package mod_booking
 * @copyright 2018 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 3.1
 */

define(['jquery', "jqueryui", 'core/config'], function($, jqueryui, mdlconfig) {

    return {
        init: function(id) {
            $.ajax({
                url: mdlconfig.wwwroot + "/mod/booking/institutions_rest.php?id=" + id,
                method: "POST",
            }).done(function(data) {
                $("#id_institution").autocomplete("option", "source", data);
            });

            $("#id_institution").autocomplete();
        }
    };
});