@mod @mod_booking @booking_slotbooking
Feature: Slot booking option of type "session" offers the option's own sessions as slots

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
  Scenario: Session slotbooking: slots are the option's own sessions, and capacity is per session
    # slot_type=session ignores opening/closing time, valid_from/until and days_of_week entirely -
    # the bookable slots are exactly the option's own optiondates (coursestarttime_N/courseendtime_N),
    # not a generated grid. Both sessions sit on the same day, so the fixed-editor day view (which
    # always auto-opens on the first bookable day) shows both without any day navigation.
    Given the following "activities" exist:
      | activity | course | name               | intro                 | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingSessionCap  | Session capacity test | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking            | text                   | course | description   | optiontype | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0     | courseendtime_0       | optiondateid_1 | daystonotify_1 | coursestarttime_1     | courseendtime_1       | slot_enabled | slot_type | slot_booking_view_mode | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingSessionCap  | Session capacity option | C1    | Capacity test | 2          | 1           | 0              | 0               | ## tomorrow 09:00 ##  | ## tomorrow 09:45 ##  | 0              | 0               | ## tomorrow 14:00 ##  | ## tomorrow 14:45 ##  | 1            | session   | calendar               | 1                               | 5                        |
    Given I am on the "BookingSessionCap" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    ## Both sessions must be offered as slots - proof the picker sources them from the option's own
    ## dates rather than generating a grid from opening/closing time (which is not even set here).
    Then I should see "09:00 - 09:45" in the ".booking-slot-fixed-editor" "css_element"
    And I should see "14:00 - 14:45" in the ".booking-slot-fixed-editor" "css_element"
    When I click on "09:00 - 09:45" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    And I log out
    ## The 09:00 session's single place is now taken - it must no longer be offered - but the 14:00
    ## session is a SEPARATE session with its own, still-untouched capacity.
    Given I am on the "BookingSessionCap" Activity page logged in as student2
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    Then I should not see "09:00 - 09:45" in the ".booking-slot-fixed-editor" "css_element"
    And I should see "14:00 - 14:45" in the ".booking-slot-fixed-editor" "css_element"

  @javascript
  Scenario: Session slotbooking: max_slots_per_user caps how many of the option's own sessions a student can hold
    Given the following "activities" exist:
      | activity | course | name              | intro                | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingSessionMax | Session max slots test | teacher1     | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking           | text                | course | description | optiontype | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0    | courseendtime_0      | optiondateid_1 | daystonotify_1 | coursestarttime_1    | courseendtime_1      | optiondateid_2 | daystonotify_2 | coursestarttime_2    | courseendtime_2      | slot_enabled | slot_type | slot_booking_view_mode | slot_max_participants_per_slot | slot_max_slots_per_user |
      | BookingSessionMax | Session max option  | C1     | Max test    | 2          | 1           | 0              | 0               | ## tomorrow 09:00 ## | ## tomorrow 09:45 ## | 0              | 0               | ## tomorrow 11:00 ## | ## tomorrow 11:45 ## | 0              | 0               | ## tomorrow 14:00 ## | ## tomorrow 14:45 ## | 1            | session   | calendar               | 5                               | 2                        |
    Given I am on the "BookingSessionMax" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "09:00 - 09:45" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    ## With 1 of max 2 sessions booked, the row must offer booking again instead of locking to the
    ## booked state (slot capacity purchases are independent of the "book again" setting).
    And I should not see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I click on "11:00 - 11:45" "text" in the ".booking-slot-fixed-editor" "css_element"
    And I follow "Continue"
    Then I should see "Thank you! You have successfully booked" in the ".modal-dialog.modal-xl .condition-confirmation" "css_element"
    And I follow "Close"
    ## Capacity exhausted (2 of 2 sessions): the row locks to the booked state, even though the
    ## third session (14:00-14:45) still has plenty of open capacity for other students.
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
