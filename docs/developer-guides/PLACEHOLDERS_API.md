[Back to parent section](../README.md)

# Placeholders — Developer API

This guide explains how to implement a custom placeholder class for the mod_booking placeholder system.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [How placeholders work](#1-how-placeholders-work)
2. [Implementing a placeholder class](#2-implementing-a-placeholder-class)
3. [Registering your placeholder](#3-registering-your-placeholder)
4. [Providing placeholders from a booking extension](#4-providing-placeholders-from-a-booking-extension)
5. [Caching considerations](#5-caching-considerations)

---

## 1. How placeholders work

When `placeholders_info::render_text()` is called with a text containing `{tokens}`:

1. All `{token}` patterns in the text are extracted with a regex.
2. For each token, the class name is derived: the token `{myfieldname}` maps to class `myfieldname` in the namespace `mod_booking\placeholders\placeholders\`.
3. If the class exists and `is_applicable()` returns `true`, `return_value()` is called.
4. The returned string replaces the `{token}` in the text.

Namespaces searched (in order):
1. `mod_booking\placeholders\placeholders\` — core placeholder classes
2. `bookingextension_<pluginname>\placeholders\` — from installed booking extensions

---

## 2. Implementing a placeholder class

Create a file in `classes/placeholders/placeholders/` named after the token. For a `{myvalue}` token, create `myvalue.php`:

```php
namespace mod_booking\placeholders\placeholders;

use mod_booking\placeholders\placeholder_base;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Placeholder for myvalue — returns a custom value.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class myvalue extends placeholder_base {

    /**
     * Returns the value for this placeholder.
     *
     * @param int $cmid         Course module ID
     * @param int $optionid     Booking option ID
     * @param int $userid       User ID
     * @param int $installmentnr Instalment number (for pricing placeholders)
     * @param int $duedate      Due date timestamp (for instalment placeholders)
     * @param float $price      Price value (for pricing placeholders)
     * @param string $text      The full text being processed (passed by reference)
     * @param array $params     Additional parameters (passed by reference)
     * @param int $descriptionparam Description context (website, iCal, etc.)
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE
    ): string {
        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        // Use the singleton cache pattern:
        $cachekey = "$classname-$optionid";
        if (isset(placeholders_info::$placeholders[$cachekey])) {
            return placeholders_info::$placeholders[$cachekey];
        }

        // Compute your value
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $value = $settings->text ?? ''; // Replace with your actual logic

        // Cache the result
        placeholders_info::$placeholders[$cachekey] = $value;

        return $value;
    }

    /**
     * Whether this placeholder should be evaluated at all.
     * Return false to completely skip this placeholder.
     */
    public static function is_applicable(): bool {
        return true;
    }

    /**
     * Whether this placeholder works inside poll URLs.
     * Only simple text values (not HTML) are safe to use in URLs.
     */
    public static function for_pollurl(): bool {
        return false; // Set to true for simple text values
    }
}
```

---

## 3. Registering your placeholder

Placeholder classes are auto-discovered — no registration is required. Simply placing a class in `classes/placeholders/placeholders/` with the correct name is sufficient.

The token name is the filename without `.php`. Class name must match the filename exactly.

---

## 4. Providing placeholders from a booking extension

Booking extensions can provide their own placeholders. Place them in:

```
bookingextension_<pluginname>/classes/placeholders/<tokenname>.php
```

The namespace should be `bookingextension_<pluginname>\placeholders\`. These are automatically discovered alongside the core placeholder classes.

---

## 5. Caching considerations

- The `placeholders_info::$placeholders` static array is an in-memory cache within a single request.
- Design your cache key to be appropriate for the data's scope:
  - **Per user:** `"classname-$userid"`
  - **Per option:** `"classname-$optionid"`
  - **Per user and option:** `"classname-$userid-$optionid"`
- Do not cache sensitive user data across requests (the in-memory cache is request-scoped, not persistent).

---

## See also

- [Architecture overview](ARCHITECTURE.md)
- [Placeholders user documentation](../placeholders/README.md)
- [Booking extensions API](BOOKING_EXTENSIONS_API.md) — For adding placeholders via an extension
