@mod @mod_booking @booking_cancel_option
Feature: In a booking instance
  As a student
  I need to book option and then cancel it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |
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
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "mod_booking > semesters" exist:
      | identifier | name      | startdate                      | enddate                         |
      | nextmomth  | NextMonth | ## first day of next month ##  | ## last day of next month ##    |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | cancancelbook | Default view for booking options |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 1             | All bookings                     |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option cancellation: disable cancellation and booking of oprion as a student
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description  |
      | My booking | Test option 1 | C1     | Cancellation |
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Allow users to cancel their booking themselves" to ""
    And I press "Save and display"
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: book oprion as a student and self-cancell it
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description  |
      | My booking | Test option 1 | C1     | Cancellation |
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Undo my booking" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    Then I should see "Click again to confirm cancellation" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm cancellation" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: try self-cancell future option as a student with different disallow settings
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description  | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Test option 1 | C1     | Cancellation | 1           | 0              | 0              | ## tomorrow ##    | ## +3 days ##   |
    ## Important: ## tomorrow ## means 00:00 start time!
    ## With this setting, and option starts on the 5th at 00:00 o clock,
    ## users would expect it to be cancelable until 4th at 23:59:59.
    ## In this case, there would be now difference between "1 day before" and no setting at all.
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Define cancellation conditions" to "Set cancellation date relative to Start of the booking option (coursestarttime)"
    ## name for "Disallow users to cancel their booking n days before start..."
    And I set the field "allowupdatedays" to "2"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "allowupdatedays" to "0"
    And I press "Save and display"
    And I log out
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "allowupdatedays" to "1"
    And I press "Save and display"
    And I log out
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: try self-cancell ongoing option as a student with different disallow settings
    Given the following "mod_booking > options" exist:
      | booking    | text          | course | description  | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Test option 1 | C1     | Cancellation | 1           | 0              | 0              | ## -5 minutes ##  | ## +2 days ##   |
    ## Important: ## tomorrow ## means 00:00 start time!
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Define cancellation conditions" to "Set cancellation date relative to Start of the booking option (coursestarttime)"
    ## name for "Disallow users to cancel their booking n days before start..."
    And I set the field "Allow booking after course start" to "checked"
    And I set the field "allowupdatedays" to "0"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "allowupdatedays" to "-1"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: try self-cancell ongoing option as a student depending to semester dates
    ## Define semester start time as relative date to cancellation
    Given the following config values are set as admin:
      | config                 | value         | plugin  |
      | canceldependenton      | semesterstart | booking |
    And the following "mod_booking > options" exist:
      | booking    | text          | course | description  | semester  |
      | My booking | Test option 1 | C1     | Cancellation | nextmomth |
    And I am on the "My booking" Activity page logged in as admin
    ## allowupdatedays > max possible days before semester so cancellation impossible
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Allow booking after course start" to "checked"
    And I set the field "Define cancellation conditions" to "Set cancellation date relative to Semester start"
    And I set the field "allowupdatedays" to "32"
    And I press "Save and display"
    And I wait until the page is ready
    ## Create option dates by semester
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Dates"
    And I should see "NextMonth (nextmomth)" in the "//div[contains(@id, 'id_datesheader_') and contains(@class, 'fcontainer')]" "xpath_element"
    And I set the following fields to these values:
      | Select time period                               | NextMonth (nextmomth) |
      | Weekday, start and end time (Day, HH:MM - HH:MM) | Friday, 13:00-14:00   |
    And I press "Create date series"
    And I wait "1" seconds
    And I press "Save"
    And I log out
    ## Student - book and cancel
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    ## 0 has been used to pass test OK for any day until "next month" comes
    And I set the field "allowupdatedays" to "0"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: try self-cancell ongoing option as a student with bookingopeningtime and different disallow settings
    ## Define semester start time as relative date to cancellation
    Given the following config values are set as admin:
      | config                 | value              | plugin  |
      | canceldependenton      | bookingopeningtime | booking |
    And the following "mod_booking > options" exist:
      | booking    | text          | course | description  | availability | restrictanswerperiodopening | bookingopeningtime   | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Test option 1 | C1     | Cancellation | 1            | 1                           | ## yesterday noon ## | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And I am on the "My booking" Activity page logged in as admin
    ## allowupdatedays > max possible days before semester so cancellation impossible
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Define cancellation conditions" to "Set cancellation date relative to Booking registration start (bookingopeningtime)"
    ## name for "Disallow users to cancel their booking n days before start..."
    And I set the field "allowupdatedays" to "0"
    And I press "Save and display"
    And I log out
    ## Book option as student
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Verify - self-cancellation IS NOT possible
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    ## Update self-cancellation as a teacher
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "allowupdatedays" to "-2"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Verify - self-cancellation IS possible
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"

  @javascript
  Scenario: Booking option cancellation: try self-cancell ongoing option as a student with bookingclosingtime and different disallow settings
    ## Define semester start time as relative date to cancellation
    Given the following config values are set as admin:
      | config                 | value              | plugin  |
      | canceldependenton      | bookingclosingtime | booking |
    And the following "mod_booking > options" exist:
      | booking    | text          | course | description  | availability | restrictanswerperiodclosing | bookingclosingtime  | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | My booking | Test option 1 | C1     | Cancellation | 1            | 1                           | ## tomorrow noon ## | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And I am on the "My booking" Activity page logged in as admin
    ## allowupdatedays > max possible days before semester so cancellation impossible
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "Define cancellation conditions" to "Set cancellation date relative to Booking registration end (bookingclosingtime)"
    ## name for "Disallow users to cancel their booking n days before start..."
    And I set the field "allowupdatedays" to "2"
    And I press "Save and display"
    And I log out
    ## Book option as student
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Verify - self-cancellation IS NOT possible
    And I should not see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
    And I log out
    ## Update self-cancellation as a teacher
    And I am on the "My booking" Activity page logged in as teacher1
    And I follow "Settings"
    And I follow "Booking und Cancelling"
    And I set the field "allowupdatedays" to "0"
    And I press "Save and display"
    And I log out
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Verify - self-cancellation IS possible
    And I should see "Undo my booking" in the ".allbookingoptionstable_r1 .booknow" "css_element"
