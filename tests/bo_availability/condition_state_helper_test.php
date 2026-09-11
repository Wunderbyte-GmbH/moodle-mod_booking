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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for booking availability condition state resolution.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking availability condition state resolution.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\condition_state_helper
 */
final class condition_state_helper_test extends advanced_testcase {
    /**
     * Set up test state.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    /**
     * Legacy skip settings should map to skip-and-freeze when no state map exists yet.
     */
    public function test_legacy_skip_settings_are_backward_compatible(): void {
        set_config('skipableconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');
        set_config('availabilityconditionsettings', '', 'booking');

        $helper = new condition_state_helper();

        $this->assertSame(
            condition_state_helper::STATE_SKIP_AND_FREEZE,
            $helper->get_condition_state(MOD_BOOKING_BO_COND_BOOKING_TIME)
        );
        $this->assertTrue($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
        $this->assertTrue($helper->should_freeze_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
    }

    /**
     * Explicit states should override the legacy settings when present.
     */
    public function test_explicit_state_map_is_used_first(): void {
        set_config('skipableconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');
        set_config(
            'availabilityconditionsettings',
            json_encode([
                MOD_BOOKING_BO_COND_BOOKING_TIME => [
                    'skipstate' => condition_state_helper::STATE_FREEZE,
                ],
            ]),
            'booking'
        );

        $helper = new condition_state_helper();

        $this->assertSame(
            condition_state_helper::STATE_FREEZE,
            $helper->get_condition_state(MOD_BOOKING_BO_COND_BOOKING_TIME)
        );
        $this->assertFalse($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
        $this->assertTrue($helper->should_freeze_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
    }

    /**
     * A condition without a configured state should remain inactive.
     */
    public function test_unconfigured_condition_defaults_to_inactive(): void {
        set_config('skipableconditions', '', 'booking');
        set_config('availabilityconditionsettings', '', 'booking');

        $helper = new condition_state_helper();

        $this->assertSame(
            condition_state_helper::STATE_INACTIVE,
            $helper->get_condition_state(MOD_BOOKING_BO_COND_JSON_SELECTUSERS)
        );
        $this->assertFalse($helper->should_skip_condition(MOD_BOOKING_BO_COND_JSON_SELECTUSERS));
        $this->assertFalse($helper->should_freeze_condition(MOD_BOOKING_BO_COND_JSON_SELECTUSERS));
    }

    /**
     * New object-shaped state configuration should be parsed as condition states.
     */
    public function test_object_state_map_with_skipstate_field_is_parsed(): void {
        set_config(
            'availabilityconditionsettings',
            json_encode([
                MOD_BOOKING_BO_COND_CAPBOOKINGCHOOSE => ['skipstate' => condition_state_helper::STATE_FREEZE],
                MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING => ['skipstate' => condition_state_helper::STATE_SKIP_AND_FREEZE],
            ]),
            'booking'
        );

        $helper = new condition_state_helper();

        $this->assertSame(
            condition_state_helper::STATE_FREEZE,
            $helper->get_condition_state(MOD_BOOKING_BO_COND_CAPBOOKINGCHOOSE)
        );
        $this->assertSame(
            condition_state_helper::STATE_SKIP_AND_FREEZE,
            $helper->get_condition_state(MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING)
        );
    }

    /**
     * The enrollinkskip flag should skip the condition in enrollink context only, without freezing.
     */
    public function test_enrollink_flag_skips_only_in_enrollink_context(): void {
        set_config('skipableconditions', '', 'booking');
        set_config('enrollinkskipconditions', '', 'booking');
        set_config(
            'availabilityconditionsettings',
            json_encode([
                MOD_BOOKING_BO_COND_BOOKING_TIME => [
                    'skipstate' => condition_state_helper::STATE_INACTIVE,
                    'enrollinkskip' => true,
                ],
            ]),
            'booking'
        );

        $helper = new condition_state_helper();

        $this->assertTrue($helper->is_enrollink_skipped(MOD_BOOKING_BO_COND_BOOKING_TIME));
        $this->assertTrue($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME, true));
        $this->assertFalse($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
        $this->assertFalse($helper->should_freeze_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));
    }

    /**
     * An explicit enrollinkskip=false must override the hardcoded enrollink defaults.
     */
    public function test_explicit_enrollink_flag_false_overrides_hardcoded_defaults(): void {
        set_config('skipableconditions', '', 'booking');
        set_config('enrollinkskipconditions', '', 'booking');
        set_config(
            'availabilityconditionsettings',
            json_encode([
                MOD_BOOKING_BO_COND_JSON_CUSTOMFORM => [
                    'skipstate' => condition_state_helper::STATE_INACTIVE,
                    'enrollinkskip' => false,
                ],
            ]),
            'booking'
        );

        $helper = new condition_state_helper();

        $this->assertFalse($helper->is_enrollink_skipped(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM));
        $this->assertFalse($helper->should_skip_condition(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, true));
    }

    /**
     * Entries saved before the enrollink flag existed must keep the legacy enrollink behaviour.
     */
    public function test_entry_without_enrollink_flag_falls_back_to_legacy(): void {
        set_config('skipableconditions', '', 'booking');
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');
        set_config(
            'availabilityconditionsettings',
            json_encode([
                // Old dashboard format without the enrollinkskip key.
                MOD_BOOKING_BO_COND_BOOKING_TIME => ['skipstate' => condition_state_helper::STATE_FREEZE],
                MOD_BOOKING_BO_COND_JSON_CUSTOMFORM => ['skipstate' => condition_state_helper::STATE_FREEZE],
            ]),
            'booking'
        );

        $helper = new condition_state_helper();

        // Legacy enrollinkskipconditions list still applies.
        $this->assertTrue($helper->is_enrollink_skipped(MOD_BOOKING_BO_COND_BOOKING_TIME));
        $this->assertTrue($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME, true));
        $this->assertFalse($helper->should_skip_condition(MOD_BOOKING_BO_COND_BOOKING_TIME));

        // Hardcoded enrollink defaults still apply, even though the condition has a configured state.
        $this->assertTrue($helper->is_enrollink_skipped(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM));
        $this->assertTrue($helper->should_skip_condition(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, true));

        // The configured freeze state remains untouched.
        $this->assertTrue($helper->should_freeze_condition(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM));
    }

    /**
     * Without any configuration the hardcoded enrollink defaults must apply.
     */
    public function test_hardcoded_enrollink_defaults_apply_without_config(): void {
        set_config('skipableconditions', '', 'booking');
        set_config('enrollinkskipconditions', '', 'booking');
        set_config('availabilityconditionsettings', '', 'booking');

        $helper = new condition_state_helper();

        foreach (condition_state_helper::ENROLLINK_DEFAULT_SKIP as $conditionid) {
            $this->assertTrue($helper->is_enrollink_skipped($conditionid));
            $this->assertTrue($helper->should_skip_condition($conditionid, true));
            $this->assertFalse($helper->should_skip_condition($conditionid));
        }
    }
}
