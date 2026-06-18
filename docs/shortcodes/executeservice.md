[Back to shortcode index](README.md)

# `[executeservice]`

Runs an internal PHP service class through a shortcode.

This is a **developer / site-admin only** shortcode.

---

## Syntax

```text
[executeservice service="mod_booking\service\myservice"]
[executeservice service="mod_booking\service\myservice" arg1="foo" arg2="bar"]
```

---

## Required parameters

| Parameter | Meaning |
|-----------|---------|
| `service="Fully\\Qualified\\ClassName"` | The PHP class whose static `execute()` method should be called. |

---

## Security and permission rules

The shortcode only executes when:

- the user is a **site admin**, and
- the `service` parameter is present.

Otherwise it returns `nopermissiontoaccesscontent`.

---

## Behaviour details

- After validation, the shortcode removes the `service` attribute from the argument array.
- All remaining shortcode attribute values are forwarded positionally via:
  - `serviceclass::execute(...array_values($args))`
- The shortcode itself returns an empty string after execution.
- There is no UI wrapper, no confirmation step, and no result rendering.

Because this shortcode can call arbitrary internal service classes, it should only be used in tightly controlled administrator scenarios.

---

## Example

```text
[executeservice service="mod_booking\service\myservice" arg1="value1" arg2="value2"]
```

