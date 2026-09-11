[Back to parent section](../README.md)

# Campaigns — Developer API

This guide explains how to implement a custom campaign type for the mod_booking campaign system.

---

## Quick setup path

1. Open this page and start with the matching section for your use case.
2. Follow the linked detailed pages from the table of contents for configuration details.
3. Apply the configuration in Booking and save your changes.
4. Test with one realistic scenario before rollout.

---

## Table of Contents

1. [How campaigns are evaluated](#1-how-campaigns-are-evaluated)
2. [Implementing `booking_campaign`](#2-implementing-booking_campaign)
3. [Registering your campaign type](#3-registering-your-campaign-type)

---

## 1. How campaigns are evaluated

When a booking option's availability or price is checked:

1. `campaigns_info::get_all_campaigns()` loads all active campaigns.
2. Each campaign's `campaign_is_active()` method is checked against the current option.
3. For active campaigns, `apply_logic()` modifies the option settings (price factor, capacity factor, block label).
4. `is_blocking()` is called to determine if the campaign blocks the user from booking.
5. `get_campaign_price()` returns the modified price for a specific user.

---

## 2. Implementing `booking_campaign`

Create a class in `classes/booking_campaigns/campaigns/` implementing `mod_booking\booking_campaigns\booking_campaign`:

```php
namespace mod_booking\booking_campaigns\campaigns;

use mod_booking\booking_campaigns\booking_campaign;
use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

class campaign_mytype implements booking_campaign {

    public $id = 0;
    public $name = '';
    public $starttime = 0;
    public $endtime = 0;
    public $bookingcampaigntype = 'campaign_mytype';

    public function get_name_of_campaign_type(bool $localized = true): string {
        return $localized
            ? get_string('campaign_mytype', 'mod_booking')
            : 'campaign_mytype';
    }

    public function get_name_of_campaign(): string {
        return $this->name;
    }

    public function get_id_of_campaign(): int {
        return $this->id;
    }

    public function add_campaign_to_mform(MoodleQuickForm &$mform, array &$ajaxformdata) {
        // Add configuration fields for this campaign type
        $mform->addElement('text', 'mycampaign_param', get_string('mycampaign_param', 'mod_booking'));
    }

    public function save_campaign(stdClass &$data) {
        // Persist campaign data (typically to $data->json)
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        // Load saved values back into $data for editing
    }

    public function set_campaigndata(stdClass $record) {
        $this->id = $record->id ?? 0;
        $this->name = $record->name ?? '';
        $this->starttime = $record->starttime ?? 0;
        $this->endtime = $record->endtime ?? 0;
        // Load additional data from JSON
        $jsonobj = json_decode($record->json ?? '{}');
    }

    public function campaign_is_active(int $optionid, booking_option_settings $settings): bool {
        // Return true if this campaign applies to the given option
        $now = time();
        if ($now < $this->starttime || $now > $this->endtime) {
            return false;
        }
        // Add your option matching logic here
        return true;
    }

    public function apply_logic(booking_option_settings &$settings, stdClass &$dbrecord) {
        // Modify $settings (e.g., adjust maxanswers, price factor)
        // This is called when campaign_is_active() returns true
    }

    public function is_blocking(booking_option_settings $settings, int $userid): array {
        // Return ['blocked' => false] or ['blocked' => true, 'label' => 'Message']
        return ['blocked' => false];
    }

    public function get_campaign_price(float $price, int $userid = 0): float {
        // Return the modified price for this campaign
        return $price; // No change by default
    }

    public function user_specific_price(): bool {
        // Return true if the price is different per user (for caching purposes)
        return false;
    }
}
```

---

## 3. Registering your campaign type

Campaign types are auto-discovered by `campaigns_info`, which scans the `classes/booking_campaigns/campaigns/` directory. No explicit registration is needed.

---

## See also

- [Campaigns user documentation](../user/campaigns/README.md)
