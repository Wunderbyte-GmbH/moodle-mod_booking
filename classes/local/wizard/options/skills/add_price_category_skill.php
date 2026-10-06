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

namespace mod_booking\local\wizard\options\skills;

use context_system;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\local\pricecategories_handler;

/**
 * Task definition for booking.add_price_category.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_price_category_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** @var string Capability that allows maintaining the site-wide price category list. */
    private const CAPABILITY = 'mod/booking:managepricecategories';

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.add_price_category';

    /**
     * Price categories are a site-wide list, not activity-scoped, so this skill operates at the
     * system context and needs no booking-activity target. It is gated by its own capability since
     * run 23: moodle/site:config guarded it before, which no manager holds, so the role that owns
     * the booking area could never reach it. Declaring this
     * keeps it correct over MCP (which runs at the system context) and exempt from the module/option
     * target contract required of activity-scoped mutating skills.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, [self::CAPABILITY]);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Human-readable preview of the new price category (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        // W32 APC-1: the command may carry only the name; the card shows the key the skill will store.
        $input['identifier'] = self::identifier_from_input($input);
        return option_preview_builder::add_price_category_descriptor($input);
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'mod_booking.pricing',
                'triggers' => ['price', 'preise', 'preis', 'cost', 'kosten', 'price category', 'pricecat'],
                'guidance' => [
                    '- Use a "prices" object keyed by price category identifier, e.g. {"default": 10, "student": 20}.',
                    '- If a requested price category is unknown, add it via mod_booking.add_price_category.',
                    // W32: "use confirmation_request first" made the constructor answer with a confirmation that
                    // carried no command (APC-3 L31 call 61588, L30 call 60114); the engine asks for confirmation
                    // itself once the command is built.
                    '- If the skill reports a duplicate identifier and the user confirms keeping it,',
                    '  send the command again with override token duplicate_identifier.',
                ],
            ],
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector/constructor window (#2423, APC-1 "tariff for apprentices").
            'description' => 'Create a new price category — a tariff or price group such as students, members or apprentices '
                . '(identifier + name) used in option prices. Use this when users ask to add or manage named price types. Requires '
                . 'site-level configuration capability.',
            'when' => 'The user wants a new price category (a tariff or price group used in option prices) added.',
            'is' => 'A tariff or price group used in option prices.',
            'not' => 'A booking option (create_option); a custom option field (create_option_field).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                // W32 APC-1 (L38 call 71807): a required technical key made the constructor ask the user for it
                // although the request named the tariff. The name alone is enough: the skill derives the key.
                // APC-3 (L41 thread 12590 call 79531 "fermes", L43 thread 13159 call 82923 "âgés" for « retraités »,
                // both counted as clean): asked for a key in a character format, the constructor sent only an identifier
                // that is not the user's word, and no name. Making the key is the skill's folding, not the model's;
                // the name is copied as written. Both texts fit the 159-character card window.
                'identifier' => [
                    'type' => 'string',
                    'description' => 'Technical key, only when the user gives one. Otherwise leave it out: the skill derives '
                        . 'the key from name.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the category exactly as the user wrote it: same language, same spelling, never '
                        . 'translated or replaced.',
                    'required' => false,
                ],
                'defaultvalue' => [
                    'type' => 'number',
                    'description' => 'Default price value for this category.',
                    'required' => false,
                ],
                'pricecatsortorder' => [
                    'type' => 'integer',
                    'description' => 'Optional explicit sort order.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Optional override tokens for confirmed exceptions (e.g. duplicate_identifier).',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Mirrors check_structure(): one of the two names the category.
                'required_groups' => [
                    ['identifier', 'name'],
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.confirm_duplicate_price_category',
                'description' => 'User explicitly confirms creating/keeping a duplicate price category identifier.',
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $identifier = self::identifier_from_input($input);
        if ($identifier === '') {
            $errors[] = $this->localized_string('agent_booking_pricecat_identifier_required', null, $lang);
        } else if (!preg_match('/^[a-z0-9_-]+$/i', $identifier)) {
            $errors[] = $this->localized_string('agent_booking_pricecat_identifier_invalid', null, $lang);
        }

        if (isset($input['defaultvalue']) && !is_numeric($input['defaultvalue'])) {
            $errors[] = $this->localized_string('agent_booking_pricecat_defaultvalue_numeric', null, $lang);
        } else if (isset($input['defaultvalue']) && (float)$input['defaultvalue'] < 0) {
            $errors[] = $this->localized_string('agent_booking_pricecat_defaultvalue_nonnegative', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * The identifier is a technical key: diacritics folded to ASCII, case lowered.
     *
     * F78 (baseline run 31, APC-3): the planner built "retraités" from « retraités » and the key check
     * refused it, while the same prompt had produced "retraites" the run before. The display name keeps
     * its accents; anything that is still no key after folding is refused as before.
     *
     * @param string $identifier
     * @return string
     */
    public static function fold_identifier(string $identifier): string {
        return strtolower(trim((string)\core_text::specialtoascii(trim($identifier))));
    }

    /**
     * The identifier to use: the given one folded, or - when only a name is given - the name as a key.
     *
     * The derivation is structural (fold to ASCII, lower case, every run of non-key characters becomes "_"),
     * the same for every language; the display name keeps the user's spelling.
     *
     * @param array $input
     * @return string Empty when neither an identifier nor a usable name is given.
     */
    public static function identifier_from_input(array $input): string {
        $identifier = self::fold_identifier((string)($input['identifier'] ?? ''));
        if ($identifier !== '') {
            return $identifier;
        }
        $fromname = self::fold_identifier((string)($input['name'] ?? ''));
        $fromname = trim((string)preg_replace('/[^a-z0-9_-]+/', '_', $fromname), '_-');
        return $fromname;
    }

    /**
     * Deep preflight validation and normalized input preparation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            return $this->invalid($this->build_preflight_issues((array)($structure['errors'] ?? [])));
        }

        $lang = $this->get_output_language($input);
        // Maintaining the price category list is booking work, so it has its own capability since
        // run 23. moodle/site:config gated it before, which no manager holds - the role that owns
        // this area could never reach it (all four APC prompts refused, correctly and uselessly).
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([[
                'code' => 'PRICE_CATEGORY_CAPABILITY_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_add_pricecat_capability_required', 'booking'),
            ]]);
        }

        $identifier = self::identifier_from_input($input);
        $override = array_map('strval', (array)($input['override'] ?? []));
        $allowduplicate = in_array('duplicate_identifier', $override, true);

        $handler = new pricecategories_handler();
        $existing = $handler->get_pricecategories_indexed_by_identifier();
        if (
            $identifier !== ''
            && isset($existing[strtolower($identifier)])
            && (int)$existing[strtolower($identifier)]->disabled === 0
            && !$allowduplicate
        ) {
            return $this->confirmable($input, [[
                'code' => 'DUPLICATE_PRICE_CATEGORY_CONFIRM_REQUIRED',
                'severity' => 'needs_confirmation',
                'user_question' => $this->localized_string('agent_booking_pricecat_duplicate_user_question', $identifier, $lang),
                'remedy_options' => ['CONFIRM_DUPLICATE_IDENTIFIER', 'USE_DIFFERENT_IDENTIFIER'],
            ]]);
        }

        $preparedinput = $input;
        $preparedinput['identifier'] = $identifier;
        $preparedinput['name'] = trim((string)($input['name'] ?? ''));
        $preparedinput['defaultvalue'] = isset($input['defaultvalue']) ? (float)$input['defaultvalue'] : 0.0;
        if (isset($input['pricecatsortorder'])) {
            $preparedinput['pricecatsortorder'] = (int)$input['pricecatsortorder'];
        }

        return $this->pass($preparedinput);
    }

    /**
     * Convert messages to preflight issues.
     *
     * @param array $messages
     * @return array<int,array<string,string>>
     */
    private function build_preflight_issues(array $messages): array {
        $issues = [];
        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $issues[] = [
                'code' => 'PRICE_CATEGORY_PREFLIGHT_BLOCKED',
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
        }
        return $issues;
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if (!has_capability(self::CAPABILITY, context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_add_pricecat_capability_required', 'booking'),
                'resultid' => null,
            ];
        }

        $identifier = trim((string)($input['identifier'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $name = ucfirst(str_replace(['_', '-'], ' ', $identifier));
        }
        $defaultvalue = isset($input['defaultvalue']) ? (float)$input['defaultvalue'] : 0.0;

        $handler = new pricecategories_handler();
        $result = $handler->upsert_pricecategory(
            $identifier,
            $name,
            $defaultvalue,
            isset($input['pricecatsortorder']) ? (int)$input['pricecatsortorder'] : null
        );

        $outputlang = $this->get_output_language($input);
        if (is_array($result)) {
            $result['usermessage'] = $this->localized_string(
                'agent_booking_pricecat_created',
                $identifier,
                $outputlang
            );
            $result['outputlang'] = $outputlang;
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
        }

        return $result;
    }
}
