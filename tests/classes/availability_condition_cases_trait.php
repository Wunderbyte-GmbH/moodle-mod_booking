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

namespace mod_booking\tests;

use coding_exception;
use stdClass;

/**
 * The availability conditions a booking option can be configured with, as the option form submits them.
 *
 * Tests on the tracking of availability changes run the same steps for every condition: it is added,
 * changed and removed again. This trait lists the conditions and builds the form values of each step.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait availability_condition_cases_trait {
    /**
     * The names of all conditions stored in the availability JSON of an option.
     *
     * @return array
     */
    public static function condition_names(): array {
        return [
            'allowedtobookininstance',
            'customform',
            'enrolledincohorts',
            'enrolledincourse',
            'hascompetency',
            'nooverlapping',
            'previouslybooked',
            'selectusers',
            'userprofilefield_1_default',
            'userprofilefield_2_custom',
        ];
    }

    /**
     * Builds the form values that add, change and remove the given condition.
     *
     * The values of a step are meant to be set on top of the ones of the step before, like a user
     * editing the same option form again. Whatever a condition refers to is created here.
     *
     * @param string $condition name of the condition
     * @param int $bookingid the booking instance of the option under test
     * @param int $courseid the course of the booking instance
     * @return array with the keys id (of the condition), add, change and remove (form values)
     */
    protected function condition_steps(string $condition, int $bookingid, int $courseid): array {
        $generator = $this->getDataGenerator();

        switch ($condition) {
            case 'allowedtobookininstance':
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE,
                    'add' => [
                        'bo_cond_allowedtobookininstance_restrict' => 1,
                        'bo_cond_allowedtobookininstance_capabilitynotneeded' => 0,
                    ],
                    'change' => ['bo_cond_allowedtobookininstance_capabilitynotneeded' => 1],
                    'remove' => ['bo_cond_allowedtobookininstance_restrict' => 0],
                ];

            case 'customform':
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
                    'add' => [
                        'bo_cond_customform_restrict' => 1,
                        'bo_cond_customform_select_1_1' => 'shorttext',
                        'bo_cond_customform_label_1_1' => 'First question',
                        'bo_cond_customform_notempty_1_1' => 0,
                    ],
                    'change' => ['bo_cond_customform_label_1_1' => 'Second question'],
                    'remove' => ['bo_cond_customform_restrict' => 0],
                ];

            case 'enrolledincohorts':
                $cohort1 = $generator->create_cohort();
                $cohort2 = $generator->create_cohort();
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS,
                    'add' => [
                        'bo_cond_enrolledincohorts_restrict' => 1,
                        'bo_cond_enrolledincohorts_cohortids' => [$cohort1->id],
                        'bo_cond_enrolledincohorts_cohortids_operator' => 'OR',
                    ],
                    'change' => [
                        'bo_cond_enrolledincohorts_cohortids' => [$cohort1->id, $cohort2->id],
                        'bo_cond_enrolledincohorts_cohortids_operator' => 'AND',
                    ],
                    'remove' => ['bo_cond_enrolledincohorts_restrict' => 0],
                ];

            case 'enrolledincourse':
                $course1 = $generator->create_course();
                $course2 = $generator->create_course();
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE,
                    'add' => [
                        'bo_cond_enrolledincourse_restrict' => 1,
                        'bo_cond_enrolledincourse_courseids' => [$course1->id],
                        'bo_cond_enrolledincourse_courseids_operator' => 'OR',
                    ],
                    'change' => ['bo_cond_enrolledincourse_courseids' => [$course1->id, $course2->id]],
                    'remove' => ['bo_cond_enrolledincourse_restrict' => 0],
                ];

            case 'hascompetency':
                /** @var \core_competency_generator $competencygenerator */
                $competencygenerator = $generator->get_plugin_generator('core_competency');
                $framework = $competencygenerator->create_framework();
                $competency1 = $competencygenerator->create_competency(['competencyframeworkid' => $framework->get('id')]);
                $competency2 = $competencygenerator->create_competency(['competencyframeworkid' => $framework->get('id')]);
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY,
                    'add' => [
                        'bo_cond_hascompetency_restrict' => 1,
                        'bo_cond_hascompetency_competencyids' => [$competency1->get('id')],
                        'bo_cond_hascompetency_competencyids_operator' => 'AND',
                    ],
                    'change' => [
                        'bo_cond_hascompetency_competencyids' => [$competency1->get('id'), $competency2->get('id')],
                    ],
                    'remove' => ['bo_cond_hascompetency_restrict' => 0],
                ];

            case 'nooverlapping':
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING,
                    'add' => [
                        'bo_cond_nooverlapping_restrict' => 1,
                        'bo_cond_nooverlapping_handling' => MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN,
                    ],
                    'change' => ['bo_cond_nooverlapping_handling' => MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK],
                    'remove' => ['bo_cond_nooverlapping_restrict' => 0],
                ];

            case 'previouslybooked':
                /** @var \mod_booking_generator $plugingenerator */
                $plugingenerator = $generator->get_plugin_generator('mod_booking');
                $record = new stdClass();
                $record->bookingid = $bookingid;
                $record->text = 'Option to be booked before';
                $record->chooseorcreatecourse = 1;
                $record->courseid = $courseid;
                $record->importing = 1;
                $record->optiondateid_0 = '0';
                $record->daystonotify_0 = '0';
                $record->coursestarttime_0 = strtotime('20 May 2050');
                $record->courseendtime_0 = strtotime('21 May 2050');
                $required = $plugingenerator->create_option($record);
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED,
                    'add' => [
                        'bo_cond_previouslybooked_restrict' => 1,
                        'bo_cond_previouslybooked_optionid' => [$required->id],
                    ],
                    'change' => ['bo_cond_previouslybooked_requirecompletion' => 1],
                    'remove' => ['bo_cond_previouslybooked_restrict' => 0],
                ];

            case 'selectusers':
                $user1 = $generator->create_user();
                $user2 = $generator->create_user();
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
                    'add' => [
                        'bo_cond_selectusers_restrict' => 1,
                        'bo_cond_selectusers_userids' => [$user1->id],
                    ],
                    'change' => ['bo_cond_selectusers_userids' => [$user1->id, $user2->id]],
                    'remove' => ['bo_cond_selectusers_restrict' => 0],
                ];

            case 'userprofilefield_1_default':
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD,
                    'add' => [
                        'bo_cond_userprofilefield_1_default_restrict' => 1,
                        'bo_cond_userprofilefield_field' => 'email',
                        'bo_cond_userprofilefield_operator' => '~',
                        'bo_cond_userprofilefield_value' => 'example',
                    ],
                    'change' => ['bo_cond_userprofilefield_value' => 'sample'],
                    'remove' => ['bo_cond_userprofilefield_1_default_restrict' => 0],
                ];

            case 'userprofilefield_2_custom':
                $generator->create_custom_profile_field([
                    'datatype' => 'text',
                    'shortname' => 'conditionfield',
                    'name' => 'Condition field',
                ]);
                return [
                    'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD,
                    'add' => [
                        'bo_cond_userprofilefield_2_custom_restrict' => 1,
                        'bo_cond_customuserprofilefield_field' => 'conditionfield',
                        'bo_cond_customuserprofilefield_operator' => '=',
                        'bo_cond_customuserprofilefield_value' => 'first',
                    ],
                    'change' => ['bo_cond_customuserprofilefield_value' => 'second'],
                    'remove' => ['bo_cond_userprofilefield_2_custom_restrict' => 0],
                ];
        }

        throw new coding_exception('Unknown availability condition: ' . $condition);
    }
}
