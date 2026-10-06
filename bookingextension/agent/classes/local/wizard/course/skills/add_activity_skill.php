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

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\activity_preview_renderer;
use bookingextension_agent\local\wizard\services\activities\module_catalog_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use bookingextension_agent\local\wizard\services\activities\section_resolver_service;
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use context;

/**
 * Generic skill: add an activity module to a course (course.add_activity).
 *
 * Validate-and-create only (blueprint scope = schlank). The skill builds a module's real mod_form headless
 * to validate the candidate fields and to source defaults, then creates the activity via add_moduleinfo().
 * No activity/module/mform knowledge leaks into the engine: clarifications (which module? which section?
 * which fields?) all travel over the generic needs_clarification + options channel; the created activity is
 * surfaced via the generic get_result_preview() data block.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_activity_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name constant. */
    public const SKILL_NAME = 'course.add_activity';

    /** How many create attempts before giving up (guards transient DB errors). */
    private const MAX_RETRIES = 2;

    /**
     * Constructor. Mutating skill (creates a course module) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Human-readable preview of the activity to be created (tier-3 confirmation preview).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return activity_preview_builder::add_activity_descriptor($input);
    }

    /**
     * Activities are created in a course, so this skill needs course scope.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * The cross-context target is a course.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * Native capability required to add an activity (Gate 2). The module-specific
     * mod/<modname>:addinstance is re-checked in preflight once the module type is known.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/course:manageactivities'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Add an activity or resource (modname: page, url, label, book, folder, forum) to a Moodle course '
                . 'section; a named course goes into coursequery, no lookup needed. Use this whenever the user wants to add, '
                . 'create or insert an activity or resource into a course (e.g. "add a page", "add a URL/link", "create a forum", '
                . '"create a label").',
            'is' => 'Creating an activity or resource.',
            'not' => 'A quiz (add_quiz); questions (question.generate_questions); the course (create_course); edits '
                . '(update_activity); a whole course (scaffold_course_content).',
            'readonly' => false,
            'example_utterances' => [
                'add a page to week 2',
                'put a forum in this course',
                'create a label with some intro text',
                'add a link to our syllabus PDF',
                'insert a folder into the introduction section',
                'add a book resource to the course',
            ],
            'properties' => [
                'modname' => [
                    'type' => 'string',
                    // Wave 32 (L42sol: AA-4 "Ordner", AA-3 "lien"; ADD_ACTIVITY_MODULE_AMBIGUOUS): the old text asked
                    // for the user's wording verbatim, and the resolver compares it with the module labels in the
                    // SITE language - a user writing in another language never matched. The type is a closed set;
                    // the constructor picks the value, the resolver still accepts anything else leniently.
                    'enum' => module_catalog_service::WHITELIST,
                    'description' => 'The type of activity or resource the user means, as one of the listed values. '
                        . 'Leave it empty if the user named no type; the system then lists addable types.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    // L45 AA-3 (thread 14947): "un lien vers le site de la fédération nationale" names what the link is, yet the
                    // constructor asked for a name; the user's words for what the activity holds or points to are its name.
                    'description' => 'The name shown in the course: the user\'s words for the activity or for what it '
                        . 'holds or points to. Optional for a label/text area (the text is the content).',
                    'required' => false,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'The description/intro text. For a label/text area this is the actual content '
                        . 'shown on the course page.',
                    'required' => false,
                ],
                'section' => [
                    'type' => 'string',
                    'description' => 'Where to place the activity: pass the user\'s wording verbatim — "top", '
                        . '"bottom", a section name (e.g. "Week 2"), or a section '
                        . 'number. Leave empty if the user did not say where; the system then lists the course '
                        . 'sections and asks.',
                    'required' => false,
                ],
                'settings' => [
                    'type' => 'object',
                    'description' => 'Module-specific fields, as a free object. Examples: for a URL pass '
                        . '{"externalurl":"https://…"}; for a Page pass {"content":"…the page body…"}. Only set what '
                        . 'the user provided; the system validates against the module and asks for anything missing.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'The course the user named as the target, if any. If the user names a course in '
                        . 'this message OR an earlier one (e.g. "add a page in the course Biology 101", "please add '
                        . 'it in selflearning"), you MUST put that exact wording here verbatim — a named course is '
                        . 'NEVER the same as "the current course", even if it was mentioned earlier in the chat. The '
                        . 'system resolves the name itself. Leave empty ONLY when the user named no course at all '
                        . '(then the current course is used).',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course, when already known. Leave empty for the current '
                        . 'course; never guess an id.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['modname', 'name'],
                'anchor_fields' => ['coursequery'],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'modname' => 'page',
            'name' => 'Course introduction',
            'intro' => 'Welcome to the course.',
            'section' => 'top',
            // Illustrates the cross-course shape: surfaces coursequery as an OPTIONAL field in the
            // compact selector catalog (without mislabeling the optional field as REQUIRED) so a
            // user-named target course is not silently dropped. See prompt_refactoring blueprint H2.
            'coursequery' => 'Biology 101',
        ];
    }

    /**
     * Return message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.add_activity_request',
                'description' => 'The user wants an activity or resource (page, link, label, book, folder, forum) added to a'
                    . ' Moodle course.',
            ],
        ];
    }

    /**
     * Construction-phase guidance surfaced once this skill is selected.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.add_activity',
                'triggers' => [
                    'add a page', 'create a page', 'add a url', 'add a link', 'add a label', 'add a text area',
                    'add a forum', 'add a folder', 'add a book', 'add an activity', 'add a resource',
                    'create page', 'add page', 'add link', 'add url', 'create forum',
                    'add text area', 'add activity', 'create activity',
                    'add resource', 'create folder', 'create book',
                ],
                'guidance' => [
                    '- course.add_activity creates the activity itself; do NOT look for a separate "insert" skill.',
                    '- Supported types: ' . implode(', ', module_catalog_service::WHITELIST)
                        . '. If the user did not name a type, leave input.modname empty — the system lists the '
                        . 'addable types and asks.',
                    '- If the user did not say WHERE to place it, leave input.section empty — the system lists the '
                        . 'course sections and asks. If they did ("top"/"bottom"/a section name), pass it verbatim.',
                    '- Put module-specific values into input.settings (e.g. {"externalurl":"https://…"} for a URL, '
                        . '{"content":"…"} for a Page). Do NOT invent values; the system asks for anything missing.',
                    '- For quiz/test questions use question.generate_questions instead, not this skill.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure, no DB).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];

        $modname = trim((string)($input['modname'] ?? ''));
        if ($modname !== '') {
            // Only a hard error when the planner passed something that is clearly not a supported type AND
            // cannot be a free-text alias — we keep this lenient because preflight resolves names against the
            // real, capability-filtered catalog. Reject only obviously structural junk.
            if (strlen($modname) > 100) {
                $errors[] = 'modname is too long.';
            }
        }

        if (isset($input['settings']) && $input['settings'] !== '' && !is_array($input['settings'])) {
            $errors[] = 'settings must be an object of module-specific fields.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Deep validation: resolve course, module type, section and module fields (all read-only).
     *
     * @param array $input
     * @param int   $contextid Operating context (the target course context when one was named).
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // 1) Resolve the course context.
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify(
                'Activities can only be added inside a course. Please open a course, or tell me which course '
                    . '(by name) the activity should go into.',
                'ADD_ACTIVITY_NO_COURSE'
            );
        }

        // 2) Gate 2 (static): the user must natively be allowed to manage activities in this course.
        if (!has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
            return $this->clarify(
                get_string('nopermissions', 'error', 'moodle/course:manageactivities'),
                'NO_NATIVE_CAPABILITY'
            );
        }

        $course = get_course($coursecontext->instanceid);

        // 3) Module selection (Stage A) — incl. Gate 2 module-specific addinstance via the catalog filter.
        $catalog = new module_catalog_service();
        $addable = $catalog->list_addable_modules($course, $userid);
        if (empty($addable)) {
            return $this->clarify(
                'You cannot add any of the supported activity types in this course.',
                'ADD_ACTIVITY_NONE_ADDABLE'
            );
        }

        $modresolution = $this->resolve_module($catalog, $addable, trim((string)($input['modname'] ?? '')));
        if (is_array($modresolution)) {
            return $modresolution;
        }
        $modname = $modresolution;

        // 4) Section placement (Stage) — unless this module type must live in section 0.
        $sectionnum = $this->resolve_section($course, $modname, trim((string)($input['section'] ?? '')));
        if (is_array($sectionnum)) {
            return $sectionnum;
        }

        // 5) Field validation (Stage B) against the module's real mod_form.
        $name = trim((string)($input['name'] ?? ''));
        $intro = trim((string)($input['intro'] ?? ''));
        $settings = is_array($input['settings'] ?? null) ? (array)$input['settings'] : [];

        $validation = (new module_form_contract())->validate($course, $modname, $sectionnum, $name, $intro, $settings);
        if (!$validation['ok']) {
            if (!empty($validation['errors'])) {
                return $this->clarify(
                    $this->format_field_errors($modname, $validation['errors']),
                    'ADD_ACTIVITY_FIELDS_INVALID'
                );
            }
            // Form could not be built headless and we have no concrete errors: fall through with a
            // best-effort minimal guard for the most critical user-supplied field.
            $guard = $this->minimal_field_guard($modname, $name, $intro, $settings);
            if (is_array($guard)) {
                return $guard;
            }
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'modname' => $modname,
            'sectionnum' => (int)$sectionnum,
            'name' => $name,
            'intro' => $intro,
            'settings' => $settings,
        ]);
    }

    /**
     * Create the activity, retrying once on a transient failure.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $modname = (string)($preparedinput['modname'] ?? '');
        $sectionnum = (int)($preparedinput['sectionnum'] ?? 0);
        $name = (string)($preparedinput['name'] ?? '');
        $intro = (string)($preparedinput['intro'] ?? '');
        $settings = (array)($preparedinput['settings'] ?? []);

        if ($courseid <= 0 || $modname === '') {
            return $this->build_error_result('Missing prepared course or module type for activity creation.');
        }

        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->build_error_result('Target course could not be loaded.');
        }

        $contract = new module_form_contract();
        $creator = new activity_creation_service();

        // A missing addinstance capability is deterministic; surface the truthful cause instead of
        // the mform's misleading "module disabled ({$a})" that the build below would raise (C6).
        $denial = $creator->addinstance_denial_reason($course, $modname);
        if ($denial !== null) {
            return $this->build_error_result($denial);
        }

        $lasterror = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $moduleinfo = $contract->build_prepared_moduleinfo($course, $modname, $sectionnum, $name, $intro, $settings);
                if ($modname === 'page') {
                    // The add_moduleinfo() call runs headless (no mform), and page_add_instance() only
                    // copies the 'page' editor into the content column when a form is present —
                    // set the column directly so the body survives the create.
                    foreach (['page', 'content', 'body', 'text'] as $contentkey) {
                        if (isset($settings[$contentkey]) && trim((string)$settings[$contentkey]) !== '') {
                            $moduleinfo->content = (string)$settings[$contentkey];
                            $moduleinfo->contentformat = FORMAT_HTML;
                            break;
                        }
                    }
                }
                $created = $creator->create($moduleinfo, $course);
                return $this->build_success_result($created, $attempt);
            } catch (\Throwable $e) {
                $lasterror = $e->getMessage();
                // Creation errors are usually deterministic; a retry only helps transient DB hiccups.
                continue;
            }
        }

        return $this->build_error_result(
            'Could not create the activity after ' . self::MAX_RETRIES . ' attempt(s). Last error: ' . $lasterror
        );
    }

    /**
     * Render the freshly created activity inline for the agent preview pane.
     *
     * @param array $resultentry
     * @param int   $contextid
     * @param int   $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $cmid = (int)($resultentry['created_cmid'] ?? 0);
        $courseid = (int)($resultentry['created_courseid'] ?? 0);
        if ($cmid <= 0 || $courseid <= 0) {
            return null;
        }

        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return null;
        }

        $modname = (string)($resultentry['created_modname'] ?? '');
        $name = (string)($resultentry['created_name'] ?? '');
        $url = (string)($resultentry['activity_url'] ?? '');

        $html = (new activity_preview_renderer())->render($course, $cmid, $modname, $name, $url);
        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => 'created_activity',
            'html' => $html,
            'payload' => [
                'cmid' => $cmid,
                'activity_url' => $url,
            ],
        ];
    }

    /**
     * Resolve the module type from input against the addable catalog.
     *
     * @param module_catalog_service $catalog
     * @param array[] $addable
     * @param string $query
     * @return string|array The resolved module name, or a clarification.
     */
    private function resolve_module(module_catalog_service $catalog, array $addable, string $query) {
        if ($query === '') {
            return $this->build_module_clarification(
                $addable,
                'Which type of activity should I add?'
            );
        }

        $matches = $catalog->resolve_module_name($addable, $query);
        if (count($matches) === 1) {
            return (string)$matches[0]['modname'];
        }
        if (count($matches) > 1) {
            return $this->build_module_clarification(
                $matches,
                'More than one activity type matches "' . $query . '". Which one did you mean?'
            );
        }
        return $this->build_module_clarification(
            $addable,
            'I cannot add "' . $query . '" here. Please choose one of the supported types:'
        );
    }

    /**
     * Resolve the target section number, or return a clarification.
     *
     * @param \stdClass $course
     * @param string $modname
     * @param string $query
     * @return int|array
     */
    private function resolve_section(\stdClass $course, string $modname, string $query) {
        // Site front page: it has no selectable topic sections — section 0 is not rendered there, so
        // every activity lands in section 1 regardless of what was asked ("top"/"homepage"/a number).
        // Hard-coded because on the front page placement is not a real user choice.
        if (section_resolver_service::is_site_front_page($course)) {
            return section_resolver_service::SITE_FRONT_PAGE_SECTION;
        }
        // Module types that are not displayed on the course page must always be in section 0.
        if (!\course_modinfo::is_mod_type_visible_on_course($modname)) {
            return 0;
        }

        $resolver = new section_resolver_service();
        $sections = $resolver->list_sections($course);

        if ($query === '') {
            return $this->build_section_clarification(
                $sections,
                'Where should I place the activity?'
            );
        }

        $resolved = $resolver->resolve_placement($course, $query);
        if (is_int($resolved)) {
            return $resolved;
        }
        if (is_array($resolved) && !empty($resolved)) {
            return $this->build_section_clarification(
                $resolved,
                'More than one section matches "' . $query . '". Which one did you mean?'
            );
        }
        return $this->build_section_clarification(
            $sections,
            'I could not find a section called "' . $query . '". Please choose one of these:'
        );
    }

    /**
     * Minimal guard for the few module fields we cannot validate when the mform refuses to build headless.
     *
     * @param string $modname
     * @param string $name
     * @param string $intro
     * @param array $settings
     * @return array|null
     */
    private function minimal_field_guard(string $modname, string $name, string $intro, array $settings): ?array {
        if ($modname === 'url') {
            $url = trim((string)($settings['externalurl'] ?? $settings['url'] ?? $settings['link'] ?? ''));
            if ($url === '') {
                return $this->clarify('Please provide the URL the link should point to.', 'ADD_ACTIVITY_URL_REQUIRED');
            }
        }
        if ($modname === 'label' && $intro === '' && $name === '') {
            return $this->clarify('Please provide the text for the text/label area.', 'ADD_ACTIVITY_LABEL_REQUIRED');
        }
        if ($modname !== 'label' && $name === '') {
            return $this->clarify('Please provide a name for the new activity.', 'ADD_ACTIVITY_NAME_REQUIRED');
        }
        return null;
    }

    /**
     * Build a needs_clarification listing the addable module types.
     *
     * @param array[] $modules
     * @param string $lead
     * @return array
     */
    private function build_module_clarification(array $modules, string $lead): array {
        $lines = [$lead, ''];
        $options = [];
        foreach ($modules as $module) {
            $lines[] = '- ' . $module['label'] . ' (' . $module['modname'] . ')';
            $options[] = [
                'modname' => $module['modname'],
                'label' => $module['label'],
            ];
        }
        $lines[] = '';
        $lines[] = 'Just reply with the type you want and I will continue.';

        return $this->clarify(implode("\n", $lines), 'ADD_ACTIVITY_MODULE_AMBIGUOUS', $options);
    }

    /**
     * Build a needs_clarification listing the course sections.
     *
     * @param array[] $sections
     * @param string $lead
     * @return array
     */
    private function build_section_clarification(array $sections, string $lead): array {
        $lines = [$lead, ''];
        $options = [];
        foreach ($sections as $section) {
            $label = $section['name'] !== '' ? $section['name'] : ('Section ' . $section['sectionnum']);
            $lines[] = '- ' . $label . ' [section ' . $section['sectionnum'] . ']';
            $options[] = [
                'sectionnum' => (int)$section['sectionnum'],
                'name' => $label,
            ];
        }
        $lines[] = '';
        $lines[] = 'Reply with the section name (or "top"/"bottom") and I will continue.';

        return $this->clarify(implode("\n", $lines), 'ADD_ACTIVITY_SECTION_AMBIGUOUS', $options);
    }

    /**
     * Format real mod_form field errors into a clarification message.
     *
     * @param string $modname
     * @param array $errors
     * @return string
     */
    private function format_field_errors(string $modname, array $errors): string {
        // Wave 32: a missing required field's message carries the form's own label (module_form_contract).
        $lines = ['I still need some details for the ' . $modname . ' activity:', ''];
        foreach ($errors as $field => $message) {
            $lines[] = '- ' . $field . ': ' . $message;
        }
        return implode("\n", $lines);
    }


    /**
     * Build the success result payload.
     *
     * @param array $created
     * @param int $attempts
     * @return array
     */
    private function build_success_result(array $created, int $attempts): array {
        $cmid = (int)($created['cmid'] ?? 0);
        $modname = (string)($created['modname'] ?? '');
        $name = (string)($created['name'] ?? '');
        $url = (string)($created['url'] ?? '');
        $courseid = 0;
        // Resolve coursecontextid to courseid for the preview reload.
        if (!empty($created['coursecontextid'])) {
            $cc = \context::instance_by_id((int)$created['coursecontextid'], IGNORE_MISSING);
            $courseid = $cc ? (int)$cc->instanceid : 0;
        }

        $message = 'Created the activity "' . $name . '" (' . $modname . ') in the course.';

        $observation = implode("\n", [
            'Created course module cmid=' . $cmid . ' modname=' . $modname . ' name="' . $name . '"'
                . ' (after ' . $attempts . ' attempt(s)).',
            'Activity URL: ' . $url,
        ]);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ($url !== '' ? ' You can open it here: ' . $url : ''),
            'resultid' => null,
            'created_cmid' => $cmid,
            'created_courseid' => $courseid,
            'created_modname' => $modname,
            'created_name' => $name,
            'activity_url' => $url,
            'observation_full' => $observation,
        ];
    }

    /**
     * Build an error result payload.
     *
     * @param string $message
     * @return array
     */
    private function build_error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
        ];
    }
}
