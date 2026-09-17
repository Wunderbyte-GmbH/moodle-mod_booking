@mod @mod_booking @booking_slotbooking
Feature: Slot booking option of type "fixed" covers per-slot capacity, cancellation and paid checkout

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
      | student3 | Student   | 3        | student3@example.com | S3       |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And I clean booking cache
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Fixed slotbooking: slot capacity is shared across students, not per-student
    # slot_valid_from/until use relative dates and every weekday is open, so the calendar always
    # auto-opens on the very first bookable day (tomorrow) without needing to click a day button.
    Given the following "activities" exist:
      | activity | course | name              | intro                | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingFixedCap   | Fixed capacity test  | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking         | text                  | course | description   | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingFixedCap | Fixed capacity option | C1     | Capacity test | 2          | 1            | fixed     | calendar               | 30                     | 09:00              | 11:00              | ## tomorrow ##   | ## +7 days ##     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2                               | 1                        |
    Given I am on the "BookingFixedCap" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I log out
    ## Second student: the slot still has one free place (capacity 2), so it must still be offered.
    Given I am on the "BookingFixedCap" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    Then I should see "09:00 - 09:30" in the ".booking-slot-fixed-editor" "css_element"
    When I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I log out
    ## Third student: capacity (2) is now exhausted by two DIFFERENT students - the slot must no
    ## longer be offered at all (a full slot the user never booked is not "Booked", it is dropped).
    Given I am on the "BookingFixedCap" Activity page logged in as student3
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    Then I should not see "09:00 - 09:30" in the ".booking-slot-fixed-editor" "css_element"
    And I should see "09:30 - 10:00" in the ".booking-slot-fixed-editor" "css_element"

  @javascript
  Scenario: Fixed slotbooking: cancelling a booked slot frees it up for booking again
    Given the following "activities" exist:
      | activity | course | name              | intro               | bookingmanager | eventtype | Default view for booking options | cancancelbook |
      | booking  | C1     | BookingFixedCncl  | Fixed cancel test   | teacher1       | Webinar   | All bookings                     | 1              |
    And the following "mod_booking > options" exist:
      | booking          | text               | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingFixedCncl | Fixed cancel option | C1     | Cancel test | 2          | 1            | fixed     | calendar               | 30                     | 09:00              | 11:00              | ## tomorrow ##   | ## +7 days ##     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 5                               | 1                        |
    Given I am on the "BookingFixedCncl" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Undo my booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Click again to confirm cancellation" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Click again to confirm cancellation" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    ## The cancelled slot must be bookable again, by the same student.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I should see "09:00 - 09:30" in the ".booking-slot-fixed-editor" "css_element"
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"

  @javascript
  Scenario: Fixed slotbooking: a paid option leads to the shopping cart instead of an immediate confirmation
    Given the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 50           | 0        | 1                  |
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
      | activity | course | name              | intro              | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingFixedPrice | Fixed price test   | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking           | text                 | course | description | optiontype | useprice | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingFixedPrice | Fixed priced option  | C1     | Price test  | 2          | 1        | 1            | fixed     | calendar               | 30                     | 09:00              | 11:00              | ## tomorrow ##   | ## +7 days ##     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 5                               | 1                        |
    Given I am on the "BookingFixedPrice" Activity page logged in as student1
    When I click on "Add to cart" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully put Fixed priced option into the shopping cart." in the ".modal-dialog.modal-xl .modalMainContent" "css_element"
    When I click on "Proceed to checkout" "text" in the ".modal-dialog.modal-xl .modalFooter" "css_element"
    And I wait to be redirected
    Then I should see "Fixed priced option" in the ".shopping-cart-checkout-items-container" "css_element"
    And I should see "50.00 EUR" in the ".shopping-cart-checkout-items-container" "css_element"
