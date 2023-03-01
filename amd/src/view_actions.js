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
 * @module mod_booking/view_actions
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 3.1
 */

define(['jquery', 'core/config', 'mod_booking/jquery.barrating'],
    function($, mdlconfig) {
        return {
            setup: function() {
                const queryString = window.location.search;
                const urlParams = new URLSearchParams(queryString);
                const id = urlParams.get('id');
                /*
                 * TODO: We probably will need a possibility to update a rating
                 * as soon as you have rated something. Right now you can change it,
                 * but nothing will happen, which is bad usability.
                 */
                $('.starrating').each(function(index, value) {
                    var currentrating = $(this).data('current-rating');
                    var itemid = $(this).data('itemid');
                    if (!value) {
                        // Just for stupid TravisCI rules!
                        value = '';
                    }
                    var thisid = this;
                    $(this).barrating({
                        initialRating: currentrating,
                        theme: 'css-stars',
                        onSelect: function(value, text, event) {
                            if (typeof (event) !== 'undefined') {
                                $.ajax({
                                    url: mdlconfig.wwwroot + "/mod/booking/rating_rest.php?id=" + id,
                                    method: "POST",
                                    data: {optionid: itemid, value: value}
                                }).done(function(data) {
                                    $(thisid).barrating('readonly', true);
                                    $(thisid).barrating('set', data.rate);
                                });
                            }
                        }
                    });
                });

                $('#page-mod-booking-report #buttonclear')
                        .on('click',
                                function() {
                                    $('#menusearchwaitinglist, #menusearchfinished, #searchdate')
                                            .val('');
                                    $('#searchButton').trigger('click');
                                });

                $('#page-mod-booking-report #usercheckboxall')
                        .click(function() {
                            $('#studentsform input:checkbox').not(this)
                                    .prop('checked', this.checked);
                        });

                $('#page-mod-booking-report #menuratingall')
                        .change(function() {
                            $('#studentsform input:checkbox').not(this)
                                    .prop('checked', 'checked');
                            var selected = $(this).val();
                            $('.booking-option-rating .postratingmenu.ratinginput [value="' + selected + '"]')
                                    .attr('selected', true);
                        });

                $('#page-mod-booking-report .booking-option-rating .ratinginput')
                        .change(function() {
                            var selectid = $(this).attr('id');
                            var selected = selectid.replace(/\D/g, '');
                            $('#studentsform [id=check' + selected + ']')
                                    .prop('checked', 'checked');
                        });

            }
        };
    });