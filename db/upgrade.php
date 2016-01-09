<?php

// This file keeps track of upgrades to 
// the booking module
//
// Sometimes, changes between versions involve
// alterations to database structures and other
// major things that may break installations.
//
// The upgrade function in this file will attempt
// to perform all the necessary actions to upgrade
// your older installtion to the current version.
//
// If there's something it cannot do itself, it
// will tell you what you need to do.
//
// The commands in here will all be database-neutral,
// using the functions defined in lib/ddllib.php

function xmldb_booking_upgrade($oldversion) {

    global $CFG, $DB;

    $dbman = $DB->get_manager(); /// loads ddl manager and xmldb classes

    if ($oldversion < 2011020401) {

        /// Rename field text on table booking to text
        $table = new xmldb_table('booking');
        $field = new xmldb_field('text', XMLDB_TYPE_TEXT, 'small', null, XMLDB_NOTNULL, null, null, 'name');

        /// Launch rename field text
        $dbman->rename_field($table, $field, 'intro');

        /// booking savepoint reached
        upgrade_mod_savepoint(true, 2009042000, 'booking');
    }

    if ($oldversion < 2011020401) {

        /// Rename field format on table booking to format
        $table = new xmldb_table('booking');
        $field = new xmldb_field('format', XMLDB_TYPE_INTEGER, '4', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'intro');

        /// Launch rename field format
        $dbman->rename_field($table, $field, 'introformat');

        /// booking savepoint reached
        upgrade_mod_savepoint(true, 2009042001, 'booking');

        // Define field bookingpolicyformat to be added to choice
        $table = new xmldb_table('booking');
        $field = new xmldb_field('bookingpolicyformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'bookingpolicy');

        // Conditionally launch add field completionsubmit
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // choice savepoint reached
        upgrade_mod_savepoint(true, 2010101300, 'booking');
    }
    if ($oldversion < 2011020403) {
        // Define field bookingpolicyformat to be added to choice
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('descriptionformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'description');

        // Conditionally launch add field completionsubmit
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    if ($oldversion < 2012091601) {
        // Define field autoenrol to be added to booking
        $table = new xmldb_table('booking');
        $field = new xmldb_field('autoenrol', XMLDB_TYPE_INTEGER, '4', null, null, null, '0', 'timemodified');

        // Conditionally launch add field autoenrol
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // booking savepoint reached
        upgrade_mod_savepoint(true, 2012091601, 'booking');
    }

    // Add fields to store custom email message content
    if ($oldversion < 2012091602) {
        $table = new xmldb_table('booking');

        $field = new xmldb_field('bookedtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'autoenrol');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('waitingtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'bookedtext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('statuschangetext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'waitingtext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('deletedtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'statuschangetext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // booking savepoint reached
        upgrade_mod_savepoint(true, 2012091602, 'booking');
    }

    if ($oldversion < 2012091603) {
        // Define field maxperuser to be added to booking
        $table = new xmldb_table('booking');
        $field = new xmldb_field('maxperuser', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'deletedtext');

        // Conditionally launch add field maxperuser
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // booking savepoint reached
        upgrade_mod_savepoint(true, 2012091603, 'booking');
    }

    if ($oldversion < 2014012807) {

        // Define field addtocalendar to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('addtocalendar', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'timemodified');

        // Conditionally launch add field addtocalendar.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('calendarid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'addtocalendar');

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

        $field = new xmldb_field('duration', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'maxperuser');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('points', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'duration');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('organizatorname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'points');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'organizatorname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'calendarid');
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
        $field = new xmldb_field('addtogroup', XMLDB_TYPE_INTEGER, '4', null, null, null, '0', 'pollurl');

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
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '11', null, null, null, null, 'pollurl');

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
        $field = new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'addtogroup');

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
        $field = new xmldb_field('pollurltext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'categoryid');

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
        $table->add_key('optionid', XMLDB_KEY_FOREIGN, array('optionid'), 'booking_options', array('id'));

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
        $field = new xmldb_field('additionalfields', XMLDB_TYPE_TEXT, null, null, null, null, null, 'pollurltext');

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
        $field = new xmldb_field('daystonotify', XMLDB_TYPE_INTEGER, '3', null, null, null, '0', 'groupid');

        // Conditionally launch add field daystonotify.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sent', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'daystonotify');

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

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'organizatorname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurl');
        }

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'organizatorname');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurl');
        }

        $field = new xmldb_field('poolurltext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'categoryid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'pollurltext');
        }

        $field = new xmldb_field('poolurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'calendarid');
        if ($dbman->field_exists($tableoptions, $field)) {
            $dbman->rename_field($tableoptions, $field, 'pollurl');
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032600, 'booking');
    }

    if ($oldversion < 2014032800) {

        // Changing type of field categoryid on table booking to text.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('categoryid', XMLDB_TYPE_TEXT, null, null, null, null, null, 'addtogroup');

        // Launch change of type for field categoryid.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014032800, 'booking');
    }

    if ($oldversion < 2014033100) {

        // Define field location to be added to booking_options.
        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sent');

        // Conditionally launch add field location.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('institution', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'location');

        // Conditionally launch add field institution.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('address', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'institution');

        // Conditionally launch add field address.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014033100, 'booking');
    }

    if ($oldversion < 2014033101) {

        // Changing the default of field eventtype on table booking to Booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '255', null, null, null, 'Booking', 'additionalfields');

        // Launch change of default for field eventtype.
        $dbman->change_field_default($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014033101, 'booking');
    }

    if ($oldversion < 2014040700) {

        // Changing type of field points on table booking to number.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('points', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'duration');

        // Launch change of type for field points.
        $dbman->change_field_type($table, $field);

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014040700, 'booking');
    }

    if ($oldversion < 2014091600) {

        $table = new xmldb_table('booking');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'additionalfields');

        // Conditionally launch add field eventtype.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field notificationtext to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'eventtype');

        // Conditionally launch add field notificationtext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Define field completed to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'timemodified');

        // Conditionally launch add field completed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field userleave to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('userleave', XMLDB_TYPE_TEXT, null, null, null, null, null, 'notificationtext');

        // Conditionally launch add field userleave.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field enablecompletion to be added to booking.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('enablecompletion', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'userleave');

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
        $field = new xmldb_field('pollurlteachers', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'enablecompletion');

        // Conditionally launch add field pollurlteachers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('pollurlteacherstext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'pollurlteachers');

        // Conditionally launch add field pollurlteacherstext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('booking_options');
        $field = new xmldb_field('pollurlteachers', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'address');

        // Conditionally launch add field pollurlteachers.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }


        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014092901, 'booking');
    }

    if ($oldversion < 2014111800) {

        // Define field timecreated to be added to booking_answers.
        $table = new xmldb_table('booking_answers');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'completed');

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
        $field = new xmldb_field('sendmailtobooker', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'maxperuser');

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
        $field = new xmldb_field('waitinglist', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timecreated');

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
        $field = new xmldb_field('cancancelbook', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'pollurlteacherstext');

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
        $field = new xmldb_field('conectedbooking', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'cancancelbook');

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
        $field = new xmldb_field('conectedoption', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'pollurlteachers');

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
        $field = new xmldb_field('howmanyusers', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'conectedoption');

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
        $field = new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'optionid');

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
        $field = new xmldb_field('showinapi', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'conectedbooking');

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
        $field = new xmldb_field('pollsend', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'howmanyusers');

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
        $field = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null, null);

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
        $field = new xmldb_field('removeafterminutes', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0', 'pollsend');

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
        $field = new xmldb_field('btncacname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'removeafterminutes');

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
        $field = new xmldb_field('lblteachname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'btncacname');

        // Conditionally launch add field lblteachname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsputtname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'lblteachname');

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
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lblsputtname');

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
        $field = new xmldb_field('notificationtextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'notificationtext');

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
        $field = new xmldb_field('btnbooknowname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'notificationtextformat');

        // Conditionally launch add field btnbooknowname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btncancelname', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null, 'btnbooknowname');

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
        $field = new xmldb_field('disablebookingusers', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'btncancelname');

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

        $field = new xmldb_field('lblbooking', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'showinapi');

        // Conditionally launch add field lblbooking.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lbllocation', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblbooking');

        // Conditionally launch add field lbllocation.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblinstitution', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lbllocation');

        // Conditionally launch add field lblinstitution.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblinstitution');

        // Conditionally launch add field lblname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsurname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblname');

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

        $field = new xmldb_field('btncacname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblsurname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblteachname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'btncacname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lblsputtname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblteachname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btnbooknowname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lblsputtname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('btncancelname', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'btnbooknowname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015110600, 'booking');
    }

    if ($oldversion < 2015120102) {
        // Setting for show date and time or only date if the time ist out of the booking time in booking and booking_option.         
        $table = new xmldb_table('booking');

        $field = new xmldb_field('showdatetime', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timeclose');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        
        $table = new xmldb_table('booking_options');
        
        $field = new xmldb_field('bookingopeningtime', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'maxoverbooking');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('showdatetime', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'bookingclosingtime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
         // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2015120102, 'booking');
    }
    
     if ($oldversion < 2016010500) {

        $table = new xmldb_table('booking_options');
        
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'removeafterminutes');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'completiontext');
        }
        
        // Rename notifcationtextformat to completiontextformat
        $field = new xmldb_field('notificationtextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'notificationtext');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'completiontextformat');
        }
                
        // Add field notificationoption
        $field = new xmldb_field('notificationoption', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'daystonotify');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    
        // Add field notificationtext
        $field = new xmldb_field('notificationtext', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'notificationoption');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add field notificationtextformat.
        $field = new xmldb_field('notificationtextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'notificationtext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);  
        }
        
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2016011600, 'booking');
    }
    
    return true;
}

?>
