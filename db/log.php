<?php

/**
 * Definition of log events
 *
 * @package    mod
 * @subpackage booking
 * @copyright  2010 David Bogner (http://edulabs.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    array('module'=>'booking', 'action'=>'view', 'mtable'=>'booking', 'field'=>'name'),
    array('module'=>'booking', 'action'=>'update', 'mtable'=>'booking', 'field'=>'name'),
    array('module'=>'booking', 'action'=>'add', 'mtable'=>'booking', 'field'=>'name'),
    array('module'=>'booking', 'action'=>'report', 'mtable'=>'booking', 'field'=>'name'),
    array('module'=>'booking', 'action'=>'choose', 'mtable'=>'booking', 'field'=>'name'),
    array('module'=>'booking', 'action'=>'choose again', 'mtable'=>'booking', 'field'=>'name'),
);