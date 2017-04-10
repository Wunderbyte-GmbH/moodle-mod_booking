YUI.add('moodle-mod_booking-viewscript', function(Y) {

    // This file is part of Moodle - http://moodle.org/
    //
    // Moodle is free software: you can redistribute it and/or modify
    // it under the terms of the GNU General Public License as published by
    // the Free Software Foundation, either version 3 of the License, or
    // (at your option) any later version.
    //
    // Moodle is distributed in the hope that it will be useful,
    // but WITHOUT ANY WARRANTY; without even the implied warranty of
    // MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
    // GNU General Public License for more details.
    //
    // You should have received a copy of the GNU General Public License
    // along with Moodle. If not, see <http://www.gnu.org/licenses/>.

     // @package mod_booking
     // @copyright 2016 David Bogner
     // @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     // @main moodle-mod_booking-viewscript
     // @namespace M.mod_booking
     // @class viewscript

    M.mod_booking = M.mod_booking || {};
    M.mod_booking.viewscript = {
        init : function() {

            Y.one('#buttonclear').on('click', function() {
                Y.one('#searchtext').set('value', '');
                Y.one('#searchlocation').set('value', '');
                Y.one('#searchinstitution').set('value', '');
                Y.one('#searchname').set('value', '');
                Y.one('#searchsurname').set('value', '');
                Y.one('#searchButton').simulate('click');
            });

            Y.delegate('click', function(e) {
                var buttonID = e.currentTarget.get('id'), node = Y
                        .one('#tableSearch');

                if (buttonID === 'showHideSearch') {
                    node.toggleView();
                    location.hash = "#goenrol";
                    e.preventDefault();
                }

            }, document, 'a');

        }
    };
}, '@VERSION@', {
    requires : [ 'node', 'node-event-simulate' ]
});