@mod @mod_booking @booking_history @mod_booking_history
Feature: In a booking instance create booking options anf view history
  As an admin
  I need to add booking options and events to a booking instance and view history

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
      | teacher2 | Teacher   | 2        | teacher2@example.com | T2       |
      | admin1   | Admin     | 1        | admin1@example.com   | A1       |
      | student1 | Student   | 1        | student1@example.com | S1       |
      | student2 | Student   | 2        | student2@example.com | S2       |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name      | intro            | bookingmanager | eventtype |
      | booking  | C1     | MyBooking | My booking descr | teacher1       | Webinar   |
    And the following config values are set as admin:
    ## Set testing objective settings
      | bookingstracker | 1             | booking |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Bookinh history: create basic option than edit it and view history
    Given the following "mod_booking > options" exist:
      | booking   | text           | course | description   | limitanswers | maxanswers | teachersforoption | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | Option-created | C1     | Deskr-created | 0            | 0          | teacher1          | 0              | 0              | 2346937200        | 2347110000      |
      ## 2044/05/15 - 2044/05/17
    And the following "mod_booking > answers" exist:
      | booking    | option        | user     |
      | MyBooking | Option-created | student1 |
      | MyBooking | Option-created | student2 |
    And I am on the "MyBooking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Booking option name" to "Option-hist_updated"
    And I set the field "Description" to "Deskr-hist_updated"
    And I set the field "Max. number of participants" to "6"
    And I press "Save"
    ## Validate booking history for selected option
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Bookings tracker" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Manage bookings for Booking option: \"Option-hist_updated\""
    And I should see "2 of 2 records found" in the "#accordion-item-bookedusers" "css_element"
    And I click on "Booking history" "text" in the "#accordion-heading-bookinghistory" "css_element"
    ## TODO: different default order of records in mysql vs pgsql
    And I should see "student2@example.com" in the "//table[contains(@id, 'bookinghistorytable_option_')]" "xpath_element"
    And I should see "student1@example.com" in the "//table[contains(@id, 'bookinghistorytable_option_')]" "xpath_element"
    ## Validate general access to the booking history
    And I click on "Acceptance test site" "text" in the ".report2-nav" "css_element"
    And I should see "Manage bookings for Site: \"Acceptance test site\""
    And I should see "Option-hist_updated" in the "#booked_system_0_r1" "css_element"
    And I should see "2/6" in the "#booked_system_0_r1" "css_element"
    And I click on "Booking history" "text" in the "#accordion-heading-bookinghistory" "css_element"
    ## TODO: different default order of records in mysql vs pgsql
    And I should see "student2@example.com" in the "#bookinghistorytable_system_0" "css_element"
    And I should see "student1@example.com" in the "#bookinghistorytable_system_0" "css_element"
    And I click on "Report for Option-hist_updated" "link" in the "#bookinghistorytable_system_0_r1" "css_element"
    ## Just in case
    And I switch to a second window
    And I should see "Manage bookings for Booking option: \"Option-hist_updated\""
    And I log out
