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
 * @module     mod_booking/optionstemplateselect
 * @package    mod_booking
 * @copyright  2019 Andraž Prinčič
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      4.5
 */

define(['jquery', 'core/ajax'], function($, ajax) {

    return {
        init: function() {

            // Put whatever you like here. $ is available
            // to you as normal.
            $("#id_optiontemplateid").change(function() {
                if ($("#id_optiontemplateid").val() != '') {
                    ajax
                    .call([{
                        methodname: 'mod_booking_optiontemplate',
                        args: {
                            id: $("#id_optiontemplateid").val()
                        },
                        done: function(data) {
                            var obj = $.parseJSON(data.template);

                            // General
                            $("#id_text").val(obj.text);
                            $("#id_location").val(obj.location);
                            $("#id_institution").val(obj.institution);
                            $("#id_address").val(obj.address);
                            $("#id_limitanswers").prop("checked", (obj.limitanswers == 0 ? false : true));
                            $("#id_maxanswers").val(obj.maxanswers);
                            $("#id_maxoverbooking").val(obj.maxoverbooking);
                            $("#id_restrictanswerperiod").prop("checked", (obj.restrictanswerperiod == 0 ? false : true));
                            $("#id_startendtimeknown").prop("checked", (obj.startendtimeknown == 0 ? false : true));
                            $("#id_addtocalendar").prop("checked", (obj.addtocalendar == 0 ? false : true));

                            var x = obj.duration;
                            switch (true) {
                                case (x < 60):
                                    $("#id_duration_number").val(x);
                                    $("#id_duration_timeunit").val(1);
                                    break;
                                case (x < 3600):
                                    $("#id_duration_number").val(x / 60);
                                    $("#id_duration_timeunit").val(60);
                                    break;
                                case (x < 86400):
                                    $("#id_duration_number").val(x / 3600);
                                    $("#id_duration_timeunit").val(3600);
                                    break;
                                case (x < 604800):
                                    $("#id_duration_number").val(x / 86400);
                                    $("#id_duration_timeunit").val(86400);
                                    break;
                                default:
                                    $("#id_duration_number").val(x / 604800);
                                    $("#id_duration_timeunit").val(604800);
                                    break;
                            }

                            $("#id_enrolmentstatus").prop("checked", (obj.enrolmentstatus == 0 ? false : true));
                            $("#id_descriptioneditable").html(obj.description);
                            $("#id_pollurl").val(obj.pollurl);
                            $("#id_pollurlteachers").val(obj.pollurlteachers);
                            $("#id_howmanyusers").val(obj.howmanyusers);
                            $("#id_removeafterminutes").val(obj.removeafterminutes);

                            // Advanced options.
                            $("#id_notificationtexteditable").html(obj.notificationtext);
                            $("#id_disablebookingusers").val(obj.disablebookingusers);

                            // Booking option text depending on booking status.
                            $("#id_beforebookedtexteditable").html(obj.beforebookedtext);
                            $("#id_beforecompletedtexteditable").html(obj.beforecompletedtext);
                            $("#id_aftercompletedtexteditable").html(obj.aftercompletedtext);
                        }
                    }], true);
                }
            });
        }
    };
});