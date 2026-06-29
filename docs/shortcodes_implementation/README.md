# Shortcodes Implementation Guide

This document is specifically designed for developers and AI agents that need to interact with, modify, or extend the shortcodes functionality in `mod_booking`. It covers the internal mechanisms, argument parsing, database interactions, and an exhaustive list of all implemented shortcodes.

## Analysis of Existing Shortcode Documentation

Before diving into the implementation, here is an analysis of where shortcodes are currently referenced in our documentation:
1. **`docs/shortcodes/` (The User Manual)**: This is the primary user-facing documentation. It includes syntax and examples for 14 shortcodes. Notably, it **misses the `myfavorites` shortcode** which is fully implemented in the backend. It is great for end-users but does not explain *how* the PHP code executes.
2. **`docs/README.md`**: Provides quick links to the `docs/shortcodes/` folder.
3. **`docs/capabilities/README.md`**: Outlines the Moodle capabilities required to render certain shortcode views (e.g., `mod/booking:view`).
4. **`docs/developer-guides/ARCHITECTURE.md`**: Briefly lists `classes/shortcodes.php` as the entry point for shortcode registrations.

Agents reading this directory should consider `docs/shortcodes/` as the "frontend usage guide" and `docs/shortcodes_implementation/` (this document) as the "backend logic guide".

---

## Architecture and Core Mechanisms

All shortcodes are defined as static methods inside `\mod_booking\shortcodes` (`classes/shortcodes.php`). 

### Argument Parsing and Validation (`shortcodes_handler.php`)
Every shortcode method begins by calling `shortcodes_handler::validatecondition()`. This method:
1. Verifies if shortcodes are enabled globally (`shortcodesoff` setting).
2. Checks password protection if `shortcodespassword` is set.
3. Validates the active PRO license.
4. Ensures all required arguments (e.g., `cmid`, `optionid`) are present.
5. Removes quotes from arguments using `fix_args()`.

### The `wunderbyte_table` and Queries
Most shortcodes render a list of booking options. They achieve this by:
1. Initialising a table: `$table = self::init_table_for_courses(...)`.
2. Applying standard view parameters: `view::apply_standard_params_for_bookingtable(...)`.
3. Extracting custom field filters using `self::get_columnfilters($args)` and `self::set_customfield_wherearray()`.
4. Constructing the database query using `booking::get_options_filter_sql()`. This function builds the complex `SELECT`, `FROM`, and `WHERE` clauses required to fetch options.
5. Setting the SQL on the table object: `$table->set_filter_sql(...)`.
6. Outputting the HTML: `$out = $table->outhtml($perpage, true);`.

---

## Shared Shortcode Arguments

The following arguments are processed by shared helper methods (e.g., `set_common_table_options_from_arguments`) and apply to almost all table-based shortcodes:

| Argument | Description | Internal Handling |
|----------|-------------|-------------------|
| `perpage` | Number of items per page. | `self::check_perpage($args)`. Defaults to 0 (infinite scroll) if not provided. |
| `infinitescrollpage` | Items per scroll step. | Checked in `check_perpage`. Defaults to 30. |
| `sortby` / `sortorder` | Default column sorting. | Sets `$table->sort_default_column` and `$table->sort_default_order`. |
| `filter` / `search` / `sort` | UI toggles. | Booleans passed into `view::apply_standard_params_for_bookingtable`. |
| `exclude` | Comma-separated list of columns to hide. | Extracted into an array, used in `array_diff` against `$possibleoptions`. If `rightside` is included, `$table->subcolumns['rightside']` is unset. |
| `requirelogin` | Bypasses login requirement if false. | Sets `$table->requirelogin`. |
| `type` | Determines view type (`list`, `cards`, etc.). | Mapped to `viewparam` values. |
| `all` | Show all, past only, or future only options. | Handled by `self::applyallarg($args, $where)`. |
| `filteronloadactive` | Start filters visible. | Passed to the table view logic. |
| `customfieldfilter` | Add UI filters for custom fields. | Split by comma, creates new `customfieldfilter` instances. |
| `cfinclude` | Changes custom field filtering logic to `OR`. | Modifies the operator in `set_customfield_wherearray`. |

---

## Exhaustive List of Shortcodes

### 1. `recommendedin`
- **Purpose**: Shows options where the `recommendedin` custom field matches the current course's shortname.
- **Specific Arguments**: None required.
- **Result**: A table view matching `recommendedin` via `LIKE` SQL patterns.

### 2. `courselist`
- **Purpose**: Displays options from a specific booking activity.
- **Specific Arguments**: `cmid` (Required).
- **Result**: Table filtered by `bookingid`.

### 3. `fieldofstudyoptions`
- **Purpose**: Shows options related to a specific cohort/group "field of study".
- **Specific Arguments**: `group` (Optional, defaults to user's current group).
- **Result**: Finds cohorts, matches courses, and filters options by `recommendedin` matching course shortnames.

### 4. `bookingoptionview`
- **Purpose**: Renders a single "Book now" or CTA button for a specific option.
- **Specific Arguments**: `optionid` (Required), `inlinestartpage` (Optional).
- **Result**: Returns button HTML via `booking_bookit::render_bookit_button()`.

### 5. `linkbacktocourse`
- **Purpose**: Generates links to booking options associated with the current Moodle course.
- **Specific Arguments**: None.
- **Result**: A series of anchor tags targeting `optionview.php`.

### 6. `allbookingoptions`
- **Purpose**: Shows options across multiple or all booking activities.
- **Specific Arguments**: `cmid` (Optional, comma-separated), `courseid` (Optional).
- **Result**: Merges `cmid` and `courseid` logic to display an aggregated table.

### 7. `mycourselist`
- **Purpose**: Shows the current user's booked options.
- **Specific Arguments**: `statuswaitinglist` (Optional - includes waiting list items if true).
- **Result**: Uses `booked_users::get_bookings_for_user_sql()` and sets `bookingstatus` filter.

### 8. `myfavorites`
- **Purpose**: Displays options the user has marked as favorites. (Note: Missing from user docs).
- **Specific Arguments**: `favorites` (Optional).
- **Result**: Joins with `{booking_favorites}` table to show user's favorite options.

### 9. `fieldofstudycohortoptions`
- **Purpose**: Similar to `fieldofstudyoptions` but strictly cohort-based.
- **Specific Arguments**: `cohort` (Optional, defaults to user's cohorts).
- **Result**: Joins cohort members and courses to filter `recommendedin`. Only works on MySQL/MariaDB and PostgreSQL.

### 10. `bulkoperations`
- **Purpose**: Administrative view for bulk actions.
- **Specific Arguments**: `columns`, `filter` (comma-separated key=value pairs).
- **Result**: Renders `bulkoperations_table`.

### 11. `executeservice`
- **Purpose**: Triggers internal service classes (used for testing or specific backend tasks).
- **Specific Arguments**: `service` (Required).
- **Result**: Calls `execute()` on the corresponding service class.

### 12. `bookingoptionsfromcondition`
- **Purpose**: Outputs completed options for certificate conditions.
- **Specific Arguments**: `certid` (Required).
- **Result**: Interacts with the `certificatetemplates` plugin to evaluate rules.

### 13. `listtoapprove`
- **Purpose**: Shows bookings pending approval.
- **Specific Arguments**: `cmid` (Required).
- **Result**: Renders options with `MOD_BOOKING_STATUSPARAM_WAITING_FOR_APPROVAL`.

### 14. `supervisorteam`
- **Purpose**: Shows bookings of users in a supervisor's team.
- **Specific Arguments**: None required.
- **Result**: Uses `user_handler::get_users_in_supervisors_team_sql()` to filter users.

### 15. `aiinstructions`
- **Purpose**: Renders the inline Booking Agent UI.
- **Implementation Note**: Invoked dynamically via Moodle's shortcode filter. Rendering logic resides externally (e.g. `bookingextension_agent`).

