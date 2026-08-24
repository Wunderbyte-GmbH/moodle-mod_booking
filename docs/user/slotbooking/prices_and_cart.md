[Back to slot booking](README.md)

# Prices and shopping cart

---

## How a slot price comes about

1. The **price of the booking option** (Price section of the option form) is the **base price of one slot**. The form states this explicitly: *"For slot booking, the initial price in this section is the base price for all slots. Individual slots can then receive their own prices via the slot rules editor."*
2. The participant's **price category** selects which of the option's prices applies, exactly as for normal bookings.
3. **Slot rules** of type *Price adjustment* then modify the price of individual slots — absolute, as a delta, or by a factor. See [Slot rules](slot_rules.md).
4. The **total** of a booking is the sum of the prices of all selected slots.

Participants see the price on each slot in the picker, as coloured price dots on the calendar days, and as a running total under the picker while they select.

---

## Booking a paid slot

Paid slot options are **never booked directly**. The button reads **Add to cart**, and after choosing a slot the participant is told:

> **"Thank you! You have successfully put … into the shopping cart."**

From there, **Proceed to checkout** leads to the shopping cart, where the option appears with the computed slot price. The booking becomes final when the checkout is completed.

While the item waits in the cart, the chosen slot is **held**: it counts as occupied for everybody else, so nobody can take it away during checkout. If the cart expires or the purchase is abandoned, the hold is released and the slot becomes free again.

---

## Changing a booking that costs money

When a participant or a staff member changes the slots of an existing booking, the **price difference** decides what happens. Only genuinely changed slots count — slots that stay in the booking cancel out.

| Situation | What happens |
|-----------|--------------|
| The price stays the same (or the difference is negligible) | The change is applied immediately, no payment involved |
| The new slots are **cheaper** | The change is applied immediately and the difference is credited to the participant: *"You will be refunded … as credit."* |
| The new slots are **more expensive** | The new slots are held, the difference is put into the shopping cart as **"Rebooking: <option>"**, and the change becomes final at checkout |
| **All** slots are removed | The whole booking is cancelled |

The cart entry describes the change as *"Slot change from … to …"*, listing only the slots that actually change.

Staff moving somebody else's slots bypass this entirely: manager moves are **price-neutral**, no cart, no refund.

> In this first version, self-service rebooking is intended for **slots of the same price**. Upgrades and downgrades work as described above, but the feature was designed around like-for-like moves.

---

## Cancelling and refunds

Cancelling a slot booking follows the normal cancellation rules of the booking option and of `local_shopping_cart`: whether cancellation is allowed at all, until when, and which cancellation fee applies is configured as for any other paid option.

Additionally, slot bookings have their own **move/cancel deadline per slot** — see [Moving, cancelling and rebooking](move_cancel_rebook.md). A full cancellation is only offered while every booked slot is still within its deadline.

Releasing single slots from a booking refunds the difference as credit if the removed slots had a price.

---

## Setting up a paid slot option — checklist

1. Set up a **payment account** and the payment gateway in `local_shopping_cart` as usual.
2. Define the **price categories** of the site.
3. In the booking option, enter the **price per slot** in the Price section.
4. Optionally create **price rules** for individual times.
5. Check the result in the picker: the price dots and the running total should show what you expect before anybody books.
