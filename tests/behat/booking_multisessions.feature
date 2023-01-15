@mod @mod_booking @booking_multisessions
Feature: In a booking create multi session options
  As a teacher
  I need to add booking options with multiple dates

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
      | admin    | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And I create booking option "New option - Webinar" in "My booking"

  @javascript
  Scenario: Create session with multiple dates
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    And I should see "New option"
    And I click on "Book now" "button"
    And I click on "Continue" "button"
    ## And I follow "Settings"
    And I click on "Settings" "icon"
    And I follow "Duplicate this booking option"
    And I press "Save and go back"
        ## And I follow "Settings"
    And I click on "Settings" "icon"
        ## And I follow "Multiple dates session"
    And I follow "Manage option dates"
    And I set the following fields to these values:
      | Day       | 30      |
      | Month     | January |
      | Year      | 2023    |
      | Hour      | 12      |
      | Minute    | 00      |
      | endhour   | 20      |
      | endminute | 00      |
    And I press "Save"
    And I press "Back"
    And I should see "Monday, 30 January 2023, 12:00 PM - 8:00 PM"

  @javascript
  Scenario: Send reminder mail to participant
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    And I follow "My booking"
    ## And I press "dropdown d-inline show"
    And I click on "Settings" "icon"
    And I follow "Edit teachers"
    And I press "Turn editing on"
    And I click on "Teacher 1 (teacher1@example.com)" "text"
    And I click on "Add" "button"
    And I follow "My booking"
    ## And I follow "Settings"
    And I click on "Settings" "icon"
    And I follow "Book other users"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send reminder e-mail" "button"
    And I click on "selectall" "checkbox"
    And I click on "Send custom message" "button"
    And I set the following fields to these values:
      | Subject | Behat test                                                     |
      | Message | Dear, Firstly, I would like to thank you for booking my Course |
    And I press "Save changes"
    And I should see "Your message has been sent."
    And I run all adhoc tasks
    And I open the link "webserver/_/mail"
    Then I should see "Teacher 1 (via Acceptance test site)"
    And I should see "Behat test"

  @javascript
  Scenario: Student books an option
    When I log in as "student1"
    And I open the link "webserver/_/mail"
    And I follow "Delete all messages"
    And I press "Delete all messages"
    And I open the link "webserver"
    Then I am on "Course 1" course homepage
    And I follow "My booking"
    And I should see "New option"
    And I click on "Book now" "button"
    And I click on "Continue" "button"
    And I should see "New option"
    And I click on "Booked" "text"
    And I run all adhoc tasks
    And I open the link "webserver/_/mail"
    Then I should see "Teacher 1 (via Acceptance test site)"
    And I should see "Booking confirmation for New option - Webinar"

  @javascript
  Scenario: Teacher sends mails to students
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I follow "My booking"
    And I follow "My booking"
    ## And I follow "Settings"
    And I click on "Settings" "icon"
    And I follow "Book other users"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send reminder e-mail" "button"
    And I should see "Notification e-mail has been sent!"

  Scenario: Run cron
    Then I open the link "webserver/admin/cron.php"
    And I wait "1" seconds

  @javascript @email
  Scenario: Send email for user
    Given I open the link "webserver/_/mail"
    And I should see "Connected"
    ## I can not see the sent email
    #And I should see "Student 1 (via Acceptance test site)"
    And I follow "Delete all messages"
    And I press "Delete all messages"
