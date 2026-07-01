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
      | BookingSlots | Slot booking option | C1     | Slot booking test | 2          | 1            | fixed     | calendar               | 20                    | 09:00             | 11:00             | 2409195600      | 2409627000       | 1          | 0          | 1          | 0          | 0          | 0          | 0          | 2                              | 2                       |
    # 20 years in future in America/Chicago:
    # valid range: 6 May 2046 (Sunday 00:00:00) - 10 May 2046 (Thursday EOD)
    # generated fixed slots in Europe/Kyiv should be 5:00 PM-6:00 PM and 6:00 PM-7:00 PM.
    And I change viewport size to "1366x6000"

  @javascript
  Scenario: Slotbookig: student sees predefined Monday and Wednesday slot dates in slotbooking modal calendar and book one slot
    Given I am on the "BookingSlots" Activity page logged in as student1
    And I should see "12" in the ".allbookingoptionstable_r1 .bookings " "css_element"
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
    And I should see "11" in the ".allbookingoptionstable_r1 .bookings " "css_element"
    And I should see "Booked slots" in the ".allbookingoptionstable_r1 " "css_element"
    And I should see "9 May 2046, 4:20 PM - 4:40 PM" in the ".allbookingoptionstable_r1 " "css_element"

  @javascript
  Scenario: Slotbookig: teacher update slot settings to roling and list view and student book one slot
    Given I am on the "BookingSlots" Activity page logged in as teacher1
    And I should see "12" in the ".allbookingoptionstable_r1 .bookings " "css_element"
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Slot Booking Settings"
    And I set the field "Slot type" to "Rolling"
    ## Changing "Slot type" triggers a no-submit AJAX form reload (btn_slot_type) that
    ## replaces the form markup. Wait for it to finish, then make sure the section is
    ## expanded (idempotent - never collapses) so the conditionally shown
    ## "Slot booking interface" field is interactable.
    And I wait until the page is ready
    And I expand all fieldsets
    And I set the field "Slot booking interface" to "List view"
    And I set the field "Slot duration (minutes)" to "40"
    And I set the field "Slot interval (minutes)" to "20"
    And I press "Save"
    And I log out
    And I am on the "BookingSlots" Activity page logged in as student1
    And I should see "10" in the ".allbookingoptionstable_r1 .bookings " "css_element"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    ## Validate correct slots
    And I should see "10" in the ".allbookingoptionstable_r1 .bookings " "css_element"
    And I should see "Monday, 7 May 2046 - 4:00 PM - 4:40 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Monday, 7 May 2046 - 4:20 PM - 5:00 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Monday, 7 May 2046 - 4:40 PM - 5:20 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Monday, 7 May 2046 - 5:00 PM - 5:40 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Monday, 7 May 2046 - 5:20 PM - 6:00 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Wednesday, 9 May 2046 - 4:00 PM - 4:40 PM" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Wednesday, 9 May 2046 - 5:20 PM - 6:00 PM" in the ".booking-slotbooking-prepage" "css_element"
    ## Book slot
    And I click on "Monday, 7 May 2046 - 5:20 PM - 6:00 PM" "text" in the ".booking-slotbooking-prepage" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I should see "Slot booking option" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "9" in the ".allbookingoptionstable_r1 .bookings " "css_element"
    And I should see "Booked slots" in the ".allbookingoptionstable_r1 " "css_element"
    And I should see "7 May 2046, 5:20 PM - 6:00 PM" in the ".allbookingoptionstable_r1 " "css_element"

  @javascript
  Scenario: Slotbooking: teacher enables self-service rebooking and the opt-in persists
    Given I am on the "BookingSlots" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Slot Booking Settings"
    And I set the field "Allow rebooking" to "1"
    And I press "Save"
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Slot Booking Settings"
    Then the field "Allow rebooking" matches value "1"

  @javascript
  Scenario: Slotbooking: enabling self-service rebooking must not break the normal first booking
    # EXPECTATION (regression guard): an option with self-service rebooking enabled must still let a
    # not-yet-booked student complete a normal slot booking and see the success confirmation.
    # Guards the confirmation fix: slotmove (id 155) is now recognised as a booked state
    # (MOD_BOOKING_BO_COND_BOOKED_STATES), so a freshly booked rebookable user sees success, not an error.
    Given the following "activities" exist:
      | activity | course | name              | intro        | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRebookOnly | Rebook intro | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking           | text          | course | description | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user | slot_allow_self_rebooking |
      | BookingRebookOnly | Rebook option | C1     | Rebook test | 2          | 1            | fixed     | calendar               | 20                    | 09:00             | 11:00             | 2409195600      | 2409627000       | 1          | 0          | 1          | 0          | 0          | 0          | 0          | 2                              | 2                       | 1                         |
    And I am on the "BookingRebookOnly" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I click on "7" "button" in the ".booking-slot-calendar-grid" "css_element"
    And I click on "16:20 - 16:40" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"

  @javascript
  Scenario: Slotbooking Fall 2: a booked student with book-again sees a Book and a Move tab in one prepage
    # EXPECTATION (Fall 2 feature): a booked user on a multiplebookings option is offered the
    # book-again slot picker AND a move tab in one prepage; the move tab shows the move calendar.
    # Book-again (multiplebookings) is switched on by the teacher after the first booking.
    Given the following "activities" exist:
      | activity | course | name          | intro        | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingRebook | Rebook intro | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking       | text             | course | description    | optiontype | slot_enabled | slot_type | slot_booking_view_mode | slot_duration_minutes | slot_opening_time | slot_closing_time | slot_valid_from | slot_valid_until | slot_day_1 | slot_day_2 | slot_day_3 | slot_day_4 | slot_day_5 | slot_day_6 | slot_day_7 | slot_max_participants_per_slot | slot_max_slots_per_user | slot_allow_self_rebooking |
      | BookingRebook | Rebooking option | C1     | Rebooking test | 2          | 1            | fixed     | calendar               | 20                    | 09:00             | 11:00             | 2409195600      | 2409627000       | 1          | 0          | 1          | 0          | 0          | 0          | 0          | 2                              | 2                       | 1                         |
    And I am on the "BookingRebook" Activity page logged in as student1
    # Book a first slot through the UI so a proper slot answer exists.
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait "1" seconds
    And I click on "7" "button" in the ".booking-slot-calendar-grid" "css_element"
    And I click on "16:20 - 16:40" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    And I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I log out
    # Teacher switches on book-again (multiplebookings).
    And I am on the "BookingRebook" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Allow to book again" to "1"
    And I press "Save"
    And I log out
    # Book again: the same prepage must now offer a Book and a Move tab.
    And I am on the "BookingRebook" Activity page logged in as student1
    And I click on "Book again (already booked 1 time)" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    Then I should see "Book another slot" in the ".booking-slotbooking-prepage" "css_element"
    And I should see "Move/Cancel your slot(s)" in the ".booking-slotbooking-prepage" "css_element"
    # Switching to the move tab reveals the "Update booking" editor (slotupdate_form DynamicForm).
    And I click on "Move/Cancel your slot(s)" "text" in the ".booking-slotbooking-prepage" "css_element"
    And I wait "2" seconds
    And ".booking-slotupdate-prepage .booking-slot-calendar-ui" "css_element" should be visible
