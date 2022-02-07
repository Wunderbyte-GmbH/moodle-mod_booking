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
                            if ($('#id_limitanswers').is(':checked') != (obj.bookingclosingtime == 0 ? false : true)) {
                                $("#id_limitanswers").trigger('click');
                            }
                            $("#id_maxanswers").val(obj.maxanswers);
                            $("#id_maxoverbooking").val(obj.maxoverbooking);
                            if ($('#id_restrictanswerperiod').is(':checked') != (obj.bookingclosingtime == 0 ? false : true)) {
                                $("#id_restrictanswerperiod").trigger('click');
                            }

                            var datebookingclosingtime = new Date(obj.bookingclosingtime * 1000);
                            $("#id_bookingclosingtime_day").val(datebookingclosingtime.getDate());
                            $("#id_bookingclosingtime_month").val(datebookingclosingtime.getMonth() + 1);
                            $("#id_bookingclosingtime_year").val(datebookingclosingtime.getFullYear());
                            $("#id_bookingclosingtime_hour").val(datebookingclosingtime.getHours());
                            $("#id_bookingclosingtime_minute").val(datebookingclosingtime.getMinutes());

                            $("#id_courseid").val(obj.courseid);

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

                            if ($('#id_startendtimeknown').is(':checked') != (obj.coursestarttime == 0 ? false : true)) {
                                $("#id_startendtimeknown").trigger('click');
                            }

                            $("#id_addtocalendar").val(obj.addtocalendar);

                            var datecoursestarttime = new Date(obj.coursestarttime * 1000);
                            $("#id_coursestarttime_day").val(datecoursestarttime.getDate());
                            $("#id_coursestarttime_month").val(datecoursestarttime.getMonth() + 1);
                            $("#id_coursestarttime_year").val(datecoursestarttime.getFullYear());
                            $("#id_coursestarttime_hour").val(datecoursestarttime.getHours());
                            $("#id_coursestarttime_minute").val(datecoursestarttime.getMinutes());

                            if ($('#id_enrolmentstatus').is(':checked') != (obj.enrolmentstatus == 0 ? false : true)) {
                                $("#id_enrolmentstatus").trigger('click');
                            }

                            var datecourseendtime = new Date(obj.courseendtime * 1000);
                            $("#id_courseendtime_day").val(datecourseendtime.getDate());
                            $("#id_courseendtime_month").val(datecourseendtime.getMonth() + 1);
                            $("#id_courseendtime_year").val(datecourseendtime.getFullYear());
                            $("#id_courseendtime_hour").val(datecourseendtime.getHours());
                            $("#id_courseendtime_minute").val(datecourseendtime.getMinutes());

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

                            // Trigger clicks to fix autocomplete bugs.
                            $("#fitem_id_location .form-autocomplete-downarrow").trigger('click');
                            $("#fitem_id_institution .form-autocomplete-downarrow").trigger('click');
                            $("#fitem_id_courseid .badge").trigger('click');
                            $("#id_optiontemplateid").trigger('click');

                            // A little hack to close open menus.
                            $("#fitem_id_courseendtime .icon").trigger('click');
                            $("#fitem_id_courseendtime .icon").trigger('click');

                        }
                    }], true);
                }
            });
        }
    };
});