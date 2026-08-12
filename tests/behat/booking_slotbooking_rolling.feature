@mod @mod_booking @booking_slotbooking
Feature: Slot booking option of type "rolling" covers buffer conflicts, calendar view and paid checkout

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Rolling slotbooking (calendar view): warmup/cooldown buffer blocks a slot too close to an existing booking
    # duration 30min / interval 15min makes candidate start times overlap (rolling), unlike fixed's
    # duration == interval grid. warmup=cooldown=60 min is wide enough that the very next candidate
    # (15 min after the booked slot's start) always collides, while a slot 3 hours later never does,
    # regardless of the exact buffer combination mode.
    Given the following "activities" exist:
      | activity | course | name              | intro                | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRollBuffer | Rolling buffer test  | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking           | text                  | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_interval_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user | slot_buffer_warmup_minutes | slot_buffer_cooldown_minutes |
      | BookingRollBuffer | Rolling buffer option | C1     | Buffer test | 2          | 1            | rolling   | calendar               | 30                     | 15                     | 09:00              | 13:00              | ## tomorrow ##   | ## +7 days ##     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 5                               | 1                        | 60                          | 60                            |
    Given I am on the "BookingRollBuffer" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I log out
    ## A different student, so this genuinely exercises the option-wide buffer check, not the
    ## unrelated per-user max_slots_per_user gate.
    Given I am on the "BookingRollBuffer" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    Then I should not see "09:15 - 09:45" in the ".booking-slot-fixed-editor" "css_element"
    And I should see "12:30 - 13:00" in the ".booking-slot-fixed-editor" "css_element"

  @javascript
  Scenario: Rolling slotbooking (calendar view): a paid option leads to the shopping cart instead of an immediate confirmation
    Given the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 60           | 0        | 1                  |
    And the following "core_payment > payment accounts" exist:
      | name     |
      | Account1 |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > plugin setup" exist:
      | account  | cancelationfee |
      | Account1 | 0              |
    And the following "activities" exist:
      | activity | course | name             | intro              | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRollPrice | Rolling price test | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking          | text                  | course | description | optiontype | useprice | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_interval_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingRollPrice | Rolling priced option | C1     | Price test  | 2          | 1        | 1            | rolling   | calendar               | 30                     | 15                     | 09:00              | 11:00              | ## tomorrow ##   | ## +7 days ##     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 5                               | 1                        |
    Given I am on the "BookingRollPrice" Activity page logged in as student1
    When I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully put Rolling priced option into the shopping cart." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    When I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    Then I should see "Rolling priced option" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "60.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
