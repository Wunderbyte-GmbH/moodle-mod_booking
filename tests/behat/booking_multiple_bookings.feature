@mod @mod_booking @booking_multiple_bookings
Feature: In a booking instance with multiple bookings enabled
  As a student
  I need to be able to book the same option multiple times
  And see the booking count in the button message
  And be able to cancel individual bookings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | cancancelbook | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1             | All bookings                     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Multiple bookings enabled: student can book the same option multiple times and see booking count
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                |
    And I am on the "My booking" Activity page logged in as student1
    # First booking
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    # Second booking
    When I click on "Book again (already booked 1 time)" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 2 times)" in the ".allbookingoptionstable_r1" "css_element"
    # Third booking
    When I click on "Book again (already booked 2 times)" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 3 times)" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Multiple bookings enabled: student can cancel one booking and still see correct count
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                |
    And I am on the "My booking" Activity page logged in as student1
    # Book twice
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book again (already booked 1 time)" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book again (already booked 2 times)" in the ".allbookingoptionstable_r1" "css_element"
    # Cancel one booking
    When I click on "Undo my booking" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm cancellation" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    # Cancel second booking
    When I click on "Undo my booking" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm cancellation" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should not see "Book again" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Multiple bookings enabled: cancel button appears after booking with multiple bookings allowed
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                |
    And I am on the "My booking" Activity page logged in as student1
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Multiple bookings enabled: wait time between bookings is respected
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings | allowtobookagainafter |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                | 2                     |
    And I am on the "My booking" Activity page logged in as student1
    # First booking
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    # Try to book immediately (should be blocked due to wait time)
    And I should not see "Book again" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I wait "3" seconds
    And I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Multiple bookings enabled: different students can each book multiple times
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                |
    # Student 1 books twice
    And I am on the "My booking" Activity page logged in as student1
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book again (already booked 1 time)" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book again (already booked 2 times)" in the ".allbookingoptionstable_r1" "css_element"
    # Student 2 books once
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    When I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    # Verify student 1 still shows correct count
    And I log out
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Book again (already booked 2 times)" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Multiple bookings enabled: cancel booking returns option to unbooked state
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description       | multiplebookings |
      | My booking | Test option 1 | C1     | Multiple bookings | 1                |
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Allow users to cancel their booking themselves" to "checked"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    # Book once
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book again (already booked 1 time)" in the ".allbookingoptionstable_r1" "css_element"
    # Cancel the booking
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Undo my booking" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm cancellation" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".allbookingoptionstable_r1" "css_element"
    # After canceling all bookings, should return to "Book now"
    Then I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should not see "Book again" in the ".allbookingoptionstable_r1" "css_element"
