<?php

////////////////////////////////////////////////////////////////////////////////
//  Code fragment to define the plugin version etc.
//  This fragment is called by /admin/index.php
////////////////////////////////////////////////////////////////////////////////

/**
 * @package mod_booking
 * @copyright 2012,2013,2014,2015 David Bogner <info@edulabs.org>, Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$plugin->version = 2016051600;
$plugin->requires = 2013111800;  // Requires this Moodle 2.X version
$plugin->release = '2.7';
$plugin->maturity = MATURITY_STABLE;
$plugin->cron = 5;
$plugin->component = 'mod_booking';
