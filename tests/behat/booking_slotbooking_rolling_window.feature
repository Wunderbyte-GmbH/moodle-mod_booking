@mod @mod_booking @booking_slotbooking
Feature: Slot booking availability period as rolling duration instead of a fixed end date

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
  Scenario: Rolling window: only the days inside the window are offered and counted
    # slot_valid_from is tomorrow and the window is counted from the start of today, so a 3 day
    # window always covers exactly tomorrow and the day after (today's partial day is never in it).
    # Every weekday is open with two 60 minute slots, hence 2 days x 2 slots = 4 free slots,
    # independent of the time of day and the day of the week the test runs.
    Given the following "activities" exist:
      | activity | course | name           | intro            | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRolling | Rolling window   | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking        | text                   | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_interval_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_mode | slot_valid_duration_value | slot_valid_duration_unit | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingRolling | Rolling window option  | C1     | Rolling     | 2          | 1            | fixed     | list                   | 60                    | 60                    | 09:00             | 11:00             | ## tomorrow ##  | rolling         | 3                         | days                     | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 1                              | 1                       |
    When I am on the "BookingRolling" Activity page logged in as student1
    Then I should see "4" in the ".allbookingoptionstable_r1 .bookings " "css_element"

  @javascript
  Scenario: Rolling window: a longer duration offers more days (the window follows the setting)
    Given the following "activities" exist:
      | activity | course | name              | intro            | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRollingLong | Rolling window   | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking            | text                       | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_interval_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_mode | slot_valid_duration_value | slot_valid_duration_unit | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingRollingLong | Rolling long window option | C1     | Rolling     | 2          | 1            | fixed     | list                   | 60                    | 60                    | 09:00             | 11:00             | ## tomorrow ##  | rolling         | 1                         | weeks                    | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 1                              | 1                       |
    When I am on the "BookingRollingLong" Activity page logged in as student1
    # 1 week from the start of today = tomorrow .. today + 6 days = 6 days x 2 slots.
    Then I should see "12" in the ".allbookingoptionstable_r1 .bookings " "css_element"

  @javascript
  Scenario: Rolling window: the option form switches between fixed end date and rolling duration and keeps the choice
    Given the following "activities" exist:
      | activity | course | name            | intro          | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRollForm | Rolling window | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking         | text               | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_interval_minutes | slot_opening_time | slot_closing_time | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingRollForm | Rolling form option | C1     | Rolling     | 2          | 1            | fixed     | list                   | 60                    | 60                    | 09:00             | 11:00             | 1          | 1          | 1          | 1          | 1          | 1          | 1          | 1                              | 1                       |
    When I am on the "BookingRollForm" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Slot Booking Settings"
    And I wait until the page is ready
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    # Default: fixed end date.
    Then "Valid until" "field" should be visible
    And "Duration" "field" should not be visible
    When I set the field "Rolling duration (counted from today)" to "1"
    Then "Duration" "field" should be visible
    And "Unit" "field" should be visible
    And "Valid until" "field" should not be visible
    When I set the field "Duration" to "2"
    And I set the field "Unit" to "Weeks"
    And I click on "Save" "button" skipping visibility check
    And I am on the "BookingRollForm" Activity page
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Slot Booking Settings"
    And I wait until the page is ready
    And I expand all fieldsets
    And I wait until ".collapsing" "css_element" does not exist
    Then the field "Rolling duration (counted from today)" matches value "1"
    And the field "Duration" matches value "2"
    And the field "Unit" matches value "Weeks"
