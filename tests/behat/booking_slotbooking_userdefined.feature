@mod @mod_booking @booking_slotbooking
Feature: Slot booking option of type "userdefined" lets a student pick a free start/duration, enforces capacity and overlap, and supports paid checkout

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Userdefined slotbooking: a student freely picks a start time within opening hours and books it
    # slot_custom_min_duration == slot_custom_max_duration leaves exactly one duration option, so the
    # duration <select> never needs touching - only the free-form start time does. Values are in
    # seconds (mform "duration" element). Restricting valid_from/until to "tomorrow" only means the
    # custom-day calendar always auto-opens directly on that day's editor, no day click needed.
    Given the following "activities" exist:
      | activity | course | name                | intro                   | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingUserdefBasic | Userdefined basic test  | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking             | text                    | course | description | optiontype | slot_enabled | slot_type   | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_custom_min_duration | slot_custom_max_duration | slot_custom_start_interval_minutes | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingUserdefBasic | Userdefined basic option | C1    | Basic test  | 2          | 1            | userdefined | 09:00              | 17:00              | ## tomorrow ##   | ## tomorrow ##    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2700                     | 2700                      | 15                                  | 5                               | 1                        |
    Given I am on the "BookingUserdefBasic" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "10:30"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"

  @javascript
  Scenario: Userdefined slotbooking: a start time overlapping the student's own booking is rejected, and the view stays in place
    Given the following "activities" exist:
      | activity | course | name                  | intro                     | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingUserdefOverlap | Userdefined overlap test  | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking               | text                      | course | description   | optiontype | slot_enabled | slot_type   | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_custom_min_duration | slot_custom_max_duration | slot_custom_start_interval_minutes | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingUserdefOverlap | Userdefined overlap option | C1    | Overlap test  | 2          | 1            | userdefined | 09:00              | 17:00              | ## tomorrow ##   | ## tomorrow ##    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2700                     | 2700                      | 15                                  | 5                               | 2                        |
    Given I am on the "BookingUserdefOverlap" Activity page logged in as student1
    ## First booking: 09:00 - 09:45.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "09:00"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    ## Second attempt: 09:15 - 10:00 overlaps the 09:00 - 09:45 booking just made - must be rejected,
    ## and (regression guard) the calendar must stay on this same day/option instead of resetting.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "09:15"
    And I follow "Continue"
    Then I should see "The selected slot is no longer available. Please choose another one."
    And the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" matches value "09:15"

  @javascript
  Scenario: Userdefined slotbooking: max_slots_per_user rejects a second, different slot with a clear message
    Given the following "activities" exist:
      | activity | course | name              | intro                | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingUserdefMax | Userdefined max test  | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking           | text                  | course | description | optiontype | slot_enabled | slot_type   | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_custom_min_duration | slot_custom_max_duration | slot_custom_start_interval_minutes | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingUserdefMax | Userdefined max option | C1     | Max test    | 2          | 1            | userdefined | 09:00              | 17:00              | ## tomorrow ##   | ## tomorrow ##    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2700                     | 2700                      | 15                                  | 5                               | 1                        |
    Given I am on the "BookingUserdefMax" Activity page logged in as student1
    ## First (and, per max_slots_per_user=1, only allowed) booking: 09:00 - 09:45.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "09:00"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    ## A second, entirely different (non-overlapping) slot must still be rejected - not because it
    ## overlaps anything, but purely because capacity (1) is already used up.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "11:00"
    And I follow "Continue"
    Then I should see "You have already reached the maximum number of slots you can book for this option."

  @javascript
  Scenario: Userdefined slotbooking: a paid option leads to the shopping cart instead of an immediate confirmation
    Given the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 70           | 0        | 1                  |
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
      | activity | course | name                | intro                  | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingUserdefPrice | Userdefined price test | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking             | text                     | course | description | optiontype | useprice | slot_enabled | slot_type   | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_custom_min_duration | slot_custom_max_duration | slot_custom_start_interval_minutes | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingUserdefPrice | Userdefined priced option | C1    | Price test  | 2          | 1        | 1            | userdefined | 09:00              | 17:00              | ## tomorrow ##   | ## tomorrow ##    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2700                     | 2700                      | 15                                  | 5                               | 1                        |
    Given I am on the "BookingUserdefPrice" Activity page logged in as student1
    When I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I set the field with xpath "//*[@data-region='slot-custom-editor']//input[@type='time']" to "10:00"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully put Userdefined priced option into the shopping cart." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    When I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    Then I should see "Userdefined priced option" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "70.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
