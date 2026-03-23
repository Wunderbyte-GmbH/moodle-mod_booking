# CSV import columns for booking option fields

This document describes which CSV columns can trigger or populate field classes in
`mod/booking/classes/option/fields`.

## How import matching works

During import, each field class is considered present when at least one of these is true:

- The CSV contains a column with the short class name (for example `text` for class `text`).
- The CSV contains one of the class-specific `alternativeimportidentifiers`.
- The class is marked as necessary in option form config.

Relevant logic:

- `fields_info::ignore_class()` checks class-name columns and `alternativeimportidentifiers`.
- `field_base::set_data()` uses the short class name as default key.

## Validated default class-name CSV columns

After checking all field classes against both `set_data()` and `instance_form_definition()`,
these class names are directly used as keys and can be treated as reliable default CSV columns:

- `addastemplate`, `address`, `addtocalendar`, `aftercompletedtext`, `aftersubmitaction`, `annotation`
- `availability`, `beforebookedtext`, `beforecompletedtext`, `bookingoptionimage`, `canceluntil`, `certificate`
- `competencies`, `courseid`, `coursestarttime`, `credits`, `description`, `disablebookingusers`, `disablecancel`
- `duration`, `enrolmentstatus`, `howmanyusers`, `id`, `identifier`, `institution`, `invisible`, `json`
- `location`, `maxanswers`, `maxoverbooking`, `minanswers`, `moveoption`, `multiplebookings`, `notificationtext`
- `optiontype`, `pollurl`, `removeafterminutes`, `responsiblecontact`, `returnurl`, `text`, `titleprefix`
- `waitforconfirmation`

## Class names that are not effective direct CSV data keys

These class names exist as classes, but key usage in `set_data()` / `instance_form_definition()`
shows they are not direct data columns in practice.

Use the listed keys instead where applicable.

| Class name | Use this instead |
| --- | --- |
| `applybookingrules` | `skipbookingrulesmode`, `skipbookingrules` |
| `bookusers` | `useremail`, `username`, `timebooked`, `completed` |
| `bookingopeningtime` | `restrictanswerperiodopening` (or easy mode fields) |
| `bookingclosingtime` | `restrictanswerperiodclosing` (or easy mode fields) |
| `courseendtime` | Use `optiondates` date keys (for example `courseendtime`, `courseenddate`) |
| `entities` | `location`, `entity` |
| `groupid` | `resetgroupid` |
| `optiondates` | `coursestarttime`, `courseendtime`, `dayofweektime`, `semesterid`, `optiondateid_0`, `optiondateid_1`, ... |
| `price` | `useprice` plus dynamic price-category identifiers |
| `recurringoptions` | `repeatthisbooking` and recurring control fields |
| `sharedplaces` | `sharedplaceswithoptions`, `sharedplacespriority` |
| `shoppingcart` | `sch_allowinstallment` (and shopping-cart related `sch_*` fields) |
| `slotbooking` | `slot_enabled` and `slot_*` fields |
| `teachers` | `teacheremail` |
| `template` | `optiontemplateid` |

Usually not meaningful as CSV data columns (technical/derived):

- `actions`, `addtogroup`, `attachment`, `customfields`, `duplication`
- `easy_availability_previouslybooked`, `easy_availability_selectusers`
- `easy_bookingclosingtime`, `easy_bookingopeningtime`, `easy_text`
- `elective`, `eventslist`, `formconfig`, `prepare_import`
- `priceformulaadd`, `priceformulamultiply`, `priceformulaoff`
- `subbookings`, `timecreated`, `timemodified`

## Additional CSV columns (alternativeimportidentifiers)

These aliases are configured explicitly in the classes and can trigger the class during import.

| Class | Additional CSV columns |
| --- | --- |
| `applybookingrules` | `skipbookingrulesmode`, `skipbookingrules` |
| `availability` | `boavenrolledincourse`, `boavenrolledincohorts`, `bo_cond_customform_restrict` |
| `bookusers` | `useremail`, `username`, `timebooked`, `completed` |
| `competencies` | `competency` |
| `courseid` | `enroltocourseshortname`, `courseid`, `coursenumber`, `chooseorcreatecourse` |
| `entities` | `location`, `entity` |
| `optiondates` | `coursestarttime`, `courseendtime`, `coursestartdate`, `courseenddate`, `dayofweektime`, `semesterid`, `starddate`, `enddate`, `optiondateid_0`, `optiondateid_1` |
| `price` | `useprice` |
| `recurringoptions` | `repeatthisbooking` |
| `sharedplaces` | `sharedplaceswithoptions`, `sharedplacespriority` |
| `shoppingcart` | `sch_allowinstallment` |
| `slotbooking` | `slot_enabled` |
| `teachers` | `teacheremail` |
| `text` | `name` |

Note on `enrolmentstatus`:

- `enrolmentstatus` currently defines `alternativeimportidentifiers = ['']`.
- This does not add a usable alias column. Effective import key is `enrolmentstatus`.

## Special dynamic import columns

Some imports use dynamic keys that are not static aliases:

- `price`: category identifiers from `booking_pricecategories.identifier` are accepted during import.
  `fields_info::ignore_class()` has special logic so `price` is not skipped when such category columns are present.
- `availability`: `boavenrolledincourseoperator` and `boavenrolledincohortsoperator` are optional helper columns,
  used together with `boavenrolledincourse` / `boavenrolledincohorts`.
- `text`: in import mode, value fallback is `text` -> `title` -> `name`.

## Maintenance rule for new import keys

If a class reads import keys in `set_data()` (or other import path) that are different from
the class short name, add those keys to `alternativeimportidentifiers` so the class is not
skipped by `fields_info::ignore_class()`.
