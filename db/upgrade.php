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
    
    if ($oldversion < 2014020200) {
    
     // Define field addtocalendar to be added to booking_options.
     $table = new xmldb_table('booking');
     $field = new xmldb_field('sendmailtobooker', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'maxperuser');
    
     // Conditionally launch add field sendmailtobooker.
     if (!$dbman->field_exists($table, $field)) {
      $dbman->add_field($table, $field);
     }
    
     // Booking savepoint reached.
     upgrade_mod_savepoint(true, 2014020200, 'booking');
    }

    if ($oldversion < 2014030600) {
        
        $table = new xmldb_table('booking');
        $tableoptions = new xmldb_table('booking_options');
        
        $field = new xmldb_field('duration', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'maxperuser');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('points', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'duration');
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
    
    if ($oldversion < 2014051200) {

        // Changing type of field points on table booking to number.
        $table = new xmldb_table('booking');
        $field = new xmldb_field('points', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'duration');
        // Launch change of type for field points.
        $dbman->change_field_type($table, $field);
        // Booking savepoint reached.
        upgrade_mod_savepoint(true, 2014051200, 'booking');
    }
    return true;
}

?>
