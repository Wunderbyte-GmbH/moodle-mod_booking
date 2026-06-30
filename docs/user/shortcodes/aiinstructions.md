[Back to shortcode index](README.md)

# `[aiinstructions]`

Renders the inline **Booking Agent** / AI chat UI for one booking activity.

This shortcode embeds the booking agent template `mod_booking/aiinstructions` directly into a page.

---

## Syntax

```text
[aiinstructions cmid="42"]
```

---

## Required parameters

| Parameter | Meaning |
|-----------|---------|
| `cmid="42"` | The course module id of the target booking activity. |

---

## Behaviour details

- The shortcode validates the booking shortcode prerequisites first (enabled, password, PRO licence, valid `cmid`).
- It loads the booking activity via `get_course_and_cm_from_cmid($cmid, 'booking')`.
- Authorisation is delegated to `bookingextension_agent\local\wbagent\authorization_service::can_use()`.
- If the current user is not allowed to use the agent, the shortcode returns an empty string.
- If the booking extension agent class `bookingextension_agent\local\wbagent\aiready` is missing, the shortcode also returns an empty string.
- If everything is available, the shortcode renders the Mustache template `mod_booking/aiinstructions` with the data exported by `aiready`.

---

## Example

```text
[aiinstructions cmid="42"]
```

---

## When to use it

Use this shortcode when you want to expose the Booking Agent outside the default booking activity page, but still in the context of one concrete booking activity.

---

## Related docs

- [Developer guide: Booking Agent workflow](../../../bookingextension/agent/classes/local/wbagent/README_AGENT.md)
- [courselist](courselist.md) — embed the booking options table for the same activity
