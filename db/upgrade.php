<?php
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

function xmldb_booking_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2011020401) {

        // Rename field text on table booking to text.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('text', XMLDB_TYPE_TEXT, 'small', null, XMLDB_NOTNULL, null, null,
                'name');

        // Launch rename field text.
        $dbman->rename_field($table, $field, 'intro');

        // Rename field format on table booking to format.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('format', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL,
                null, '0', 'intro');

        // Launch rename field format.
        $dbman->rename_field($table, $field, 'introformat');

        $table = new xmldb_table('booking');
        $field = new xmldb_field('bookingpolicyformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL,
                null, '0', 'bookingpolicy');

        // Conditionally launch add field completionsubmit.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2011020401, 'booking');
    }
    if ($oldversion < 2011020403) {
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('descriptionformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL,
                null, '0', 'description');

        // Conditionally launch add field completionsubmit.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2011020403, 'booking');
    }

    if ($oldversion < 2012091601) {
        // Define field autoenrol to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('autoenrol', XMLDB_TYPE_INTEGER, '4', null, null, null, '0',
                'timemodified');

        // Conditionally launch add field autoenrol.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2012091601, 'booking');
    }

    // Add fields to store custom email message content.
    if ($oldversion < 2012091602) {
        $table = new xmldb_table('booking');

        $field = new xmldb_field('bookedtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null,
                'autoenrol');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('waitingtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null,
                'bookedtext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('statuschangetext', XMLDB_TYPE_TEXT, 'medium', null, null, null,
                null, 'waitingtext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('deletedtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null,
                'statuschangetext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2012091602, 'booking');
    }

    if ($oldversion < 2012091603) {
        // Define field maxperuser to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('maxperuser', XMLDB_TYPE_INTEGER, '10', null, null, null, '0',
                'deletedtext');

        // Conditionally launch add field maxperuser.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2012091603, 'booking');
    }

    if ($oldversion < 2014012807) {

        // Define field addtocalendar to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('addtocalendar', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null,
                '0', 'timemodified');

        // Conditionally launch add field addtocalendar.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('calendarid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0',
                'addtocalendar');

        // Conditionally launch add field calendarid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014012807, 'booking');
    }

    if ($oldversion < 2014030600) {

        $table = new xmldb_table('booking');
        $tableoptions = new xmldb_table('booking_options');

        $field = new xmldb_field('duration', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'maxperuser');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('points', XMLDB_TYPE_INTEGER, '10', null, null, null, null,
                'duration');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('organizatorname', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'points');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurl', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'organizatorname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurl', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'calendarid');
        if (!$dbman->field_exists($tableoptions, $field)) {
            $dbman->add_field($tableoptions, $field);
        }

        // Define field course to be dropped from booking.
        $field = new xmldb_field('tags');

        // Conditionally launch drop field course.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Define field addtogroup to be added to booking.
        $field = new xmldb_field('addtogroup', XMLDB_TYPE_INTEGER, '4', null, null, null, '0',
                'pollurl');

        // Conditionally launch add field addtogroup.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field groupname to be dropped from booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('groupname');

        // Conditionally launch drop field groupname.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014030600, 'booking');
    }

    if ($oldversion < 2014030601) {

        // Define field groupid to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '11', null, null, null, null,
                'pollurl');

        // Conditionally launch add field groupid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014030601, 'booking');
    }

    if ($oldversion < 2014031100) {

        // Define table booking_category to be created.
        $table = new xmldb_table('booking_category');

        // Adding fields to table booking_category.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('name', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table booking_category.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for booking_category.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014031100, 'booking');
    }

    if ($oldversion < 2014031200) {

        // Define field categoryid to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null,
                'addtogroup');

        // Conditionally launch add field categoryid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014031200, 'booking');
    }

    if ($oldversion < 2014031700) {

        // Define field pollurltext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('pollurltext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'categoryid');

        // Conditionally launch add field pollurltext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014031700, 'booking');
    }

    if ($oldversion < 2014031900) {

        // Define table booking_teachers to be created.
        $table = new xmldb_table('booking_teachers');

        // Adding fields to table booking_teachers.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('bookingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Adding keys to table booking_teachers.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('bookingid', XMLDB_KEY_FOREIGN, array('bookingid'), 'booking', array('id'));
        $table->add_key('optionid', XMLDB_KEY_FOREIGN, array('optionid'), 'booking_options',
                array('id'));

        // Adding indexes to table booking_teachers.
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));

        // Conditionally launch create table for booking_teachers.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014031900, 'booking');
    }

    if ($oldversion < 2014032000) {

        // Define field additionalfields to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('additionalfields', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'pollurltext');

        // Conditionally launch add field additionalfields.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032000, 'booking');
    }

    if ($oldversion < 2014032101) {

        // Define field daystonotify to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('daystonotify', XMLDB_TYPE_INTEGER, '3', null, null, null, '0',
                'groupid');

        // Conditionally launch add field daystonotify.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sent', XMLDB_TYPE_INTEGER, '1', null, null, null, '0',
                'daystonotify');

        // Conditionally launch add field sent.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032101, 'booking');
    }

    if ($oldversion < 2014032600) {

        $table = new xmldb_table('booking');
        $tableoptions = new xmldb_table('booking_options');

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'organizatorname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurl');
        }

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'organizatorname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurl');
        }

        $field = new xmldb_field('poolurltext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'categoryid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurltext');
        }

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'calendarid');
        if ($dbman->field_exists($tableoptions, $field)) {
            $dbman->rename_field($tableoptions, $field, 'pollurl');
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032600, 'booking');
    }

    if ($oldversion < 2014032800) {

        // Changing type of field categoryid on table booking to text.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('categoryid', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'addtogroup');

        // Launch change of type for field categoryid.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032800, 'booking');
    }

    if ($oldversion < 2014033101) {

        // Changing the default of field eventtype on table booking to Booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '255', null, null, null, 'Booking',
                'additionalfields');

        // Launch change of default for field eventtype.
        $dbman->change_field_default($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014033101, 'booking');
    }

    if ($oldversion < 2014040700) {

        // Changing type of field points on table booking to number.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('points', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null,
                'duration');

        // Launch change of type for field points.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014040700, 'booking');
    }

    if ($oldversion < 2014091600) {

        $table = new xmldb_table('booking');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'additionalfields');

        // Conditionally launch add field eventtype.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field notificationtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'eventtype');

        // Conditionally launch add field notificationtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Define field completed to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, null, null, '0',
                'timemodified');

        // Conditionally launch add field completed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field userleave to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('userleave', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'notificationtext');

        // Conditionally launch add field userleave.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field enablecompletion to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('enablecompletion', XMLDB_TYPE_INTEGER, '1', null, null, null, '0',
                'userleave');

        // Conditionally launch add field enablecompletion.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014091600, 'booking');
    }

    if ($oldversion < 2014092901) {

        // Define field pollurlteachers to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('pollurlteachers', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'enablecompletion');

        // Conditionally launch add field pollurlteachers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurlteacherstext', XMLDB_TYPE_TEXT, null, null, null, null,
                null, 'pollurlteachers');
        // Conditionally launch add field pollurlteacherstext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurlteachers', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'address');
        // Conditionally launch add field pollurlteachers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field location to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sent');

        // Conditionally launch add field location.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('institution', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'location');

        // Conditionally launch add field institution.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('address', XMLDB_TYPE_CHAR, '255', null, null, null, null,
                'institution');

        // Conditionally launch add field address.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014092901, 'booking');
    }

    if ($oldversion < 2014111800) {

        // Define field timecreated to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null,
                '0', 'completed');

        // Conditionally launch add field timecreated.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014111800, 'booking');
    }

    if ($oldversion < 2014111900) {

        // Define table booking_tags to be created.
        $table = new xmldb_table('booking_tags');

        // Adding fields to table booking_tags.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tag', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);

        // Adding keys to table booking_tags.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for booking_tags.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014111900, 'booking');
    }

    if ($oldversion < 2014112600) {

        // Define field addtocalendar to be added to booking_options.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('sendmailtobooker', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL,
                null, '0', 'maxperuser');

        // Conditionally launch add field sendmailtobooker.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014112600, 'booking');
    }

    if ($oldversion < 2014120800) {

        // Define field waitinglist to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('waitinglist', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null,
                '0', 'timecreated');

        // Conditionally launch add field waitinglist.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014120800, 'booking');
    }

    if ($oldversion < 2014121000) {

        // Define field cancancelbook to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('cancancelbook', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null,
                '0', 'pollurlteacherstext');

        // Conditionally launch add field cancancelbook.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014121000, 'booking');
    }

    if ($oldversion < 2014122900) {

        // Define field conectedbooking to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('conectedbooking', XMLDB_TYPE_INTEGER, '10', null, null, null, '0',
                'cancancelbook');

        // Conditionally launch add field conectedbooking.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014122900, 'booking');
    }

    if ($oldversion < 2014123000) {

        // Define field conectedoption to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('conectedoption', XMLDB_TYPE_INTEGER, '10', null, null, null, '0',
                'pollurlteachers');

        // Conditionally launch add field conectedoption.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014123000, 'booking');
    }

    if ($oldversion < 2014123001) {

        // Define field howmanyusers to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('howmanyusers', XMLDB_TYPE_INTEGER, '10', null, null, null, '0',
                'conectedoption');

        // Conditionally launch add field howmanyusers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014123001, 'booking');
    }

    if ($oldversion < 2015012000) {

        // Define field completed to be added to booking_teachers.
        $table = new xmldb_table('booking_teachers');
        $field = new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, null, null, '0',
                'optionid');

        // Conditionally launch add field completed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015012000, 'booking');
    }

    if ($oldversion < 2015012100) {

        // Define field showinapi to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showinapi', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null,
                '1', 'conectedbooking');

        // Conditionally launch add field showinapi.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015012100, 'booking');
    }

    if ($oldversion < 2015031000) {

        // Define field pollsend to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('pollsend', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0',
                'howmanyusers');

        // Conditionally launch add field pollsend.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015031000, 'booking');
    }

    if ($oldversion < 2015031700) {

        // Define field id to be added to booking_tags.
        $table = new xmldb_table('booking_tags');
        $field = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE,
                null, null);

        // Conditionally launch add field id.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015031700, 'booking');
    }

    if ($oldversion < 2015032400) {

        // Define field removeafterminutes to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('removeafterminutes', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL,
                null, '0', 'pollsend');

        // Conditionally launch add field removeafterminutes.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015032400, 'booking');
    }

    if ($oldversion < 2015051800) {

        // Define field btncacname to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('btncacname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null,
                null, 'removeafterminutes');

        // Conditionally launch add field btncacname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015051800, 'booking');
    }

    if ($oldversion < 2015051900) {

        // Define field lblteachname to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('lblteachname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null,
                null, 'btncacname');

        // Conditionally launch add field lblteachname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsputtname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null,
                null, 'lblteachname');

        // Conditionally launch add field lblsputtname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015051900, 'booking');
    }

    if ($oldversion < 2015051901) {

        // Define field notificationtext to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'lblsputtname');

        // Conditionally launch add field notificationtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015051901, 'booking');
    }

    if ($oldversion < 2015051902) {

        // Define field notificationtextformat to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('notificationtextformat', XMLDB_TYPE_INTEGER, '2', null,
                XMLDB_NOTNULL, null, '0', 'notificationtext');

        // Conditionally launch add field notificationtextformat.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015051902, 'booking');
    }

    if ($oldversion < 2015052000) {

        // Define field btnbooknowname to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('btnbooknowname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null,
                null, 'notificationtextformat');

        // Conditionally launch add field btnbooknowname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btncancelname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null,
                null, 'btnbooknowname');

        // Conditionally launch add field btncancelname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015052000, 'booking');
    }

    if ($oldversion < 2015062200) {

        // Define table booking_institutions to be created.
        $table = new xmldb_table('booking_institutions');

        // Adding fields to table booking_institutions.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('name', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table booking_institutions.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for booking_institutions.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015062200, 'booking');
    }

    if ($oldversion < 2015092400) {

        // Define field disablebookingusers to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('disablebookingusers', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL,
                null, '0', 'btncancelname');

        // Conditionally launch add field disablebookingusers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015092400, 'booking');
    }

    if ($oldversion < 2015110500) {
        // Define field lblbooking to be added to booking.
        $table = new xmldb_table('booking');

        $field = new xmldb_field('lblbooking', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'showinapi');

        // Conditionally launch add field lblbooking.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lbllocation', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblbooking');

        // Conditionally launch add field lbllocation.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblinstitution', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lbllocation');

        // Conditionally launch add field lblinstitution.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblinstitution');

        // Conditionally launch add field lblname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsurname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblname');

        // Conditionally launch add field lblsurname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015110500, 'booking');
    }

    if ($oldversion < 2015110600) {

        // Define field btncancelname to be dropped from booking_options.
        $table = new xmldb_table('booking_options');

        $field = new xmldb_field('btncacname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('lblteachname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('lblsputtname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('btnbooknowname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('btncancelname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table = new xmldb_table('booking');

        $field = new xmldb_field('btncacname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblsurname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblteachname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'btncacname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsputtname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblteachname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btnbooknowname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblsputtname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btncancelname', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'btnbooknowname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015110600, 'booking');
    }

    if ($oldversion < 2015122100) {

        // Define field conectedoption to be dropped from booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('conectedoption');

        // Conditionally launch drop field conectedoption.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015122100, 'booking');
    }

    if ($oldversion < 2015122101) {

        // Define field frombookingid to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('frombookingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL,
                null, '0', 'waitinglist');

        // Conditionally launch add field frombookingid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015122101, 'booking');
    }

    if ($oldversion < 2016011200) {

        // Define field booktootherbooking to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('booktootherbooking', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'btncancelname');

        // Conditionally launch add field booktootherbooking.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016011200, 'booking');
    }

    if ($oldversion < 2016011800) {

        // Define table booking_other to be created.
        $table = new xmldb_table('booking_other');

        // Adding fields to table booking_other.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('otheroptionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null,
                null);
        $table->add_field('limit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table booking_other.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Adding indexes to table booking_other.
        $table->add_index('optionid', XMLDB_INDEX_UNIQUE, array('optionid', 'otheroptionid'));

        // Conditionally launch create table for booking_other.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016011800, 'booking');
    }

    if ($oldversion < 2016011901) {

        // Define table booking_other to be dropped.
        $table = new xmldb_table('booking_other');

        // Conditionally launch drop table for booking_other.
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Define table booking_other to be created.
        $table = new xmldb_table('booking_other');

        // Adding fields to table booking_other.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('otheroptionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null,
                null);
        $table->add_field('userslimit', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table booking_other.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Adding indexes to table booking_other.
        $table->add_index('optionid', XMLDB_INDEX_NOTUNIQUE, array('optionid', 'otheroptionid'));

        // Conditionally launch create table for booking_other.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016011901, 'booking');
    }

    if ($oldversion < 2016021100) {

        // Define field lblacceptingfrom to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('lblacceptingfrom', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'booktootherbooking');

        // Conditionally launch add field lblacceptingfrom.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblnumofusers', XMLDB_TYPE_CHAR, '64', null, null, null, null,
                'lblacceptingfrom');

        // Conditionally launch add field lblnumofusers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016021100, 'booking');
    }

    if ($oldversion < 2016041500) {

        // Define field numgenerator to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('numgenerator', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null,
                '0', 'lblnumofusers');

        // Conditionally launch add field numgenerator.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016041500, 'booking');
    }

    if ($oldversion < 2016041501) {

        // Define field numrec to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('numrec', XMLDB_TYPE_INTEGER, '11', null, XMLDB_NOTNULL, null, '0',
                'frombookingid');

        // Conditionally launch add field numrec.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016041501, 'booking');
    }

    if ($oldversion < 2016041502) {

        // Define field paginationnum to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('paginationnum', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null,
                '25', 'numgenerator');

        // Conditionally launch add field paginationnum.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016041502, 'booking');
    }

    if ($oldversion < 2016051201) {

        // Define index courseid (not unique) to be added to booking_tags.
        $table = new xmldb_table('booking_tags');
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, array('courseid'));

        // Conditionally launch add index courseid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016051201, 'booking');
    }

    if ($oldversion < 2016051703) {

        // Course ids from all courses with booking instance.
        $courseids = $DB->get_fieldset_sql('SELECT DISTINCT course FROM {booking}', array());

        foreach ($courseids as $courseid) {

            // Delete all records made by now deleted users from booking_answers.
            $deletedusers = $DB->get_fieldset_select('user', 'id', " deleted = 1");
            list($insql, $params) = $DB->get_in_or_equal($deletedusers);
            $DB->delete_records_select('booking_answers',
                    " userid $insql AND bookingid IN ( SELECT id FROM {booking} WHERE course = $courseid)",
                    $params);

            $guestenrol = false;
            $enrolmethods = enrol_get_instances($courseid, true);
            foreach ($enrolmethods as $method) {
                if ('guest' == $method->enrol) {
                    $guestenrol = true;
                    break;
                }
            }
            if (!$guestenrol) {
                continue;
            }

            // Delete unenrolled and deleted users from booking_answers. This is done via events in the future.
            $coursecontext = context_course::instance($courseid);
            list($enrolsql, $enrolparams) = get_enrolled_sql($coursecontext);
            $params = array_merge(array('course' => $courseid), $enrolparams);
            $DB->delete_records_select('booking_answers',
                    ' userid NOT IN (' . $enrolsql .
                    ') AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course)',
                    $params);
            $DB->delete_records_select('booking_teachers',
                    ' userid NOT IN (' . $enrolsql .
                    ') AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course)',
                    $params);
        }

        upgrade_mod_savepoint(true, 2016051703, 'booking');
    }

    if ($oldversion < 2016053000) {

        // Define field banusernames to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('banusernames', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'paginationnum');

        // Conditionally launch add field banusernames.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016053000, 'booking');
    }

    if ($oldversion < 2016053100) {

        // Define field showhelpfullnavigationlinks to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showhelpfullnavigationlinks', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1', 'banusernames');

        // Conditionally launch add field showhelpfullnavigationlinks.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016053100, 'booking');
    }

    if ($oldversion < 2016061500) {

        // Define field daystonotify to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('daystonotify', XMLDB_TYPE_INTEGER, '3', null, null, null, '0',
                'showhelpfullnavigationlinks');

        // Conditionally launch add field daystonotify.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field daystonotify to be dropped from booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('daystonotify');

        // Conditionally launch drop field daystonotify.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Define field notifyemail to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('notifyemail', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'daystonotify');

        // Conditionally launch add field notifyemail.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016061500, 'booking');
    }

    if ($oldversion < 2016061501) {

        // Define table booking_optiondates to be created.
        $table = new xmldb_table('booking_optiondates');

        // Adding fields to table booking_optiondates.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('bookingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('coursestarttime', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('courseendtime', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Adding keys to table booking_optiondates.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Adding indexes to table booking_optiondates.
        $table->add_index('optionid', XMLDB_INDEX_NOTUNIQUE, array('optionid'));

        // Conditionally launch create table for booking_optiondates.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016061501, 'booking');
    }

    if ($oldversion < 2016062400) {

        // Define fields to be added to booking.
        $table = new xmldb_table('booking');

        $field = new xmldb_field('assessed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null,
                '0', 'notifyemail');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('assesstimestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL,
                null, '0', 'assessed');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('assesstimefinish', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL,
                null, '0', 'assesstimestart');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('scale', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'assesstimefinish');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016062400, 'booking');
    }

    if ($oldversion < 2016122300) {

        // Define field whichview to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('whichview', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null,
                'showactive', 'scale');

        // Conditionally launch add field whichview.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016122300, 'booking');
    }
    if ($oldversion < 2017021000) {

        $sql = "SELECT tc.id, tc.stringid
        FROM {tool_customlang} tc
        LEFT JOIN {tool_customlang_components} tcc ON tcc.id = tc.componentid
        WHERE tcc.name = 'mod_booking'";
        $langstrings = $DB->get_records_sql($sql);
        foreach ($langstrings as $langstring) {
            $langstring->stringid = strtolower($langstring->stringid);
            $DB->update_record('tool_customlang', $langstring, true);
        }
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017021000, 'booking');
    }
    if ($oldversion < 2017040600) {
        // Define table to be created.
        $table = new xmldb_table('booking_customfields');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('bookingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cfgname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value', XMLDB_TYPE_TEXT, 'medium', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table booking_other.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('bookingid', XMLDB_KEY_FOREIGN, array('bookingid'), 'booking', array('id'));
        $table->add_key('optionid', XMLDB_KEY_FOREIGN, array('optionid'), 'booking_options',
                array('id'));

        // Conditionally launch create table for booking_customfields.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017040600, 'booking');
    }

    if ($oldversion < 2017081401) {
        // Define field daystonotify2 to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('daystonotify2', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null,
                '0', 'whichview');

        // Conditionally launch add field daystonotify2.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field sent2 to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('sent2', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0',
                'disablebookingusers');

        // Conditionally launch add field sent2.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field status to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'numrec');

        // Conditionally launch add field status.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field enablepresence to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('enablepresence', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL,
                null, '0', 'daystonotify2');

        // Conditionally launch add field enablepresence.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field completionmodule to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('completionmodule', XMLDB_TYPE_INTEGER, '20', null, null, null,
                '-1', 'enablepresence');

        // Conditionally launch add field completionmodule.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017081401, 'booking');
    }

    if ($oldversion < 2017082303) {

        // Changing type of field responsesfields on table booking to char.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('responsesfields', XMLDB_TYPE_CHAR, '1333', null, null, null,
                'completed,status,rating,numrec,fullname,timecreated,institution,waitinglist',
                'completionmodule');

        // Launch change of type for field responsesfields.
        $dbman->add_field($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017082303, 'booking');
    }

    if ($oldversion < 2017082305) {

        // Changing type of field responsesfields on table booking to char.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('reportfields', XMLDB_TYPE_CHAR, '1333', null, null, null,
                'booking,location,coursestarttime,courseendtime,firstname,lastname',
                'responsesfields');

        // Launch change of type for field responsesfields.
        $dbman->add_field($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017082305, 'booking');
    }

    if ($oldversion < 2017082500) {

        // Define field optionsfields to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('optionsfields', XMLDB_TYPE_CHAR, '1333', null, null, null,
                'text,coursestarttime,maxanswers', 'reportfields');

        // Conditionally launch add field optionsfields.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017082500, 'booking');
    }

    if ($oldversion < 2017082800) {

        // Define field beforebookedtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('beforebookedtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'optionsfields');

        // Conditionally launch add field beforebookedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field beforecompletedtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('beforecompletedtext', XMLDB_TYPE_TEXT, null, null, null, null,
                null, 'beforebookedtext');

        // Conditionally launch add field beforecompletedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field aftercompletedtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('aftercompletedtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'beforecompletedtext');

        // Conditionally launch add field aftercompletedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field beforebookedtext to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('beforebookedtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'sent2');

        // Conditionally launch add field beforebookedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field beforecompletedtext to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('beforecompletedtext', XMLDB_TYPE_TEXT, null, null, null, null,
                null, 'beforebookedtext');

        // Conditionally launch add field beforecompletedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field aftercompletedtext to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('aftercompletedtext', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'beforecompletedtext');

        // Conditionally launch add field aftercompletedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017082800, 'booking');
    }

    if ($oldversion < 2017090500) {

        // Define field signinsheetfields to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('signinsheetfields', XMLDB_TYPE_CHAR, '1333', null, null, null,
                'fullname,signature', 'aftercompletedtext');

        // Conditionally launch add field signinsheetfields.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017090500, 'booking');
    }

    if ($oldversion < 2017090600) {

        // Define field shorturl to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('shorturl', XMLDB_TYPE_CHAR, '1333', null, null, null, null,
                'aftercompletedtext');

        // Conditionally launch add field shorturl.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017090600, 'booking');
    }
    if ($oldversion < 2017091200) {

        // Define field comments to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('comments', XMLDB_TYPE_INTEGER, '2', null, null, null, '0',
                'signinsheetfields');

        // Conditionally launch add field comments.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017091200, 'booking');
    }
    if ($oldversion < 2017091400) {

        // Define table booking_ratings to be created.
        $table = new xmldb_table('booking_ratings');

        // Adding fields to table booking_ratings.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rate', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '1');

        // Adding keys to table booking_ratings.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('uniq', XMLDB_KEY_UNIQUE, array('userid', 'optionid'));

        // Conditionally launch create table for booking_ratings.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017091400, 'booking');
    }

    if ($oldversion < 2017091401) {

        // Define index optionid (not unique) to be added to booking_ratings.
        $table = new xmldb_table('booking_ratings');
        $index = new xmldb_index('optionid', XMLDB_INDEX_NOTUNIQUE, array('optionid'));

        // Conditionally launch add index optionid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017091401, 'booking');
    }

    if ($oldversion < 2017091402) {

        // Define field ratings to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('ratings', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0',
                'comments');

        // Conditionally launch add field ratings.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017091402, 'booking');
    }

    if ($oldversion < 2017112101) {
        $sql = 'SELECT MAX(id), cfgname, optionid, COUNT(*)
                  FROM {booking_customfields}
              GROUP BY optionid, cfgname
                HAVING COUNT(*) > 1';
        while ($records = $DB->get_records_sql($sql)) {
            if (!empty($records)) {
                foreach ($records as $id => $record) {
                    $DB->delete_records('booking_customfields', array('id' => $id));
                }
            }
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2017112101, 'booking');
    }

    if ($oldversion < 2018011100) {

        // Define field removeuseronunenrol to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('removeuseronunenrol', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'ratings');

        // Conditionally launch add field removeuseronunenrol.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018011100, 'booking');
    }

    if ($oldversion < 2018040600) {

        // Changing type of field institution on table booking_options to text.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('institution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'location');

        // Launch change of type for field institution.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018040600, 'booking');
    }

    if ($oldversion < 2018052101) {

        // Add field notes to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'status');

        // Conditionally launch add field notes.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018052101, 'booking');
    }

    if ($oldversion < 2018062100) {

        // Define field additionalfields to be removed from booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('additionalfields', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'pollurltext');
        // Conditionally launch drop field.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018062100, 'booking');
    }

    if ($oldversion < 2018071601) {
        // Define table booking_institutions to be created.
        $table = new xmldb_table('booking_institutions');

        // The field to change. Name ist changed from text to char.
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);

        // Change the field type.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018071601, 'booking');
    }

    if ($oldversion < 2018080701) {
        $ids = $DB->get_fieldset_sql('SELECT bo.id FROM {booking_options} bo WHERE bo.id > 0');
        if (!empty($ids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            if (count($ids) > 1) {
                $sql = "DELETE FROM {booking_teachers}
                    WHERE optionid NOT $insql";
                $DB->execute($sql, $inparams);
            } else if (count($ids) == 1) {
                $sql = "DELETE FROM {booking_teachers}
                    WHERE optionid !$insql";
                $DB->execute($sql, $inparams);
            }
        } else {
            $sql = "DELETE FROM {booking_teachers}";
            $DB->execute($sql);
        }
        upgrade_mod_savepoint(true, 2018080701, 'booking');
    }

    if ($oldversion < 2018090600) {

        // Define field duration to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('duration', XMLDB_TYPE_INTEGER, '11', null, XMLDB_NOTNULL, null, '0', 'shorturl');

        // Conditionally launch add field duration.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2018090600, 'booking');
    }

    if ($oldversion < 2019071400) {

        // Define field calendarid to be added to booking_teachers.
        $table = new xmldb_table('booking_teachers');
        $field = new xmldb_field('calendarid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'completed');

        // Conditionally launch add field calendarid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field teacherroleid to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('teacherroleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3', 'removeuseronunenrol');

        // Conditionally launch add field teacherroleid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019071400, 'booking');
    }

    if ($oldversion < 2019071700) {

        // Define field enrolmentstatus to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('enrolmentstatus', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '2', 'courseendtime');

        // Conditionally launch add field teacherroleid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field allowupdatedays to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('allowupdatedays', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'teacherroleid');

        // Conditionally launch add field allowupdatedays.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019071700, 'booking');
    }

    if ($oldversion < 2019071701) {

        // Change title of booking option to char.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('text', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'bookingid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019071701, 'booking');
    }

    if ($oldversion < 2019072900) {

        // Changing precision of field enablecompletion on table booking to (3).
        $table = new xmldb_table('booking');
        $field = new xmldb_field('enablecompletion', XMLDB_TYPE_INTEGER, '3', null, null, null, '1', 'userleave');

        // Launch change of precision for field enablecompletion.
        $dbman->change_field_precision($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019072900, 'booking');
    }

    if ($oldversion < 2019080101) {

        // Define field parentid to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('parentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duration');

        // Conditionally launch add field parentid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('parentid', XMLDB_INDEX_NOTUNIQUE, ['parentid']);

        // Conditionally launch add index parentid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019080101, 'booking');
    }

    if ($oldversion < 2019080300) {

        // Drop unused fields.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('maxoverbooking', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'maxanswers');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('maxanswers', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'limitanswers');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('limitanswers', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'timeclose');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019080300, 'booking');
    }

    if ($oldversion < 2019080303) {
        // Add field for default template used for booking options of the booking instance.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('templateid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'allowupdatedays');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table->add_index('templateid', XMLDB_INDEX_NOTUNIQUE, array('templateid'));
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019080303, 'booking');
    }

    if ($oldversion < 2019092601) {
        // Add field for default template used for booking options of the booking instance.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('defaultoptionsort', XMLDB_TYPE_CHAR, '255', null, null, null, 'text', 'templateid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showviews', XMLDB_TYPE_CHAR, '255', null, null, null,
            'mybooking,myoptions,showall,showactive,myinstitution', 'defaultoptionsort');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('responsesfields', XMLDB_TYPE_TEXT, 'small', null, null, null, null, 'completionmodule');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }
        $field = new xmldb_field('reportfields', XMLDB_TYPE_TEXT, 'small', null, null, null, null, 'responsesfields');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }
        $field = new xmldb_field('optionsfields', XMLDB_TYPE_TEXT, 'small', null, null, null, null, 'reportfields');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }
        $field = new xmldb_field('signinsheetfields', XMLDB_TYPE_TEXT, 'small', null, null, null,
            null, 'aftercompletedtext');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }
        // Add field for views to show in view.php.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showviews', XMLDB_TYPE_CHAR, '255', null, null, null,
            'mybooking,myoptions,showall,showactive,myinstitution', 'defaultoptionsort');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2019092601, 'booking');
    }

    if ($oldversion < 2020071300) {

        // Define field autcractive to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('customtemplateid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'showviews');

        // Conditionally launch add field autcractive.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field autcractive to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('autcractive', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'customtemplateid');

        // Conditionally launch add field autcractive.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

         $field = new xmldb_field('autcrprofile', XMLDB_TYPE_CHAR, '264', null, null, null, null, 'autcractive');

        // Conditionally launch add field autcrprofile.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('autcrvalue', XMLDB_TYPE_CHAR, '264', null, null, null, null, 'autcrprofile');

        // Conditionally launch add field autcrvalue.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('autcrtemplate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'autcrvalue');

        // Conditionally launch add field autcrtemplate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2020071300, 'booking');
    }

    if ($oldversion < 2020082601) {
        // Define field to be renamed.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('customteplateid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'showviews');

        // Conditionally launch renaming the field.
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'customtemplateid');
        }

        // Define table booking_instancetemplate to be created.
        $table = new xmldb_table('booking_instancetemplate');

        // Adding fields to table booking_instancetemplate.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('template', XMLDB_TYPE_BINARY, null, null, XMLDB_NOTNULL, null, null);

        // Adding keys to table booking_instancetemplate.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for booking_instancetemplate.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table booking_customreport to be created.
        $table = new xmldb_table('booking_customreport');

        // Adding fields to table booking_customreport.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table booking_customreport.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course', XMLDB_INDEX_NOTUNIQUE, ['course']);

        // Conditionally launch create table for booking_customreport.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2020082601, 'booking');
    }

    if ($oldversion < 2021051901) {
        // Define field optiondateid and its foreign key to be added to booking_customfields.
        $table = new xmldb_table('booking_customfields');
        $field = new xmldb_field('optiondateid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'optionid');
        $key = new xmldb_key('optiondateid', XMLDB_KEY_FOREIGN, ['optiondateid'], 'booking_optiondates', ['id']);

        // Conditionally launch add field optiondateid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            // Launch add key optiondateid.
            $dbman->add_key($table, $key);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021051901, 'booking');
    }

    //  If is Elective.
    if ($oldversion < 2021060200) {
        // Add field consecutive to instance.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('iselective', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field iselecitve.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('consumeatonce', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field consumeatonce.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('maxcredits', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field maxcredits.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('enforceorder', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field enforceorder.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add field credits to booking options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('credits', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field optiondateid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add booking combinations table:
        $table = new xmldb_table('booking_combinations');
        // Adding fields to table booking_instancetemplate.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('otheroptionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('othercourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cancombine', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table booking_instancetemplate.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for booking_instancetemplate.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021060200, 'booking');
    }

    if ($oldversion < 2021052700) {

        // Define field eventid to be added to booking_optiondates.
        $table = new xmldb_table('booking_optiondates');
        $field = new xmldb_field('eventid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'optionid');
        $key = new xmldb_key('fk_eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'event', ['id']);

        // Conditionally launch add field eventid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            // Launch add key fk_eventid.
            $dbman->add_key($table, $key);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021052700, 'booking');
    }

    if ($oldversion < 2021062100) {

        // Define table booking_category to be created.
        $table = new xmldb_table('booking_icalsequence');

        // Adding fields to table booking_category.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sequencevalue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table booking_category.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for booking_category.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021062100, 'booking');
    }
    if ($oldversion < 2021062200) {
        // Define table booking_optiondates to be created.
        $table = new xmldb_table('booking_optiondates');

        // Adding fields to table booking_optiondates.
        $daystonotify = new xmldb_field('daystonotify', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'courseendtime');
        $sent = new xmldb_field('sent', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'daystonotify');

        // Conditionally launch add field daystonotify.
        if (!$dbman->field_exists($table, $daystonotify)) {
            $dbman->add_field($table, $daystonotify);
        }
        // Conditionally launch add field sent.
        if (!$dbman->field_exists($table, $sent)) {
            $dbman->add_field($table, $sent);
        }
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021062200, 'booking');
    }
  
    if ($oldversion < 2021062500) {
        // Define table booking_optiondates to be created.
        $table = new xmldb_table('booking_userevents');

        // Adding fields to table booking_category.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('optiondateid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Adding keys to table booking_category.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for booking_category.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021062500, 'booking');
    }

    if ($oldversion < 2021080400) {

        // Define field mailtemplatessource to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('mailtemplatessource', XMLDB_TYPE_INTEGER, '1', null,
            null, null, '0', 'bookingmanager');

        // Conditionally launch add field mailtemplatessource.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021080400, 'booking');
    }

    if ($oldversion < 2021080900) {

        // Define fields daystonotifyteachers, notifyemailteachers to be added to booking.
        $table = new xmldb_table('booking');

        // Conditionally launch add field daystonotifyteachers.
        $field1 = new xmldb_field('daystonotifyteachers', XMLDB_TYPE_INTEGER, '3', null,
            null, null, '0', 'notifyemail');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        // Conditionally launch add field notifyemailteachers.
        $field2 = new xmldb_field('notifyemailteachers', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'daystonotifyteachers');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021080900, 'booking');
    }

    if ($oldversion < 2021080901) {

        // Define field sentteachers to be added to booking_options.
        $table = new xmldb_table('booking_options');

        $field = new xmldb_field('sentteachers', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'sent2');

        // Conditionally launch add field sentteachers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021080901, 'booking');
    }

    if ($oldversion < 2021121703) {

        $table = new xmldb_table('booking_prices');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'id');
        $table->add_field('pricecategoryidentifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'optionid');
        $table->add_field('price', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, '0', 'pricecategoryidentifier');
        $table->add_field('currency', XMLDB_TYPE_CHAR, '10', null, null, null, '', 'price');

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2021121703, 'booking');
    }

    if ($oldversion < 2022012607) {

        // Add new table.
        $table = new xmldb_table('booking_pricecategories');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('ordernum', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'id');
        $table->add_field('identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'ordernum');
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'identifier');
        $table->add_field('defaultvalue', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, '0', 'name');
        $table->add_field('disabled', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'defaultvalue');

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022012607, 'booking');
    }

    if ($oldversion < 2022030100) {

        // Add new table.
        $table = new xmldb_table('booking_semesters');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'identifier');
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'name');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'startdate');

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022030100, 'booking');
    }

    if ($oldversion < 2022030901) {

        // Define fields to be added to booking_options.
        $table = new xmldb_table('booking_options');

        // Fix #190 - https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/190.
        $parentid = new xmldb_field('parentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duration');
        if (!$dbman->field_exists($table, $parentid)) {
            $dbman->add_field($table, $parentid);
        }
        $index = new xmldb_index('parentid', XMLDB_INDEX_NOTUNIQUE, ['parentid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // End of fix #190.

        $semesterid = new xmldb_field('semesterid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'parentid');
        $dayofweektime = new xmldb_field('dayofweektime', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'semesterid');

        // Conditionally launch add field semesterid.
        if (!$dbman->field_exists($table, $semesterid)) {
            $dbman->add_field($table, $semesterid);
        }

        // Conditionally launch add field dayofweektime.
        if (!$dbman->field_exists($table, $dayofweektime)) {
            $dbman->add_field($table, $dayofweektime);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022030901, 'booking');
    }

    if ($oldversion < 2022042100) {

        // Add new table.
        $table = new xmldb_table('booking_holidays');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('semesteridentifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'semesteridentifier');
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'name');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'startdate');

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('fk_eventid', XMLDB_KEY_FOREIGN, ['semesteridentifier'], 'booking_semesters', ['identifier']);

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022042100, 'booking');
    }

    if ($oldversion < 2022042900) {

        // Define field invisible to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('invisible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'dayofweektime');

        // Conditionally launch add field invisible.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022042900, 'booking');
    }

    if ($oldversion < 2022050502) {

        // Define table booking_optiondates_teachers to be created.
        $table = new xmldb_table('booking_optiondates_teachers');

        // Adding fields to table booking_optiondates_teachers.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('optiondateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table booking_optiondates_teachers.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_optiondateid', XMLDB_KEY_FOREIGN, ['optiondateid'], 'booking_optiondates', ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Conditionally launch create table for booking_optiondates_teachers.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022050502, 'booking');
    }

    if ($oldversion < 2022051200) {
        // Add new table.
        $table = new xmldb_table('booking_holidays');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);
        $table->add_field('semesteridentifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'semesteridentifier');
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'name');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'startdate');

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('fk_eventid', XMLDB_KEY_FOREIGN, ['semesteridentifier'], 'booking_semesters', ['identifier']);

        // Conditionally launch create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022051200, 'booking');
    }

    if ($oldversion < 2022060900) {

        // Define table booking_optionformconfig to be created.
        $table = new xmldb_table('booking_optionformconfig');

        // Adding fields to table booking_optionformconfig.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('elementname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');

        // Adding keys to table booking_optionformconfig.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for booking_optionformconfig.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022060900, 'booking');
    }

    if ($oldversion < 2022062700) {

        // Define field annotation to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('annotation', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'invisible');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022062700, 'booking');
    }

    if ($oldversion < 2022062800) {

        // Define field identifier to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $identifier = new xmldb_field('identifier', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'annotation');
        if (!$dbman->field_exists($table, $identifier)) {
            $dbman->add_field($table, $identifier);
        }

        $titleprefix = new xmldb_field('titleprefix', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'identifier');
        if (!$dbman->field_exists($table, $titleprefix)) {
            $dbman->add_field($table, $titleprefix);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022062800, 'booking');
    }

    if ($oldversion < 2022070100) {

        $table = new xmldb_table('booking_options');

        $priceformulaadd = new xmldb_field('priceformulaadd', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, '0', 'titleprefix');
        if (!$dbman->field_exists($table, $priceformulaadd)) {
            $dbman->add_field($table, $priceformulaadd);
        }

        $priceformulamultiply = new xmldb_field('priceformulamultiply', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, '1',
            'priceformulaadd');
        if (!$dbman->field_exists($table, $priceformulamultiply)) {
            $dbman->add_field($table, $priceformulamultiply);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022070100, 'booking');
    }

    if ($oldversion < 2022070400) {

        // Define field semesterid to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('semesterid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'autcrtemplate');

        // Conditionally launch add field semesterid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022070400, 'booking');
    }

    if ($oldversion < 2022071100) {

        // Define field invisible to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('dayofweek', XMLDB_TYPE_CHAR, '255', null, null, null, '', 'priceformulamultiply');

        // Conditionally launch add field invisible.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022071100, 'booking');
    }
    if ($oldversion < 2022081600) {

        // Define field bookingchangedtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('bookingchangedtext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'deletedtext');

        // Conditionally launch add field bookingchangedtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }

    if ($oldversion < 2022081600) {

        // Define field showdescriptionmode to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showdescriptionmode', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'templateid');

        // Conditionally launch add field showdescriptionmode.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }

    if ($oldversion < 2022081600) {

        // Define field showlistoncoursepage to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('showlistoncoursepage', XMLDB_TYPE_INTEGER, '1', null, null, null, '1', 'showdescriptionmode');

        // Conditionally launch add field showlistoncoursepage.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }

    if ($oldversion < 2022081600) {

        // Define field coursepageshortinfo to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('coursepageshortinfo', XMLDB_TYPE_TEXT, null, null, null, null, null, 'showlistoncoursepage');

        // Conditionally launch add field coursepageshortinfo.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }

    if ($oldversion < 2022081600) {

        // Define field activitycompletiontext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('activitycompletiontext', XMLDB_TYPE_TEXT, null, null, null, null, null,
            'pollurlteacherstext');

        // Conditionally launch add field activitycompletiontext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }

    if ($oldversion < 2022081600) {

        // Define field bookingimagescustomfield to be added to table booking.
        $table = new xmldb_table('booking');

        $field = new xmldb_field('bookingimagescustomfield', XMLDB_TYPE_INTEGER, '10', null,
            null, null, '0', 'coursepageshortinfo');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2022081600, 'booking');
    }
    return true;
}
