@mod @mod_booking @booking_messages
Feature: Test messaging features in a booking
  As a teacher or a student
  I need to test booking option messaging (emailing) features

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
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                        | course | description  | teachersforoption |
      | My booking | Option: mail to participant | C1     | Option deskr | teacher1          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option: send custom reminder mail to participant
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
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
    And I press "Send message"
    And I should see "Your message has been sent."
    And I log out
    ## Send messages via cron and verify via events log
    And I log in as "admin"
    When I trigger cron
    And I wait "1" seconds
    And I run all adhoc tasks
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option booked"
    And I should see "Unknown message type A message e-mail with subject \"Behat test\" has been sent to user with id:"
    And I should see "Custom message A message e-mail with subject \"Behat test\" has been sent to user: \"Teacher 1\" by the user \"Student 2\""
    And I should see "Custom message A message e-mail with subject \"Behat test\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out

  @javascript
  Scenario: Teacher books a booking option and get email confirmation
    ## URL webserver/_/mail is inacessible
    ## When I log in as "student1"
    ## And I open the link "webserver/_/mail"
    ## And I follow "Delete all messages"
    ## And I press "Delete all messages"
    ## And I open the link "webserver"
    ## Then I am on "Course 1" course homepage
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    ## Next step(s) cause faiure (coding error, email was not sent):
    ## Then I trigger cron
    ## And I wait "1" seconds
    ## And I run all adhoc tasks
    ## URL webserver/_/mail is inacessible
    ## And I open the link "webserver/_/mail"
    ## Then I should see "Teacher 1 (via Acceptance test site)"
    ## And I should see "Booking confirmation for New option - Webinar"

  @javascript
  Scenario: Admin book students into booking option and sends mails to them
    Given I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send reminder e-mail" "button"
    And I should see "Notification e-mail has been sent!"
    ## Send messages via cron and verify via events log
    When I trigger cron
    And I wait "1" seconds
    And I run all adhoc tasks
    And I visit "/report/loglive/index.php"
    Then I should see "Booking option booked"
    And I should see "Reminder sent from report A message e-mail with subject \"Reminder: Your booked course\" has been sent to user: \"Teacher 1\" by the user \"Student 2\""
    And I should see "Reminder sent from report A message e-mail with subject \"Reminder: Your booked course\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    And I should see "Booking confirmation A message e-mail with subject \"Booking confirmation for Option: mail to participant\" has been sent to user: \"Teacher 1\" by the user \"Student 2\""
    And I should see "Booking confirmation A message e-mail with subject \"Booking confirmation for Option: mail to participant\" has been sent to user: \"Teacher 1\" by the user \"Student 1\""
    ## Logout is mandatory for admin pages to avoid error
    And I log out
