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
 * @module     mod_booking/bookinginstancetemplateselect
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
            $("#id_instancetemplateid").change(function() {
                if ($("#id_instancetemplateid").val() != '') {
                    ajax
                    .call([{
                        methodname: 'mod_booking_instancetemplate',
                        args: {
                            id: $("#id_instancetemplateid").val()
                        },
                        done: function(data) {
                            var obj = $.parseJSON(data.template);

                            // General
                            $("#id_name").val(obj.name);
                            $("#id_eventtype").val(obj.eventtype);
                            $("#id_introeditoreditable").text(obj.intro);
                            $('#id_duration').val(obj.duration);
                            $('#id_points').val(obj.points);
                            $('#id_organizatorname').val(obj.organizatorname);
                            $('#id_pollurl').val(obj.pollurl);
                            $('#id_pollurlteachers').val(obj.pollurlteachers);
                            $('#id_whichview').val(obj.whichview);
                            $('#id_defaultoptionsort').val(obj.defaultoptionsort);
                            $('#id_enablepresence').val(obj.enablepresence);
                            $('#id_templateid').val(obj.templateid);

                            // Confirmation e-mail settings
                            $('#id_sendmail').val(obj.sendmail);
                            $('#id_copymail').val(obj.copymail);
                            $('#id_sendmailtobooker').val(obj.sendmailtobooker);
                            $('#id_daystonotify').val(obj.daystonotify);
                            $('#id_daystonotify2').val(obj.daystonotify2);
                            // $('#id_bookingmanager').val(obj.bookingmanager);
                            $('#id_bookedtexteditable').text(obj.bookedtext);
                            $('#id_waitingtexteditable').text(obj.waitingtext);
                            $('#id_notifyemaileditable').text(obj.notifyemail);
                            $('#id_statuschangetexteditable').text(obj.statuschangetext);
                            $('#id_userleaveeditable').text(obj.userleave);
                            $('#id_deletedtexteditable').text(obj.deletedtext);
                            $('#id_pollurltexteditable').text(obj.pollurltext);
                            $('#id_pollurlteacherstexteditable').text(obj.pollurlteacherstext);
                            $('#id_notificationtexteditable').text(obj.notificationtext);

                            // Custom labels
                            $('#id_btncacname').val(obj.btncacname);
                            $('#id_lblteachname').val(obj.lblteachname);
                            $('#id_lblsputtname').val(obj.lblsputtname);
                            $('#id_btnbooknowname').val(obj.btnbooknowname);
                            $('#id_btncancelname').val(obj.btncancelname);
                            $('#id_lblbooking').val(obj.lblbooking);
                            $('#id_lbllocation').val(obj.lbllocation);
                            $('#id_lblinstitution').val(obj.lblinstitution);
                            $('#id_lblname').val(obj.lblname);
                            $('#id_lblsurname').val(obj.lblsurname);
                            $('#id_booktootherbooking').val(obj.booktootherbooking);
                            $('#id_lblacceptingfrom').val(obj.lblacceptingfrom);
                            $('#id_lblnumofusers').val(obj.lblnumofusers);

                            // Miscellaneous settings
                            $('#id_bookingpolicyeditable').text(obj.bookingpolicy);
                            $('#id_cancancelbook').val(obj.cancancelbook);
                            $('#id_allowupdate').val(obj.allowupdate);
                            $('#id_allowupdatedays').val(obj.allowupdatedays);
                            $('#id_autoenrol').val(obj.autoenrol);
                            $('#id_addtogroup').val(obj.addtogroup);
                            $('#id_maxperuser').val(obj.maxperuser);
                            $('#id_showinapi').val(obj.showinapi);
                            $('#id_numgenerator').val(obj.numgenerator);
                            $('#id_paginationnum').val(obj.paginationnum);
                            $('#id_banusernames').val(obj.banusernames);
                            $('#id_showhelpfullnavigationlinks').val(obj.showhelpfullnavigationlinks);
                            $('#id_completionmodule').val(obj.completionmodule);
                            $('#id_comments').val(obj.comments);
                            $('#id_ratings').val(obj.ratings);
                            $('#id_removeuseronunenrol').val(obj.removeuseronunenrol);

                            // Category
                            $("#id_categoryid").val(JSON.parse("[" + obj.categoryid + "]"));

                            // Fields to display in different contexts
                            // $("#id_signinsheetfields").val(JSON.parse("[" + obj.signinsheetfields + "]")).change();

                            // TO-DO :Create backup!
                            // TO-DO: Fields still to add:
                            // - assesstimefinish
                            // - assesstimestart
                            // - course
                            // - enablecompletion
                            // - optionsfields
                            // - reportfields
                            // - responsesfields
                            // - scale
                            // - signinsheetfields
                            // - timeclose
                            // - timemodified
                            // - timeopen

                            // Booking option text depending on booking status
                            $('#id_beforecompletedtexteditable').text(obj.beforecompletedtext);
                            $('#id_aftercompletedtexteditable').text(obj.aftercompletedtext);
                            $('#id_beforebookedtexteditable').text(obj.beforebookedtext);

                            // Connected booking
                            $('#id_conectedbooking').val(obj.conectedbooking);

                            // Teachers
                            $('#id_teacherroleid').val(obj.teacherroleid);

                            // Ratings
                            $('#id_assessed').val(obj.assessed);
                        }
                    }], true);
                }
            });
        }
    };
});