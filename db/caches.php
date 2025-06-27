<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Mobile cache definitions.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wudnerbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$definitions = [
    'cachedbookinginstances' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'invalidationevents' => ['setbackbookinginstances'],
    ],
    'cachedprices' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
        'invalidationevents' => ['setbackprices'],
    ],
    'cachedpricecategories' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'invalidationevents' => ['setbackpricecategories'],
    ],
    'cachedsemesters' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'invalidationevents' => ['setbacksemesters'],
    ],
    'cachedteachersjournal' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'invalidationevents' => ['setbackcachedteachersjournal'],
    ],
    'bookingoptionstable' => [ // This cache uses hashed sql queries as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
        'invalidationevents' => ['setbackoptionstable', 'setbackencodedtables'],
    ],
    'mybookingoptionstable' => [ // This cache uses hashed sql queries as keys. We destroy it when a user has booked.
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
        'invalidationevents' => [
            'setbackmyoptionstable',
            'setbackmyencodedtables',
            'setbackoptionstable',
            'setbackencodedtables',
        ],
    ],
    'bookingoptionsettings' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
        'invalidationevents' => ['setbackoptionsettings'],
    ],
    'bookingoptionsanswers' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
        'invalidationevents' => ['setbackoptionsanswers'],
    ],
    'bookinganswers' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
        'invalidationevents' => [
            'setbackoptionsanswers',
            'setbacksessionanswers',
        ],
    ],
    'bookedusertable' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
        'invalidationevents' => ['setbackbookedusertable'],
    ],
    'subbookingforms' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbacksubbookingforms'],
    ],
    'conditionforms' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackconditionforms'],
    ],
    // The cache is used to confirm bookings.
    'confirmbooking' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackconfirms'],
    ],
    'electivebookingorder' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackelectivelist'],
    ],
    'customformuserdata' => [ // We don't support general invalidation event in this cache. Use userid-optionid as keys.
        'mode' => cache_store::MODE_APPLICATION, // In order support buy for others, we need to make this MODE_APPLICATION cache.
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
    ],
    'eventlogtable' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackeventlogtable'],
    ],
    'bookinghistorytable' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackbookinghistorytable'],
    ],
    'bookforuser' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
    ],
];
