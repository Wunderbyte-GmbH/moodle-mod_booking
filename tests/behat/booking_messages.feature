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
    ## The back link leads to the Bookings tracker, which sends reminders as custom e-mails.
    And I follow "<< Back to responses"
    And I click on "//table[starts-with(@id, 'booked_option_')]//thead//input[contains(@class, 'tableheadercheckbox') and @type='checkbox']" "xpath_element"
    And I click on "//a[@data-formname='mod_booking\form\modal_send_custom_message']" "xpath_element"
    And I set the following fields to these values:
      | Subject | Behat test                                                     |
      | Message | Dear, Firstly, I would like to thank you for booking my Course |
    And I click on "//div[@data-region='modal'][.//*[@data-region='title' and normalize-space(.)='Send custom email']]//button[@data-action='save']" "xpath_element"
    And I wait until the page is ready
    And I run all booking adhoc tasks
    Then the events log should contain "Booking option booked"
    And the events log should contain "Custom message A message e-mail with subject \"Behat test\" has been sent to user: \"Student 2\" by the user \"Teacher 1\""
    And the events log should contain "Custom message A message e-mail with subject \"Behat test\" has been sent to user: \"Student 1\" by the user \"Teacher 1\""

  @javascript
  Scenario: Admin book students into booking option and sends mails to them
    ## Legacy mail templates must be used to have the expected items in the events log.
    ## The reminder e-mail of a legacy mail template is only offered on the old page "Manage bookings",
    ## so this scenario switches off the Bookings tracker as default view. The tracker sends custom e-mails
    ## and leaves reminders to booking rules.
    Given the following config values are set as admin:
      | config                 | value | plugin  |
      | uselegacymailtemplates | 1     | booking |
      | bookingstrackerdefault | 0     | booking |
    And I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student 1 (student1@example.com)" "text"
    And I click on "Student 2 (student2@example.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I click on "selectall" "checkbox"
    And I click on "Send reminder e-mail" "button"
    And I should see "Notification e-mail has been sent!"
    And I run all booking adhoc tasks
    Then the events log should contain "Booking option booked"
    And the events log should contain "Reminder sent from report A message e-mail with subject \"Reminder: Your booked course\" has been sent to user: \"Student 2\" by the user \"Teacher 1\""
    And the events log should contain "Reminder sent from report A message e-mail with subject \"Reminder: Your booked course\" has been sent to user: \"Student 1\" by the user \"Teacher 1\""
    And the events log should contain "Booking confirmation A message e-mail with subject \"Booking confirmation for Option: mail to participant\" has been sent to user: \"Student 2\" by the user \"Teacher 1\""
    And the events log should contain "Booking confirmation A message e-mail with subject \"Booking confirmation for Option: mail to participant\" has been sent to user: \"Student 1\" by the user \"Teacher 1\""
