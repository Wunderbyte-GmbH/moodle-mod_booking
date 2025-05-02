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

                            // TODO: eventtype does not yet work correctly.
                            $("#id_eventtype").val(obj.eventtype);

                            $("#id_introeditoreditable").html(obj.intro);
                            $('#id_duration').val(obj.duration);
                            $('#id_points').val(obj.points);
                            $('#id_organizatorname').val(obj.organizatorname);
                            $('#id_pollurl').val(obj.pollurl);
                            $('#id_pollurlteachers').val(obj.pollurlteachers);
                            // TODO: attachment - is this even possible?
                            // TODO: Views to show in the booking options overview.
                            $('#id_whichview').val(obj.whichview);
                            $('#id_defaultoptionsort').val(obj.defaultoptionsort);
                            $('#id_defaultsortorder').val(obj.defaultsortorder);
                            $('#id_templateid').val(obj.templateid);
                            $('#id_showlistoncoursepage').val(obj.showlistoncoursepage);
                            $('#id_coursepageshortinfo').val(obj.coursepageshortinfo);
                            // Known issue: coursepageshortinfo won't be unhidden when filled from template.

                            // Confirmation e-mail settings
                            $('#id_sendmail').val(obj.sendmail);
                            $('#id_copymail').val(obj.copymail);
                            $('#id_sendmailtobooker').val(obj.sendmailtobooker);
                            $('#id_daystonotify').val(obj.daystonotify);
                            $('#id_daystonotify2').val(obj.daystonotify2);
                            $('#id_daystonotifyteachers').val(obj.daystonotifyteachers);
                            // TODO: bookingmanager
                            $('#id_mailtemplatessource').val(obj.mailtemplatessource);
                            $('#id_bookedtexteditable').html(obj.bookedtext);
                            $('#id_waitingtexteditable').html(obj.waitingtext);
                            $('#id_notifyemaileditable').html(obj.notifyemail);
                            $('#id_notifyemailteacherseditable').html(obj.notifyemailteachers);
                            $('#id_statuschangetexteditable').html(obj.statuschangetext);
                            $('#id_userleaveeditable').html(obj.userleave);
                            $('#id_deletedtexteditable').html(obj.deletedtext);
                            $('#id_bookingchangedtexteditable').html(obj.bookingchangedtext);
                            $('#id_pollurltexteditable').html(obj.pollurltext);
                            $('#id_pollurlteacherstexteditable').html(obj.pollurlteacherstext);
                            $('#id_activitycompletiontexteditable').html(obj.activitycompletiontext);

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
                            $('#id_bookingpolicyeditable').html(obj.bookingpolicy);
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
                            $('#id_completionmodule').val(obj.completionmodule);
                            $('#id_comments').val(obj.comments);
                            $('#id_ratings').val(obj.ratings);
                            $('#id_removeuseronunenrol').val(obj.removeuseronunenrol);

                            // Category
                            $("#id_categoryid").val(JSON.parse("[" + obj.categoryid + "]"));

                            // TODO: Fields to display in different contexts

                            // Booking option text depending on booking status
                            $('#id_beforecompletedtexteditable').html(obj.beforecompletedtext);
                            $('#id_aftercompletedtexteditable').html(obj.aftercompletedtext);
                            $('#id_beforebookedtexteditable').html(obj.beforebookedtext);

                            // TODO: Sign-In Sheet Configuration
                            // $("#id_signinsheetfields").val(JSON.parse("[" + obj.signinsheetfields + "]")).change();

                            // TO-DO :Create backup!
                            // TO-DO: Fields still to add:
                            // - assesstimefinish
                            // - assesstimestart
                            // - course
                            // - enablecompletion
                            // - optionsfields
                            // - optionsdownloadfields
                            // - reportfields
                            // - responsesfields
                            // - scale
                            // - signinsheetfields
                            // - timeclose
                            // - timemodified
                            // - timeopen

                            // Connected booking
                            $('#id_conectedbooking').val(obj.conectedbooking);

                            // Teachers
                            $('#id_teacherroleid').val(obj.teacherroleid);

                            // TODO: Custom report templates
                            // TODO: Automatic booking option creation

                            // Ratings
                            $('#id_assessed').val(obj.assessed);

                            // TODO: Common module settings
                            // TODO: Restrict access (possible?)
                            // TODO: Activity completion
                            // TODO: Competencies
                        }
                    }], true);
                }
            });
        }
    };
});
