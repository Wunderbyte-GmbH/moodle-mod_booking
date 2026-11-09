@mod @mod_booking @booking_slotbooking
Feature: The bookingoptionview shortcode with calendar="compact" shows the slots in the calendar of local_entities

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
    And the following "activities" exist:
      | activity | course | name           | intro             | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingCompact | Compact calendar  | teacher1       | Webinar   | All bookings                     |
    ## Only tomorrow is bookable, so the calendar opens on the week holding the slots.
    And the following "mod_booking > options" exist:
      | booking        | text          | course | description          |
      | BookingCompact | Calendar page | C1     | replaced by shortcode |
    And the following "mod_booking > options" exist:
      | booking        | text           | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingCompact | Compact option | C1     | Slots       | 2          | 1            | fixed     | calendar               | 30                    | 09:00             | 11:00             | ## tomorrow ##  | ## tomorrow ##   | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 2                              | 3                       |
    And I clean booking cache
    And I change viewport size to "1366x3000"

  @javascript
  Scenario: Compact calendar: a slot is picked by clicking its entry and removed again from the summary
    Given I make option "Calendar page" show option "Compact option" via shortcode with "inlinestartpage=slotbooking calendar=compact", both in booking "BookingCompact"
    And I log in as "student1"
    And I am on the option detail page for option "Calendar page" in booking "BookingCompact"
    And I wait until the page is ready
    ## The calendar of local_entities replaces the month grid and the day timeline.
    Then ".booking-slotbooking-prepage[data-calendar='compact'] .fc" "css_element" should exist
    And ".booking-slot-fixed-editor .booking-slot" "css_element" should not exist
    And ".booking-slotbooking-prepage .fc-event.booking-slot-compact" "css_element" should exist
    When I click on ".booking-slotbooking-prepage .fc-event.booking-slot-compact" "css_element"
    Then I should see "1 of 3 selected" in the ".booking-slot-selection-summary" "css_element"
    And ".booking-slotbooking-prepage .fc-event.booking-slot--selected" "css_element" should exist
    ## Removing it in the summary also clears the highlight in the calendar.
    When I click on ".booking-slot-selection-summary-row:last-child .booking-slot-selection-summary-remove" "css_element"
    And I wait until the page is ready
    Then ".booking-slot-selection-summary-row" "css_element" should not exist
    And ".booking-slotbooking-prepage .fc-event.booking-slot--selected" "css_element" should not exist

  @javascript
  Scenario: Without calendar="compact" the standard slot calendar stays
    Given I make option "Calendar page" show option "Compact option" via shortcode with "inlinestartpage=slotbooking", both in booking "BookingCompact"
    And I log in as "student1"
    And I am on the option detail page for option "Calendar page" in booking "BookingCompact"
    And I wait until the page is ready
    Then ".booking-slot-fixed-editor .booking-slot" "css_element" should exist
    And ".booking-slotbooking-prepage .fc" "css_element" should not exist
