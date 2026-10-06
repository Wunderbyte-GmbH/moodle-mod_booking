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
 * Tests for the capability that guards the price category list.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\wizard\options\skills\add_price_category_skill;

/**
 * Run-23 finding: maintaining price categories was guarded by moodle/site:config, a server
 * administration right no manager holds. The area belongs to the booking manager, so all four
 * baseline prompts were refused - correctly, by the letter, and uselessly.
 *
 * The right is now booking's own and defaults to the manager archetype. The refusal path stays
 * intact for everyone without it.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\add_price_category_skill
 */
final class wizard_price_category_capability_test extends \advanced_testcase {
    /** @var string The capability under test. */
    private const CAPABILITY = 'mod/booking:managepricecategories';

    /**
     * The skill classes resolve through the engine alias layer, which the bootstrap does not set up.
     */
    protected function setUp(): void {
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * A manager can maintain price categories, and the skill asks for that right and no other.
     */
    public function test_a_manager_may_maintain_price_categories(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertTrue(
            $DB->record_exists('capabilities', ['name' => self::CAPABILITY]),
            'the capability must be declared in db/access.php'
        );

        // Every gate of this skill asks for the same right. Three of them exist - the engine's
        // declared one plus the skill's own preflight and execute checks - and run 23 showed that
        // changing only some of them leaves the skill unreachable.
        $skill = new add_price_category_skill();
        $this->assertSame([self::CAPABILITY], $skill->get_required_native_capabilities());
        // The quoted form is what a gate uses; the comments explaining the history name the old
        // right in prose and must stay readable.
        $this->assertStringNotContainsString(
            "'moodle/site:config'",
            (string)file_get_contents((string)(new \ReflectionClass($skill))->getFileName()),
            'no gate may be left on the site administration right'
        );

        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        $this->assertTrue(has_capability(self::CAPABILITY, \context_system::instance(), $manager));

        // And the gate still closes for someone without the right.
        $plain = $this->getDataGenerator()->create_user();
        $this->assertFalse(has_capability(self::CAPABILITY, \context_system::instance(), $plain));
    }
}
