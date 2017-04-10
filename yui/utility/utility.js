YUI.add('moodle-mod_booking-utility', function(Y) {

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
     // @main moodle-mod_booking-utility

     // @namespace M.mod_booking
     // @class utility

    M.mod_booking = M.mod_booking || {};
    M.mod_booking.utility = {
        init : function() {

            Y.one('#buttonclear').on('click', function() {
                Y.one('#menusearchwaitinglist').set('value', '');
                Y.one('#menusearchfinished').set('value', '');
                Y.one('#searchdate').set('value', '');
                Y.one('#searchButton').simulate('click');
            });

            Y.delegate('click', function(e) {
                var buttonID = e.currentTarget.get('id'), node = Y
                        .one('#tableSearch');

                if (buttonID === 'showHideSearch') {
                    node.toggleView();
                    e.preventDefault();
                }

            }, document, 'a');

            Y.on('change', function(e) {
                var origin = e.target;
                var str = origin.get('id');
                var ratingvalue = origin.one('option:checked').get('value');
                var id = "#check" + str.replace('menurating', '');
                if (id == '#checkall') {
                    Y.all('input.usercheckbox').each(function() {
                        this.set('checked', 'checked');
                    });
                    Y.all('.postratingmenu.ratinginput').each(function() {
                        this.set('value', ratingvalue);
                    });
                } else {
                    Y.one(id).set('checked', 'checked');
                }

            }, '#studentsform');

            Y.on('click', function(e) {
                var checkbox = e.target;
                if (checkbox.get('checked')) {
                    Y.all('input.usercheckbox').each(function() {
                        this.set('checked', 'checked');
                    });
                } else {
                    Y.all('input.usercheckbox').each(function() {
                        this.set('checked', '');
                    });
                }
            }, '#usercheckboxall');

            Y.on('click', function() {
                Y.all('input.usercheckbox').each(function() {
                    this.set('checked', 'checked');
                });
            }, '#checkall');

            Y.on('click', function() {
                Y.all('input.usercheckbox').each(function() {
                    this.set('checked', '');
                });
            }, '#checknone');

            Y.on('click', function() {
                Y.all('input.usercheckbox').each(function() {
                    if (this.get('value') === 0) {
                        this.set('checked', 'checked');
                    }
                });
            }, '#checknos');

        }
    };
}, '@VERSION@', {
    requires : [ 'node', 'node-event-simulate', 'node-event-delegate',
            'event-valuechange' ]
});