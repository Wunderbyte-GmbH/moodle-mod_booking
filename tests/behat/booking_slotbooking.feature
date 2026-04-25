@mod @mod_booking @booking_slotbooking
Feature: Slot booking option renders fixed calendar slots in student timezone

  Background:
    Given the following config values are set as admin:
      | config        | value           |
      | timezone      | America/Chicago |
      | forcetimezone | 99              |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | timezone        |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       | America/Chicago |
      | student1 | Student   | 1        | student1@example.com | S1       | Europe/Vienna   |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name          | intro                    | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingSlots  | Booking slot description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking      | text                | course | description       | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingSlots | Slot booking option | C1     | Slot booking test | 2          | 1            | fixed     | calendar               | 20                    | 09:00             | 11:00             | 2409195600      | 2409627000       | 1          | 0          | 1          | 0          | 0          | 0          | 0          | 10                             | 2                       |
    # 20 years in future in America/Chicago:
    # valid range: 6 May 2046 (Sunday 00:00:00) - 10 May 2046 (Thursday EOD)
    # generated fixed slots in Europe/Kyiv should be 5:00 PM-6:00 PM and 6:00 PM-7:00 PM.
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Slotbookig: student sees predefined Monday and Wednesday slot dates in slotbooking modal calendar and book one slot
    Given I am on the "BookingSlots" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    ##Validate no illegal slots
    Then I click on "6" "button" in the ".booking-slot-calendar-grid" "css_element"
    And ".booking-slot-fixed-editor" "css_element" should not be visible
    And I click on "8" "button" in the ".booking-slot-calendar-grid" "css_element"
    And ".booking-slot-fixed-editor" "css_element" should not be visible
    And I click on "10" "button" in the ".booking-slot-calendar-grid" "css_element"
    And ".booking-slot-fixed-editor" "css_element" should not be visible
    ## Validate correct slots
    And I click on "7" "button" in the ".booking-slot-calendar-grid" "css_element"
    And I should see "16:00 " in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see " 16:20" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "16:00 - 16:20" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "16:20 - 16:40" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "17:40 - 18:00" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "17:20 - 17:40" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I click on "9" "button" in the ".booking-slot-calendar-grid" "css_element"
    And I should see "16:00 - 16:20" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "16:20 - 16:40" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "17:40 - 18:00" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    And I should see "17:20 - 17:40" in the "[data-name=\"slot_calendar_ui\"]" "css_element"
    ## Book slot
    And I click on "16:20 - 16:40" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Slot booking option" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
