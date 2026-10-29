# Certificate Conditions System

Certificate conditions issue certificates automatically when booking options are completed. Every saved
condition (table `booking_cert_cond`) consists of three parts:

1. **Filter** (optional) – the user must match, e.g. a user profile field value.
2. **Condition** – what the user must have completed.
3. **Action** (optional) – what happens when filter and condition pass, e.g. issue a certificate.
   A condition saved with "No action" never issues anything on its own; it serves as building block
   of a combination (see below).

Each part is pluggable via a simple interface-based design.

## Directory Structure

```
certificate_conditions/
├── README.md (this file)
├── certificate_conditions.php           # Main helper class (list, save, delete, evaluate)
├── certificate_conditions_interface.php # Condition interface
├── completion_counter_trait.php         # Shared counting/gating for option based conditions
├── conditions_info.php                  # Condition discovery/instantiation and form selector
├── option_conditions_info.php           # Info about conditions on the booking option form
├── filter_interface.php / filters_info.php
├── action_interface.php / actions_info.php
├── conditions/
│   ├── bookingoption.php                # N of the selected booking options completed
│   ├── instance.php                     # N options of a booking instance completed
│   ├── taggedoptions.php                # N options tagged with this condition completed
│   └── combination.php                  # Other conditions combined with AND / OR
├── filters/
│   └── userprofilefield.php
└── actions/
    └── createcertificate.php
```

## Storage

- `booking_cert_cond`: `contextid` (1 = site wide, otherwise a booking instance's module context), `name`,
  `filterjson`, `logicjson`, `actionjson`, `isactive`.
- `booking_cert_cond_item`: lookup rows per condition (`component`, `area`, `itemid`):
  - `area = bookingoption`: the options of a `bookingoption` or `taggedoptions` condition,
  - `area = bookinginstance`: the instance of an `instance` condition (one sentinel row),
  - `area = certcondition`: the building blocks of a `combination` (one row per leaf).

`logicjson` always carries `conditionname` (the short class name) and the condition specific settings.

## Evaluation Flow

1. A user completes a booking option, `bookingoption_completed` fires (or the manual trigger on the
   "Manage users" page builds the same event).
2. `observer::bookingoption_completed()` calls `certificate_conditions::evaluate_certificate_conditions()`
   unless `certificatemanualtrigger` is set.
3. For each active condition `evaluate_single_condition()` instantiates filter, condition and action,
   calls `filter->evaluate($context)` and `condition->evaluate($context)` and, when both pass and an
   action is configured, `action->execute_action($context, $record)`.

The context object contains `event`, `userid` and `optionid`. Every option based condition **gates**
itself: only the completion of one of its own options may trigger it (`targets_option()`), so a
fulfilled condition does not fire again when the user completes an unrelated option.

### Performance

The evaluation is designed to stay cheap on large sites:

- `certificate_conditions::get_candidate_condition_ids()` uses the lookup rows of `booking_cert_cond_item`
  to evaluate only the conditions that observe the completed option (its own id, its booking instance,
  and the combinations built on those). Conditions without lookup rows are always evaluated.
- The completion state of a user is read with **one query over the user's answers** (by option ids, or by
  booking id for instance conditions) and reduced with the same precedence rule as `booking_answers`.
  The answers of the observed options are never loaded, so the cost does not grow with the number of
  options or bookings of an instance.
- `instance::targets_option()` compares the booking id of the completed option instead of loading the
  option list; the list is only loaded (one query, ids only) to cap the required count.

## The condition interface

Besides the form related methods (`add_logic_to_mform`, `save_condition`, `save_items`, `set_defaults`,
`validate`) and the loaders (`set_logicdata($record)` is the single entry point and also loads the items;
`set_conditiondata_from_json()` parses JSON only), a condition provides:

- `get_targeted_optionids(): array` – the option ids it observes,
- `get_requiredcount(): int` – how many of them must be completed,
- `get_completed_optionids_for_user(int $userid, array $excludeoptionids = []): array` – the completed
  ones, sorted by option id,
- `get_description(stdClass $record): string` – a short summary for the list of conditions,
- `evaluate(stdClass $context): bool`.

`completion_counter_trait` implements the counting, the gating and `evaluate_completion()` on top of
`get_targeted_optionids()` and `get_requiredcount()`; the three option based conditions use it.

## Combinations (AND / OR)

The `combination` condition references other saved conditions ("leaves") by id and arranges them in
groups. `logicjson`:

```json
{
  "conditionname": "combination",
  "operator": "AND",
  "uniqueoptions": 1,
  "groups": [
    {"operator": "AND", "conditionids": [11, 12, 13]},
    {"operator": "OR",  "conditionids": [11, 12, 13]}
  ]
}
```

- Within a group the leaves are combined with the group operator, the groups with the top level operator.
- `uniqueoptions`: a completed option counts for one leaf only. Leaves are evaluated in order and consume
  the options they used; a failed group or OR branch consumes nothing. Options observed by several leaves
  are consumed last, so they stay available for later leaves (greedy, no exact matching).
- Leaf filters, leaf actions and the active flag of leaves are ignored inside a combination.
- A combination cannot be a leaf of another combination (prevented in the form and in the evaluator).
- Leaves must be saved in the **same context** as the combination: global combinations use global
  conditions, a combination of a booking instance uses that instance's conditions
  (`combination::get_eligible_leaf_conditions()`, enforced again in `validate()`). The list of conditions
  marks combinations and building blocks with badges, shows "Used in" on leaves, renders the leaves of a
  combination as clickable chips and flags conditions without action that are used nowhere, as well as
  combinations that still reference a condition of another context.
- Deleting a leaf removes it from every combination using it (`combination::remove_leaf_from_combinations()`).
- Evaluation is state based: the combination is checked at every completion of an option observed by one
  of its leaves; the "issue multiple certificates" settings decide whether a fulfilled combination issues
  again.

Example "one option in each of the instances A, B and C, then one other option in any of them": three
`instance` leaves A, B, C (required count 1, action "No action") and a combination with operator AND,
group 1 = AND(A, B, C), group 2 = OR(A, B, C), unique options enabled.

## Creating Custom Components

Add a class to `conditions/`, `filters/` or `actions/` implementing the respective interface; it is
discovered automatically. Add the language strings `condition_<name>` / `filter_<name>` /
`action_<name>` (used in the form selectors and the list) to `lang/en/booking.php`. Form element names
should be prefixed with `condition_<name>_`, `filter_<name>_` or `action_<name>_`.

## Integration Points

- `certificate_conditions::get_rendered_list_of_saved_conditions($contextid)` – renders the list,
- `certificate_conditions::save_certificate_condition($data)` + `save_items_for_condition($id, $data)`,
- `certificate_conditions::delete_condition($id)`,
- `certificate_conditions::evaluate_certificate_conditions($event, $userid, $optionid)`,
- `option_conditions_info::add_static_info_to_mform()` – lists the conditions (and combinations)
  targeting a booking option on the option form.

Tests live in `tests/certificates/certificateconditions/`.
