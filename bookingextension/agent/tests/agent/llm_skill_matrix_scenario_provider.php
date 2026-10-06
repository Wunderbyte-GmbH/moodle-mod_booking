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
 * Shared scenario matrix for LLM smoke tests.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Provides one reusable skill scenario matrix for real and simulated LLM suites.
 */
final class llm_skill_matrix_scenario_provider {
    /**
     * Components whose skills the real-LLM smoke matrix exercises. Third-party skills (e.g. from
     * the oneclick subplugin or local/entities) are intentionally out of scope: they must not
     * produce smoke failures here, only our own mod/booking + agent skills do.
     *
     * @var string[]
     */
    private const SMOKE_OWNED_COMPONENTS = ['mod/booking', 'bookingextension/agent'];

    /**
     * Build provider rows for the registered skills owned by mod/booking + the agent.
     *
     * @return array
     */
    public static function provide_registered_skill_scenarios(): array {
        $definitions = self::get_scenario_definitions();
        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_skill_contracts();
        ksort($contracts);

        $rows = [];
        foreach ($contracts as $skillname => $contract) {
            if (!self::is_owned_smoke_skill((array)$contract)) {
                continue;
            }
            $scenario = $definitions[$skillname] ?? [
                'prompt' => '',
                'missing_definition' => true,
            ];
            $scenario['skill'] = $skillname;
            $scenario['mode'] = $scenario['mode'] ?? ($registry->is_read_only_skill($skillname) ? 'readonly' : 'mutating');
            $rows[$skillname] = [$scenario];
        }

        return $rows;
    }

    /**
     * Return owned (mod/booking + agent) skill names that still have no explicit scenario definition.
     *
     * @return string[]
     */
    public static function get_missing_registered_skill_scenarios(): array {
        $definitions = self::get_scenario_definitions();
        $registry = skill_registry_factory::get_default();
        $missing = [];

        foreach ($registry->get_skill_contracts() as $skillname => $contract) {
            if (!self::is_owned_smoke_skill((array)$contract)) {
                continue;
            }
            if (!array_key_exists($skillname, $definitions)) {
                $missing[] = $skillname;
            }
        }

        sort($missing);
        return $missing;
    }

    /**
     * Whether a skill contract belongs to a component the smoke matrix covers.
     *
     * @param array $contract registry skill contract metadata
     * @return bool
     */
    private static function is_owned_smoke_skill(array $contract): bool {
        return in_array(trim((string)($contract['component'] ?? '')), self::SMOKE_OWNED_COMPONENTS, true);
    }

    /**
     * Return the explicit scenario definitions keyed by skill name.
     *
     * @return array
     */
    private static function get_scenario_definitions(): array {
        return [
            'wizard.scaffold_skill' => [
                'prompt' => 'Ich möchte einen eigenen Skill für mein Plugin mod/myplugin bauen, der einen '
                    . 'Eintrag archiviert. Gib mir bitte eine Vorlage zum Herunterladen.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.get_current_user' => [
                'prompt' => 'Wer bin ich?',
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.list_skills' => [
                'prompt' => 'Welche Aktionen stehen mir hier im Buchungskontext zur Verfuegung? Bitte nenne sie mir geordnet.',
                // In the STATIC catalog (slim_all) the planner already sees every skill and answers
                // the catalog question directly with sufficient — list_skills is excluded there by
                // design (thread 565), so a direct answer is the correct outcome, not a miss.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.search_skills' => [
                'prompt' => 'Ich brauche eine bestimmte Aktion, die du wahrscheinlich nicht standardmäßig geladen hast. ' .
                    'Suche in deinem Skill-Katalog nach einem Tool zum Herunterladen von Zertifikaten (download certificate).',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.recall_memory' => [
                'prompt' => 'What did we talk about last time about "{{memory_token}}"?',
                'setup' => 'prepare_recall_memory_scenario',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'observation_full',
                        'value' => '{{memory_token}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.remember' => [
                'prompt' => 'Nutze deine Merk-Funktion (wizard.remember) und speichere dauerhaft als Notiz '
                    . 'ueber mich, gueltig fuer alle Aufgaben und Situationen (keine Rueckfrage noetig): '
                    . 'Ich moechte, dass du Buchungsoptionen immer mit Datum und Uhrzeit zusammenfasst.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.list_memories' => [
                'setup' => 'prepare_user_memory_scenario',
                'prompt' => 'Welche Notizen hast du dir bisher ueber mich gemerkt? Bitte liste alle auf.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'observation_full',
                        'value' => '{{memory_token}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.forget' => [
                // Deliberately NOT prompt-hardened: the model sometimes wraps its own "are you
                // sure?" in a plain clarification instead of staging the R2 command (whose
                // confirmation card asks exactly that). That flake is the honest signal for the
                // structural response_type analysis (George 2026-07-15) — do not paper over it.
                'setup' => 'prepare_user_memory_scenario',
                'prompt' => 'Vergiss bitte dauerhaft meine gespeicherte Notiz ueber "{{memory_token}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'question.generate_questions' => [
                'setup' => 'prepare_generate_questions_scenario',
                'prompt' => "--- DOCUMENT: danube.pdf ---\n"
                    . 'The Danube is the second-longest river in Europe at about 2850 kilometres. '
                    . 'It rises in the Black Forest in Germany and flows into the Black Sea. '
                    . "It passes through ten countries, more than any other river in the world.\n"
                    . "--- END DOCUMENT ---\n\n"
                    . 'Please create 2 multiple-choice questions of medium difficulty from this '
                    . 'document and add them to the question bank in the default category.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.recreate_skill_catalog' => [
                // Deliberately NOT prompt-hardened: occasionally the model asks its own "soll
                // ich?" as a clarification instead of staging (same family as wizard.forget) —
                // honest signal for the structural response_type analysis (George 2026-07-15).
                'prompt' => 'Bitte fuehre jetzt die Admin-Aktion wizard.recreate_skill_catalog aus ' .
                    'und plane den Neuaufbau des Skill-Katalogs.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'queued',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
            'course.search_courses' => [
                'prompt' => 'Suche bitte nach dem Kurs "{{course_fullname}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.search_users' => [
                'prompt' => 'Search users with the query "{{teacher_email}}" and return the best match.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.find_content' => [
                'prompt' => 'Find content about data privacy anywhere on this site.',
                // The phpunit site has no indexed content: an executed empty result and a direct
                // "nothing found" answer are both correct outcomes for the smoke scenario.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.list_report_sources' => [
                'prompt' => 'Which report sources does the Report Builder offer on this site?',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.describe_report_source' => [
                'prompt' => 'Which columns and filters does the "Users" report source of the Report Builder offer?',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.search_reports' => [
                'prompt' => 'Which custom reports exist in the Report Builder on this site?',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.get_report_details' => [
                'prompt' => 'What does the custom report called "Baseline users" contain, and who can see it?',
                // The phpunit site has no such report: an honest miss with candidates is a correct outcome.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.create_report' => [
                'prompt' => 'Create a custom report called "Smoke users" on the users report source with the name and '
                    . 'e-mail address columns.',
                'mode' => 'mutating',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.update_report' => [
                'prompt' => 'Add the username column to the custom report "Smoke users" and sort it by name.',
                'mode' => 'mutating',
                // The phpunit site has no such report: an honest miss with candidates is a correct outcome.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.set_report_audience' => [
                'prompt' => 'Make the custom report "Smoke users" visible to all users with the manager role.',
                'mode' => 'mutating',
                // The phpunit site has no such report: an honest miss with candidates is a correct outcome.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.schedule_report' => [
                'prompt' => 'Send the custom report "Smoke users" to its audience every Monday as an Excel file.',
                'mode' => 'mutating',
                // The phpunit site has no such report: an honest miss with candidates is a correct outcome.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'report.query_report' => [
                'prompt' => 'How many rows does the custom report "Smoke users" currently return?',
                // The phpunit site has no such report: an honest miss with candidates is a correct outcome.
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'examples.multistep_example' => [
                'prompt' => 'Ich brauche Hilfe bei folgendem Vorhaben: "{{example_objective}}". '
                    . 'Bitte gehe dabei in diesen Schritten vor: '
                    . '"{{example_step_one}}", "{{example_step_two}}" und "{{example_step_three}}".',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-B] multistep example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
            'examples.readonly_example' => [
                'prompt' => 'Zeig mir bitte zu "{{example_query}}" genau zwei passende Ergebnisse.',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-A] readonly example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'examples.spawn_child_example' => [
                'prompt' => 'Bitte starte einen neuen Arbeitsschritt mit der Bezeichnung "{{child_label}}" '
                    . 'in der Sammelaktion "{{batch_label}}" und nutze dabei die Ticketnummer "{{ticket_id}}".',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-C-CHILD] spawn child example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'examples.spawn_parent_example' => [
                'prompt' => 'Bitte fasse zwei zugehoerige Teilaufgaben unter der Sammelbezeichnung "{{batch_label}}" zusammen.',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-C-PARENT] spawn parent example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
            'mod_booking.add_price_category' => [
                'prompt' => 'Please add a new booking price category with identifier "matrix_{{batch_label}}" '
                    . 'and name "Booking Price {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.analyze_rules' => [
                'setup' => 'prepare_booking_rules_service_scenario',
                'prompt' => 'Analyze booking rules for "booking confirmation" and summarize findings.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'observation_full',
                        'value' => 'booking rule',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'observation_full',
                        'value' => 'edit_rules.php',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.book_users' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Please book {{teacher_fullname}} into booking option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.bulk_update_options' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Bulk update the option with id {{existing_option_id}} and set maxanswers to 9.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.configure_booking_instance' => [
                // Write-only since the list/configure split: a read question would now route to
                // mod_booking.list_instance_settings, so this scenario must be a real mutation.
                // eventtype is a whitelisted configure field; "organizer name" is not.
                'prompt' => 'Set the event type of this booking activity to "Seminar {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.list_instance_settings' => [
                'prompt' => 'Which booking settings can I configure in this activity? ' .
                    'Please list the available fields and current values.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_option' => [
                'prompt' => 'Create exactly one standard booking option titled "Workshop {{batch_label}}" '
                    . 'for maxanswers 6, scheduled tomorrow from 10:00 to 12:00.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_rule_from_template' => [
                'setup' => 'prepare_booking_rules_service_scenario',
                'prompt' => 'Create a booking rule from template "booking confirmation" named '
                    . '"Booking rule {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_selflearning_option' => [
                'prompt' => 'Create a self-learning booking option called "Learning session {{batch_label}}" '
                    . 'with teacher {{teacher_fullname}}, max 8 participants and a learning duration of 14400 seconds.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_slotbooking_option' => [
                'prompt' => 'Create one slot booking option titled "Consultation slots {{batch_label}}" with opening 10:00, '
                    . 'closing 12:00, valid from 2026-06-01 until 2026-06-30, duration 30 minutes, '
                    . 'max 1 participant per slot, and slot_day_3=true.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.diagnose_booking_issue' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Diagnose why {{teacher_fullname}} cannot book option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.diagnose_cancellation_issue' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Diagnose why {{teacher_fullname}} cannot cancel booking option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.diagnose_user_booking' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Diagnose the booking situation of user {{teacher_fullname}} '
                    . 'for option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'wizard.explain_docs' => [
                'prompt' => 'Explain how to create a booking option using the plugin documentation.',
                'skip_reason' => 'Temporarily skipped: docs embeddings index may not be built in CI.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.get_option_details' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Show details for booking option id {{existing_option_id}} ({{existing_option_name}}).',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.list_option_properties' => [
                'prompt' => 'Welche properties haben Buchungsoptionen? Ich möchte keine Buchung erstellen, nur Auskunft.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.search_options' => [
                'prompt' => 'Search booking options for "{{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Update the booking option "{{existing_option_name}}" and set the title to '
                    . '"Updated booking {{batch_label}}" with max 9 participants.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option_trainer' => [
                'setup' => 'prepare_update_option_scenario',
                // Name AND id of the target: with the id alone the model occasionally dropped the
                // target from the constructed command (full run 2026-07-14b + repro) — the title
                // gives it a second, robust reference (optionquery path).
                'prompt' => 'Use mod_booking.update_option_trainer to assign teacheremail '
                    . '{{teacher_email}} to the option "{{existing_option_name}}" '
                    . '(optionid {{existing_option_id}}).',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_rule_from_template' => [
                'setup' => 'prepare_booking_rule_update_scenario',
                'prompt' => 'Update booking rule "{{existing_rule_name}}" and rename it to '
                    . '"Updated booking rule {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.diagnose_notifications' => [
                'prompt' => 'Diagnose the notification situation for {{teacher_fullname}} in this course.',
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.diagnose_permissions' => [
                'prompt' => 'List the permissions of {{teacher_fullname}} for the capability '
                    . 'mod/booking:addoption in this course.',
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.analyze_course_structure' => [
                'prompt' => 'Analysiere bitte die Struktur dieses Kurses (Abschnitte und Aktivitäten).',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.diagnose_user_in_course' => [
                'prompt' => 'Diagnose the course access of {{teacher_fullname}} in this course.',
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.add_activity' => [
                'prompt' => 'Add a text label saying "Welcome {{batch_label}}" at the top of this course.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.add_quiz' => [
                // Empty quiz (no questions) so the smart source resolver does not clarify and the
                // B4 question-count trigger does not fire — a clean one-shot create for the matrix.
                'prompt' => 'Create an empty quiz with no questions titled "Quiz {{batch_label}}" in this course.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.update_activity' => [
                'setup' => 'prepare_course_activity_scenario',
                'prompt' => 'Rename the activity "{{existing_activity_name}}" to "Updated activity {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.update_quiz' => [
                'setup' => 'prepare_course_quiz_scenario',
                'prompt' => 'Rename the quiz "{{existing_quiz_name}}" to "Updated quiz {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.scaffold_course_content' => [
                // Structure named in the prompt (2 chapters, no quizzes) so the deterministic
                // structure clarification does not fire, and a seeded EMPTY course as the target —
                // the ambient matrix course carries the harness booking activity, so the F2
                // not-empty soft-block would fire on every run ("This course is not empty").
                'setup' => 'prepare_empty_course_scenario',
                'prompt' => 'Generate course content about "Vikings {{batch_label}}" in the course '
                    . '"{{empty_course_fullname}}": 2 chapters, no practice quizzes, no final quiz.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'course.create_course' => [
                // Summary and category are named in the prompt: the model otherwise legitimately
                // asks for both (the skill only auto-resolves the category when exactly one is
                // writable, and the summary guidance says "compose one" — which the model may
                // decline in favour of a question). A seeded category keeps the query unique.
                'setup' => 'prepare_create_course_scenario',
                'prompt' => 'Create a new Moodle course named "Vikings {{batch_label}}" in the '
                    . 'category "{{matrix_category_name}}". Summary: "Everyday life, seafaring '
                    . 'and culture of the Vikings."',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
        ];
    }
}
