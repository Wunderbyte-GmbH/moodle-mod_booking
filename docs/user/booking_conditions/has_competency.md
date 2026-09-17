[Back to parent section](README.md)

# Has Competency

**Class:** `mod_booking\bo_availability\conditions\hascompetency`  
**PRO required:** Yes 🔒

---

## What it does

The **Has competency** condition restricts a booking option so that only users who have been awarded one or more specific Moodle competencies can book it.

Moodle competencies are part of the Competency framework (under *Site administration → Competencies*). A user "has" a competency once it has been rated or assigned to them.

You select which competencies are required and whether the user must have **all** of them (AND) or **at least one** of them (OR).

---

## How to configure it

For non-technical users, use this click path:

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open the options list: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click **Edit** on the target option (or create one first).
4. In the option form, scroll to **Availability / Booking conditions**.
5. Enable this condition and save the option.

> This condition is only available with an active Wunderbyte PRO licence. Without it, a static notice is shown instead of the settings.

### Step 1 — Enable the condition

Check **Restrict to users with competency** to activate the condition and reveal its settings.

### Step 2 — Select competencies

An autocomplete search field lists all competencies defined in Moodle. Select one or more competencies that the user must hold.

![Has competency condition in the option form](pix/has_competency_form_placeholder.png)

### Step 3 — Choose the operator

Select how multiple competencies are combined:

| Operator | Meaning |
|----------|---------|
| **AND** *(default)* | The user must hold **all** selected competencies. |
| **OR** | The user must hold **at least one** of the selected competencies. |

### Step 4 — Override condition (optional)

Check **Allow override by another condition** to let a different condition "unlock" this one for specific users. Choose the override operator (OR / AND) and select which other conditions may override this one.

---

## Effect on the user

| Situation | What the user sees |
|-----------|-------------------|
| User has the required competency/competencies | Normal "Book it" button |
| User does not have the required competency/competencies | Alert: "You do not have the required competency to book this option." |

---

## Override behaviour

Users who hold the capability `mod/booking:overrideboconditions` can book regardless of their competencies.

---

## Prerequisites

The Moodle Competency framework must be enabled on your site (*Site administration → Advanced features → Enable competencies*).  
Competencies must be defined and assigned to users before this condition can be used meaningfully.

---

## Back to overview

[← All booking conditions](README.md)
