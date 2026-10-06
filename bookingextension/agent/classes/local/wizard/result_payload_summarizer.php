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
 * Centralised result-payload summarizer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\interfaces\skill_result_summary_provider_interface;

/**
 * Converts raw skill result payloads into human-readable summary strings.
 *
 * Two output modes are provided:
 *  - for_observation(): concise LLM-ready text for the agent observation loop.
 *    Replaces the previously duplicated build_observation_from_result() in agent_runtime.
 *  - for_client(): plain-text fallback message for client-facing responses when
 *    no LLM narration is available.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_payload_summarizer {
    /** Maximum number of summary parts included per observation step. */
    private const MAX_OBSERVATION_PARTS = 3;

    /** Maximum characters per individual result summary fragment. */
    private const MAX_SUMMARY_FRAGMENT_CHARS = 220;

    /** Maximum characters for one full observation line injected into the next step. */
    private const MAX_OBSERVATION_CHARS = 700;

    /**
     * Build a concise observation string for the LLM loop.
     *
     * Injected into the next orchestrator call so the model can reason about
     * what the tools returned.  It must be concise, deterministic, and never
     * contain raw DB ids or sensitive fields.
     *
     * @param  array  $results  Raw skill result payloads from execute_commands().
     * @param  int    $step     1-based loop step number used as a prefix label.
     * @return string
     */
    public static function for_observation(array $results, int $step): string {
        $parts = [];
        $hasfull = false;

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Observation_full: skill-provided verbatim content that must not be truncated.
            // Use it directly so list-type results (rules, notifications, etc.) are never cut.
            $full = isset($entry['observation_full']) ? trim((string)$entry['observation_full']) : '';
            if ($full !== '') {
                $parts[] = $full;
                $hasfull = true;
                // Do not apply MAX_OBSERVATION_PARTS limit when full content was explicitly provided.
                continue;
            }

            $summary = self::compact_text(
                self::describe_entry($entry, $step, 'observation'),
                self::MAX_SUMMARY_FRAGMENT_CHARS
            );
            if ($summary !== '') {
                $parts[] = $summary;
                if (!$hasfull && count($parts) >= self::MAX_OBSERVATION_PARTS) {
                    break;
                }
            }
        }

        if (empty($parts)) {
            // Improved fallback: Provide richer context than "Tool executed successfully."
            // Attempt to count result entries for better diagnostics.
            $resultcount = count(array_filter($results, static fn($e) => is_array($e)));
            if ($resultcount === 0) {
                return "Step {$step}: No structured results returned.";
            }
            if ($resultcount === 1) {
                return "Step {$step}: One result entry returned.";
            }
            return "Step {$step}: {$resultcount} result entries returned.";
        }

        $combined = "Step {$step}: " . implode(' ', $parts);
        // Skip character cap when full list content is present.
        if ($hasfull) {
            return $combined;
        }
        return self::compact_text($combined, self::MAX_OBSERVATION_CHARS);
    }

    /**
     * Build a compact description of a single result entry suitable for ASSISTANT_STATE injection.
     *
     * Unlike for_observation() (which labels steps), this returns just the content line
     * — "Found 2 booking option(s): A, B." — so it can be inserted as a state fact by
     * any caller that needs it (orchestrator state blocks, loop summaries, etc.).
     *
     * @param  array  $entry  A single raw skill result payload.
     * @return string         Empty string when nothing meaningful is available.
     */
    public static function describe_result_for_state(array $entry): string {
        return self::compact_text(self::describe_entry($entry, 0, 'state'), self::MAX_SUMMARY_FRAGMENT_CHARS);
    }

    /**
     * Classify a single result entry into a named category.
     *
     * Used by both for_observation() and execution_feedback_service to avoid
     * duplicating the structural type-detection logic across two classes.
     *
     * Possible return values:
     *  'options'      — entry contains a booking-options array
     *  'users'        — entry contains a users array
     *  'courses'      — entry contains a courses array
     *  'docs'         — entry contains a docs/documentation array
     *  'diagnosis'    — entry contains a diagnosis object
     *  'capabilities' — entry contains a capabilities array
     *  'generic'      — none of the above
     *
     * @param  array $entry  A single raw skill result payload.
     * @return string        Category identifier.
     */
    public static function detect_result_category(array $entry): string {
        if (!empty($entry['options']) && is_array($entry['options'])) {
            return 'options';
        }
        if (!empty($entry['users']) && is_array($entry['users'])) {
            return 'users';
        }
        if (!empty($entry['courses']) && is_array($entry['courses'])) {
            return 'courses';
        }
        if (!empty($entry['docs']) && is_array($entry['docs'])) {
            return 'docs';
        }
        if (!empty($entry['diagnosis']) && is_array($entry['diagnosis'])) {
            return 'diagnosis';
        }
        if (!empty($entry['capabilities']) && is_array($entry['capabilities'])) {
            return 'capabilities';
        }
        if (!empty($entry['properties']) && is_array($entry['properties'])) {
            return 'properties';
        }
        if (!empty($entry['optiondetails']) && is_array($entry['optiondetails'])) {
            return 'option_details';
        }
        if (array_key_exists('fullname', $entry) || array_key_exists('email', $entry)) {
            return 'current_user';
        }
        return 'generic';
    }

    /**
     * Describe a single result entry as a concise human-readable string.
     *
     * Public so that callers such as agent_runtime can use it directly
     * without going through the step-labelled for_observation() wrapper.
     *
     * @param  array $entry
     * @param  int   $step
     * @param  string $mode
     * @return string
     */
    public static function describe_entry(array $entry, int $step = 0, string $mode = 'observation'): string {
        $category = self::detect_result_category($entry);
        $context = self::build_summary_context($entry, $category, $step, $mode);

        // Highest-priority escape hatch: skill-authored summary method.
        $skillsummary = self::summarize_with_skill_provider($entry, $context);
        if ($skillsummary !== '') {
            return self::compact_text($skillsummary, self::MAX_SUMMARY_FRAGMENT_CHARS);
        }

        $contributed = self::summarize_with_contributors($category, $entry, $step);
        if ($contributed !== '') {
            return $contributed;
        }

        switch ($category) {
            case 'capabilities':
                $capcount = count($entry['capabilities']);
                $actcount = count($entry['actions'] ?? []);
                $acttitles = array_slice(
                    array_filter(array_map(
                        static fn($a): string => trim((string)($a['label'] ?? $a['skill'] ?? '')),
                        (array)($entry['actions'] ?? [])
                    )),
                    0,
                    8
                );
                $capsummary = "Listed {$capcount} capability item(s) and {$actcount} action(s)";
                if (!empty($acttitles)) {
                    $capsummary .= '. Actions: ' . implode(', ', $acttitles);
                }
                return $capsummary . '.';

            case 'properties':
                $propcount = count($entry['properties']);
                $propnames = array_slice(
                    array_filter(array_map(
                        static fn($p): string => trim((string)($p['name'] ?? '')),
                        $entry['properties']
                    )),
                    0,
                    8
                );
                $propsummary = "Listed {$propcount} option property/properties";
                if (!empty($propnames)) {
                    $propsummary .= ': ' . implode(', ', $propnames);
                }
                return $propsummary . '.';

            case 'option_details':
                $details = (array)$entry['optiondetails'];
                $dcount = count($details);
                $titles = array_slice(array_values(array_filter(array_map(
                    static fn($d): string => trim((string)($d['title'] ?? $d['standard_fields']['title'] ?? '')),
                    $details
                ))), 0, 3);
                $teachernames = [];
                $sessioncount = 0;
                foreach ($details as $detail) {
                    $standard = (array)($detail['standard_fields'] ?? []);
                    $sessions = (array)($standard['sessions'] ?? $detail['sessions'] ?? []);
                    $sessioncount += count($sessions);
                    $teachers = (array)($standard['teachers'] ?? $detail['teachers'] ?? []);
                    foreach ($teachers as $teacher) {
                        $name = trim((string)($teacher['name'] ?? $teacher['fullname'] ?? ''));
                        if ($name === '') {
                            $firstname = trim((string)($teacher['firstname'] ?? ''));
                            $lastname = trim((string)($teacher['lastname'] ?? ''));
                            $name = trim($firstname . ' ' . $lastname);
                        }
                        if ($name !== '') {
                            $teachernames[] = $name;
                        }
                    }
                }
                $teachernames = array_slice(array_values(array_unique($teachernames)), 0, 5);
                $detailsummary = "Loaded detailed data for {$dcount} option(s)";
                if (!empty($titles)) {
                    $detailsummary .= ': ' . implode(', ', $titles);
                }
                $detailsummary .= ". Sessions: {$sessioncount}.";
                if (!empty($teachernames)) {
                    $detailsummary .= ' Teachers: ' . implode(', ', $teachernames) . '.';
                }
                $capabilities = (array)($entry['detail_capabilities'] ?? []);
                $supported = array_slice(array_values(array_filter(array_map(
                    static fn($f): string => trim((string)$f),
                    (array)($capabilities['supported_standard_fields'] ?? [])
                ))), 0, 8);
                if (!empty($supported)) {
                    $detailsummary .= ' Supported detail fields: ' . implode(', ', $supported) . '.';
                }
                // Collect loaded custom field values across all detail entries.
                $loadedcustomfields = [];
                foreach ($details as $detail) {
                    foreach ((array)($detail['customfields'] ?? []) as $cfkey => $cfval) {
                        $strval = is_array($cfval) ? implode(', ', $cfval) : (string)$cfval;
                        if ($strval !== '') {
                            $loadedcustomfields[trim((string)$cfkey)] = $strval;
                        }
                    }
                }
                if (!empty($loadedcustomfields)) {
                    $cfparts = [];
                    foreach ($loadedcustomfields as $cfkey => $cfval) {
                        $cfparts[] = "{$cfkey}: {$cfval}";
                    }
                    $detailsummary .= ' Custom fields: ' . implode('; ', $cfparts) . '.';
                } else {
                    $customfieldcaps = (array)($capabilities['available_customfields'] ?? []);
                    if (!empty($customfieldcaps)) {
                        $labels = [];
                        $keys = [];
                        foreach (array_slice($customfieldcaps, 0, 6) as $cf) {
                            if (!is_array($cf)) {
                                continue;
                            }
                            $label = trim((string)($cf['label'] ?? $cf['key'] ?? ''));
                            $key = trim((string)($cf['key'] ?? ''));
                            if ($label !== '') {
                                $labels[] = $label;
                            }
                            if ($key !== '') {
                                $keys[] = $key;
                            }
                        }
                        if (!empty($labels)) {
                            $keylist = implode(', ', $keys);
                            $detailsummary .= ' Custom field values NOT loaded (only keys known): '
                                . implode(', ', $labels) . '.'
                                . " To retrieve a custom field value, call the corresponding details skill again"
                                . " with include_customfields=true and customfield_keys=[{$keylist}].";
                        }
                    }
                }
                return $detailsummary;

            case 'diagnosis':
                $diagnosis = (array)$entry['diagnosis'];
                $issue = trim((string)($diagnosis['issue'] ?? 'unknown'));
                $optionname = trim((string)($diagnosis['optionname'] ?? 'unknown option'));
                $userstatus = trim((string)($diagnosis['userstatus'] ?? 'unknown'));
                $reasoncount = count((array)($diagnosis['reasons'] ?? []));
                $diagnosticsummary = "Diagnosed {$issue} for {$optionname}: user {$userstatus}";
                if ($reasoncount > 0) {
                    $diagnosticsummary .= " ({$reasoncount} reason(s) identified)";
                }
                // Flag consistency mismatches so planner/synthesis can react deterministically.
                $consistency = (array)($diagnosis['consistency'] ?? $entry['consistency'] ?? []);
                $usermismatch = !empty($consistency['user_mismatch']);
                $optionmismatch = !empty($consistency['option_mismatch']);
                if ($usermismatch || $optionmismatch) {
                    $diagnosticsummary .= " [input/result mismatch detected]";
                    $warning = trim((string)($consistency['warnings'][0] ?? ''));
                    if ($warning !== '') {
                        $diagnosticsummary .= " ({$warning})";
                    }
                }
                return $diagnosticsummary . '.';

            default:
                // Fallback: use skill-authored user message or detail string.
                return self::compact_text(
                    trim((string)($entry['usermessage'] ?? $entry['detail'] ?? '')),
                    self::MAX_SUMMARY_FRAGMENT_CHARS
                );
        }
    }

    /**
     * Normalize whitespace and trim to a safe max length.
     *
     * @param string $text
     * @param int $maxchars
     * @return string
     */
    private static function compact_text(string $text, int $maxchars): string {
        $clean = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($clean === '') {
            return '';
        }

        if ($maxchars <= 0 || mb_strlen($clean) <= $maxchars) {
            return $clean;
        }

        $limit = max(8, $maxchars - 1);
        return rtrim(mb_substr($clean, 0, $limit)) . '...';
    }

    /**
     * Try skill/domain-specific summary contributors first.
     *
     * @param string $category
     * @param array $entry
     * @param int $step
     * @return string
     */
    private static function summarize_with_contributors(string $category, array $entry, int $step): string {
        $contributors = skill_registry_factory::get_default()->get_result_summary_contributors();

        foreach ($contributors as $contributor) {
            if (!$contributor->supports($category, $entry)) {
                continue;
            }

            $summary = trim($contributor->summarize($entry, $step));
            if ($summary !== '') {
                return $summary;
            }
        }

        return '';
    }

    /**
     * Build normalized summary context for skill-level summarizers.
     *
     * @param array $entry
     * @param string $category
     * @param int $step
     * @param string $mode
     * @return array
     */
    private static function build_summary_context(array $entry, string $category, int $step, string $mode): array {
        $skillname = trim((string)($entry['skill'] ?? ''));
        $component = trim((string)($entry['result_component'] ?? ''));
        if ($component === '' && $skillname !== '' && strpos($skillname, '.') !== false) {
            $prefix = explode('.', $skillname)[0] ?? '';
            if ($prefix !== '') {
                $component = $prefix === 'booking' ? 'bookingextension_agent' : $prefix;
            }
        }

        return [
            'mode' => $mode,
            'step' => $step,
            'skill' => $skillname,
            'component' => $component,
            'category' => $category,
        ];
    }

    /**
     * Try skill-authored summary providers first.
     *
     * @param array $entry
     * @param array $context
     * @return string
     */
    private static function summarize_with_skill_provider(array $entry, array $context): string {
        $skillname = trim((string)($context['skill'] ?? ''));
        if ($skillname === '') {
            return '';
        }

        $skill = skill_registry_factory::get_default()->get_skill($skillname);
        if (!$skill instanceof skill_result_summary_provider_interface) {
            return '';
        }

        $summary = trim($skill->summarize_skill_result($entry, $context));
        return $summary;
    }
}
