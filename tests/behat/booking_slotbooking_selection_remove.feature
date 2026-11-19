@mod @mod_booking @booking_slotbooking
Feature: Removing a slot from the "Selected slots" summary drops it from the selection

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
      | student1 | C1     | student        |
    And I clean booking cache
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Fixed slotbooking: the trash button on the last selected slot removes it instead of adding a copy
    # Only tomorrow is bookable, so the calendar auto-opens on that day without a day click.
    Given the following "activities" exist:
      | activity | course | name             | intro            | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRemoveSel | Remove selection | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking          | text                 | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingRemoveSel | Remove select option | C1     | Remove test | 2          | 1            | fixed     | calendar               | 30                     | 09:00              | 11:00              | ## tomorrow ##   | ## tomorrow ##    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2                               | 3                        |
    Given I am on the "BookingRemoveSel" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "09:00 - 09:30" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I click on "09:30 - 10:00" "text" in the ".booking-slot-fixed-editor" "css_element"
    Then I should see "2 of 3 selected" in the ".booking-slot-selection-summary" "css_element"
    ## Remove the LAST row of the summary - this is the row that used to come back twice.
    When I click on ".booking-slot-selection-summary-row:last-child .booking-slot-selection-summary-remove" "css_element"
    And I wait until the page is ready
    Then I should see "1 of 3 selected" in the ".booking-slot-selection-summary" "css_element"
    And ".booking-slot-selection-summary-row:nth-child(2)" "css_element" should not exist
    ## Removing the remaining (now last) row empties the selection entirely.
    When I click on ".booking-slot-selection-summary-row:last-child .booking-slot-selection-summary-remove" "css_element"
    And I wait until the page is ready
    Then ".booking-slot-selection-summary-row" "css_element" should not exist
