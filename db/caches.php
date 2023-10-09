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
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackbookinginstances'],
    ],
    'cachedprices' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackprices'],
    ],
    'cachedpricecategories' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackpricecategories'],
    ],
    'cachedsemesters' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbacksemesters'],
    ],
    'cachedteachersjournal' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackcachedteachersjournal'],
    ],
    'bookingoptionstable' => [ // This cache uses hased sql queries as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackoptionstable'],
    ],
    'bookingoptionsettings' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackoptionsettings'],
    ],
    'bookingoptionsanswers' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackoptionsanswers'],
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
    'confirmbooking' => [ // This cache uses optionids as keys.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'invalidationevents' => ['setbackconfirms'],
    ]
    ,
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
];
