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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\booking_categories;
use stdClass;

/**
 * The right to manage booking categories is checked for one course. Deleting and saving then
 * only touch categories of this course, whatever ids the request names.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\booking_categories
 */
final class booking_categories_test extends advanced_testcase {
    /** @var stdClass[] The two courses of the test. */
    private array $courses = [];

    /** @var int[] Id of one category per course. */
    private array $categoryids = [];

    /**
     * Tests set up: two courses with a booking category each.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        foreach (['own', 'other'] as $name) {
            $this->courses[$name] = $this->getDataGenerator()->create_course();
            $this->categoryids[$name] = (int)$DB->insert_record('booking_category', (object)[
                'cid' => 0,
                'course' => $this->courses[$name]->id,
                'name' => "Category of the $name course",
            ]);
        }
    }

    /**
     * A category of another course is not deleted.
     */
    public function test_delete_is_limited_to_the_course(): void {
        global $DB;

        booking_categories::delete((int)$this->courses['own']->id, $this->categoryids['other']);
        $this->assertTrue($DB->record_exists('booking_category', ['id' => $this->categoryids['other']]));

        // A category that a booking instance uses stays.
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $this->courses['own']->id]);
        $DB->set_field('booking', 'categoryid', '999,' . $this->categoryids['own'], ['id' => $booking->id]);
        booking_categories::delete((int)$this->courses['own']->id, $this->categoryids['own']);
        $this->assertTrue($DB->record_exists('booking_category', ['id' => $this->categoryids['own']]));

        // An unused category of the course is deleted.
        $DB->set_field('booking', 'categoryid', '999', ['id' => $booking->id]);
        booking_categories::delete((int)$this->courses['own']->id, $this->categoryids['own']);
        $this->assertFalse($DB->record_exists('booking_category', ['id' => $this->categoryids['own']]));
    }

    /**
     * A category of another course is not changed, and a category cannot be moved to another course.
     */
    public function test_save_is_limited_to_the_course(): void {
        global $DB;

        $owncourseid = (int)$this->courses['own']->id;
        $othercourseid = (int)$this->courses['other']->id;

        // The form names a category of another course.
        $before = $DB->get_record('booking_category', ['id' => $this->categoryids['other']]);
        booking_categories::save($owncourseid, $this->categoryids['other'], (object)[
            'id' => $this->categoryids['other'],
            'name' => 'Changed',
            'cid' => 0,
            'course' => $owncourseid,
        ]);
        $this->assertEquals($before, $DB->get_record('booking_category', ['id' => $this->categoryids['other']]));

        // The form names another course for an own category.
        booking_categories::save($owncourseid, $this->categoryids['own'], (object)[
            'id' => $this->categoryids['own'],
            'name' => 'Renamed',
            'cid' => 0,
            'course' => $othercourseid,
        ]);
        $own = $DB->get_record('booking_category', ['id' => $this->categoryids['own']]);
        $this->assertSame('Renamed', $own->name);
        $this->assertEquals($owncourseid, $own->course);

        // A new category always belongs to the course, and its parent is a category of the course.
        booking_categories::save($owncourseid, 0, (object)[
            'id' => '',
            'name' => 'New category',
            'cid' => $this->categoryids['other'],
            'course' => $othercourseid,
        ]);
        $this->assertSame(1, $DB->count_records('booking_category', ['course' => $othercourseid]));
        $new = $DB->get_record_select('booking_category', 'course = :course AND id <> :id', [
            'course' => $owncourseid,
            'id' => $this->categoryids['own'],
        ]);
        $this->assertSame('New category', $new->name);
        $this->assertEquals(0, $new->cid);
    }
}
