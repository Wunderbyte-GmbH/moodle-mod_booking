[Back to parent section](README.md)

# Action After Booking: Execute REST Script

**Class:** `mod_booking\bo_actions\action_types\executerestscript`
**PRO required:** Yes 🔒

---

## What it does

The **Execute REST script** action calls an external REST API endpoint when a booking is confirmed. The response from the endpoint is stored and can be retrieved later using the `{restresponse}` placeholder in email templates.

Use cases:

- Notify an external CRM or ERP system when a participant registers.
- Trigger an external onboarding workflow via webhook.
- Call a badge or certificate issuance API.
- Synchronise booking data with a third-party LMS or HR system.

## Click-by-click setup

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option management: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click Edit on the target option.
4. In the option form, scroll to Booking actions.
5. Click Add action and select Execute REST script.
6. Enter REST script URL and Secret token, then set Number of days.
7. Save the option.
8. Make one test booking and check whether the external endpoint received the request.

---

## Configuration fields

| Field | Description |
|-------|-------------|
| **Action name** (`boactionname`) | An internal label for this action (shown in the action list). |
| **REST script URL** (`rest_script`) | The full URL of the REST endpoint to call (must be a valid URL). |
| **Number of days** (`numberofdays`) | Optional: delays the REST call by this many days. `0` = call immediately. |
| **Secret token** (`secrettoken`) | A bearer token or secret sent with the request for authentication. Stored as plain text — use a dedicated read-only token. |
| **Include custom form data** (`customformparameter`) | When enabled, any data submitted via the [Custom form](../booking_conditions/custom_form.md) condition is included in the request body as additional parameters. |

---

## How the request is sent

The action constructs a request to the configured URL containing:

- The booking answer JSON (`bajson`) as the primary payload.
- The secret token in the `Authorization` header (or as a query parameter, depending on the implementation).
- Custom form data if `customformparameter` is enabled.

After a successful response, a `rest_script_success` Moodle event is fired and the response body is stored in the booking answer. The response is accessible via `{restresponse}` in email templates.

---

## Security considerations

- The secret token is stored as plain text in the booking option's JSON data. Use a token with minimal permissions.
- The REST endpoint URL is stored as plain text. Do not include credentials in the URL.
- Only site administrators should be able to configure booking actions. Ensure the `showboactions` setting is restricted to trusted staff.

---

## Use case: Notify a CRM on registration

**Scenario:** When a participant books a training course, your external CRM should receive a webhook notification.

| Setting | Value |
|---------|-------|
| Action name | CRM notification |
| REST script URL | `https://crm.example.com/api/v1/booking-webhook` |
| Number of days | `0` (immediate) |
| Secret token | `your-webhook-secret-token` |
| Include custom form data | ✓ (if participants filled in custom fields during booking) |

---

## See also

- [Actions after booking overview](README.md)
- [Set user profile field action](userprofilefield.md)
- [Placeholder: `{restresponse}`](../placeholders/README.md#7-booking-status-and-capacity)
- [Custom form condition](../booking_conditions/custom_form.md)
