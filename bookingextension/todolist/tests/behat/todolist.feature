@mod @mod_booking @bookingextension_todolist
Feature: Todo list booking extension
  In order to track option preparation tasks
  As a booking manager and teacher
  I need to configure and use the todo list extension end to end

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | manager1 | Manager   | One      | manager1@example.com |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | manager1 | C1     | editingteacher |
      | manager1 | C1     | manager        |
      | teacher1 | C1     | teacher        |
      | student1 | C1     | student        |
    And I clean booking cache
    And the following config values are set as admin:
      | config          | value | plugin                    |
      | enableglobally  | 1   | bookingextension_todolist |
      | bookingstracker | 1   | booking                   |
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | bookingextension/todolist:viewtodolist  | Allow      | teacher        | Course       | C1        |
      | bookingextension/todolist:checktodolist | Allow      | teacher        | Course       | C1        |
      | bookingextension/todolist:viewtodolist  | Allow      | student        | Course       | C1        |
    And the following "activities" exist:
      | activity | course | name      | intro        | bookingmanager |
      | booking  | C1     | MyBooking | Booking intro | manager1      |

  Scenario: Manager sees todo list fields in option edit form
    And I am on the "MyBooking" Activity page logged in as manager1
    When I follow "New booking option"
    Then I should see "Enable todo list"
    And I should see "Todo list"

  Scenario: Teacher sees configured todo items in option view
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "Prepare room\nBring materials" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    Then I should see "Prepare room"
    And I should see "Bring materials"

  Scenario: Teacher sees empty placeholder for enabled todo list
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    Then I should see "No todo items configured."

  @javascript
  Scenario: Teacher can check an item
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "Prepare room\nBring materials" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    When I click on ".bookingextension-todolist [data-action='toggle-todolist-item']" "css_element"
    Then I should see "Todo item marked as done."

  @javascript
  Scenario: Teacher can uncheck an item
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "One task" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    And I click on ".bookingextension-todolist [data-action='toggle-todolist-item']" "css_element"
    When I click on ".bookingextension-todolist [data-action='toggle-todolist-item']" "css_element"
    Then I should see "Todo item marked as not done."

  @javascript
  Scenario: Completing last item shows completion notification
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "Task one\nTask two" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    And I click on ".bookingextension-todolist li:nth-of-type(1) [data-action='toggle-todolist-item']" "css_element"
    When I click on ".bookingextension-todolist li:nth-of-type(2) [data-action='toggle-todolist-item']" "css_element"
    Then I should see "Congratulations! You have completed all todo items."

  Scenario: Student sees todo list items
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "One task" for option "OptionA" in booking "MyBooking"
    And I log in as "student1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    Then I should see "One task"

  @javascript
  Scenario: Booking history includes toggle entry
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "One task" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    When I click on ".bookingextension-todolist [data-action='toggle-todolist-item']" "css_element"
    And I log out
    And I log in as "manager1"
    And I am on the "MyBooking" Activity page
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Bookings tracker" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Booking history" "text" in the "#accordion-heading-bookinghistory" "css_element"
    Then I should see "Todolist item"

  @javascript
  Scenario: Booking history includes completed entry
    Given the following "mod_booking > options" exist:
      | booking   | text    | course | description | optiondateid_0 | coursestarttime_0 | courseendtime_0 |
      | MyBooking | OptionA | C1     | Desc A      | 0              | 2346937200        | 2347110000      |
    And I configure todo list "Task one\nTask two" for option "OptionA" in booking "MyBooking"
    And I log in as "teacher1"
    And I am on the option view page for option "OptionA" in booking "MyBooking"
    And I click on ".bookingextension-todolist li:nth-of-type(1) [data-action='toggle-todolist-item']" "css_element"
    And I click on ".bookingextension-todolist li:nth-of-type(2) [data-action='toggle-todolist-item']" "css_element"
    And I log out
    And I log in as "manager1"
    And I am on the "MyBooking" Activity page
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Bookings tracker" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Booking history" "text" in the "#accordion-heading-bookinghistory" "css_element"
    Then I should see "Todo list for option"

  @javascript
  Scenario: Rule type for not completed todo list is available
    Given I log in as "admin"
    When I visit "/mod/booking/edit_rules.php"
    And I click on "Add rule" "text"
    Then I should see "Before a date (only with incomplete todo list)"
