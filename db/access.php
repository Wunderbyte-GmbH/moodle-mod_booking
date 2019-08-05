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

/**
 * Booking module capability definition
 *
 * @package    mod_booking
 * @copyright  2009-2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'mod/booking:comment' => array('riskbitmask' => RISK_SPAM, 'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('student' => CAP_ALLOW, 'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW)),
    'mod/booking:managecomments' => array('riskbitmask' => RISK_SPAM, 'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW)),
    'mod/booking:choose' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('student' => CAP_ALLOW, 'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW)),
    'mod/booking:addeditownoption' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('editingteacher' => CAP_ALLOW)),
    'mod/booking:readresponses' => array('captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:deleteresponses' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:updatebooking' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:downloadresponses' => array('captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:subscribeusers' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:addinstance' => array('riskbitmask' => RISK_XSS, 'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array('editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW),
        'clonepermissionsfrom' => 'moodle/course:manageactivities'),
    'mod/booking:communicate' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW)),
    'mod/booking:viewrating' => array('captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('student' => CAP_ALLOW, 'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW)),
    'mod/booking:viewanyrating' => array('riskbitmask' => RISK_PERSONAL, 'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW)),
    'mod/booking:viewallratings' => array('riskbitmask' => RISK_PERSONAL, 'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW), 'clonepermissionsfrom' => 'mod/booking:viewanyrating'),
    'mod/booking:rate' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW)),
    'mod/booking:readallinstitutionusers' => array('riskbitmask' => RISK_PERSONAL,
        'captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW)),
    'mod/booking:manageoptiontemplates' => array('captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('manager' => CAP_ALLOW))
    );
