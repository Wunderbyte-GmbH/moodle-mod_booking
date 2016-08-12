<?php

////////////////////////////////////////////////////////////////////////////////
//  Code fragment to define the plugin version etc.
//  This fragment is called by /admin/index.php
////////////////////////////////////////////////////////////////////////////////

/**
 * @package mod_booking
 * @copyright 2012,2013,2014,2015, 2016 David Bogner <info@edulabs.org>, Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$plugin->version =  2016081201;
$plugin->requires = 2014051200;  // Requires this Moodle 2.X version
$plugin->release = 'Eva Thörnblad 1.7'; // famous female characters: Diane Selwyn, Eva Thörnblad,
$plugin->maturity = MATURITY_STABLE;
$plugin->cron = 5;
$plugin->component = 'mod_booking';
