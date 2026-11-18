@mod @mod_booking @booking_bookotherusers_courselogin
Feature: Course login on the book other users page
  As a trainer of a booking option who is not enrolled in the course
  I want to book other users when the setting "reportrequirecourselogin" is disabled
  So that I can manage the participants of my own option without a course enrolment

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | trainer1 | Trainer   | One      | trainer1@example.com |
      | trainer2 | Trainer   | Two      | trainer2@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    ## The trainer is NOT enrolled in the course.
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "roles" exist:
      | name                 | shortname     | description          | archetype |
      | Book for others role | bookforothers | Book for others role |           |
    And the following "permission overrides" exist:
      | capability                          | permission | role          | contextlevel | reference |
      | mod/booking:bookforothers           | Allow      | bookforothers | System       |           |
      ## Without this capability, a teacher of an option only sees users of the institution of the option.
      | mod/booking:readallinstitutionusers | Allow      | bookforothers | System       |           |
    And the following "role assigns" exist:
      | user     | role          | contextlevel | reference |
      | trainer1 | bookforothers | System       |           |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro      | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | My booking | My booking | admin          | Webinar   | All bookings                     |
    ## No "course" column: the options are not linked to a course, so the trainer is not enrolled anywhere.
    And the following "mod_booking > options" exist:
      | booking    | text           | description | teachersforoption |
      | My booking | Trainer option | Deskr       | trainer1          |
      | My booking | Other option   | Deskr       | trainer2          |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Book other users: course login is required by default
    Given I log in as "trainer1"
    When I visit the booking page "/mod/booking/subscribeusers.php?id={cmid}&optionid={optionid}" for option "Trainer option" in booking "My booking"
    Then I should see "You cannot enrol yourself in this course"
    And "#addselect" "css_element" should not exist

  @javascript
  Scenario: Book other users: trainer without enrolment books and removes a user when course login is not required
    Given the following config values are set as admin:
      | config                   | value | plugin  |
      | reportrequirecourselogin | 0     | booking |
    And I log in as "trainer1"
    When I visit the booking page "/mod/booking/subscribeusers.php?id={cmid}&optionid={optionid}" for option "Trainer option" in booking "My booking"
    Then I should see "Trainer option"
    And I should see "Student One" in the "#addselect" "css_element"
    And I click on "Student One" "text"
    And I click on "Add" "button"
    And I should see "All 1 selected users have successfully been assigned to this booking option." in the "#user-notifications" "css_element"
    And I should see "Student One" in the "#removeselect" "css_element"
    ## The trainer can also remove the user again.
    And I click on "Student One" "text"
    And I click on "Remove" "button"
    And I should not see "Student One" in the "#removeselect" "css_element"
    And I should see "Student One" in the "#addselect" "css_element"

  @javascript
  Scenario: Book other users: capability checks still apply when course login is not required
    Given the following config values are set as admin:
      | config                   | value | plugin  |
      | reportrequirecourselogin | 0     | booking |
    ## Trainer Two is a teacher of the option, but has no "bookforothers" capability.
    And I log in as "trainer2"
    When I visit the booking page "/mod/booking/subscribeusers.php?id={cmid}&optionid={optionid}" for option "Other option" in booking "My booking"
    Then I should see "You have no permission to access this page."
    And "#addselect" "css_element" should not exist
    And "#removeselect" "css_element" should not exist
