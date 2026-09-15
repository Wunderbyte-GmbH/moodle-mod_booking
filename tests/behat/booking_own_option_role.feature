@mod @mod_booking @booking_own_option_role
Feature: Pages a role with the own option capability can open
  As a teacher of booking options
  I need a role that opens the booking pages of my options
  So that splitting the capability mod/booking:addeditownoption changes nothing for customers

  # Baseline for the split of mod/booking:addeditownoption: after the split, only
  # the "permission overrides" table changes to the new capabilities.
  # trainer1 holds the role, trainer2 is teacher of the same option without the role.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | trainer1 | Trainer   | One      | trainer1@example.com |
      | trainer2 | Trainer   | Two      | trainer2@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | trainer1 | C1     | student |
      | trainer2 | C1     | student |
      | student1 | C1     | student |
    And the following "roles" exist:
      | name            | shortname     | description     | archetype |
      | Own option role | ownoptionrole | Own option role |           |
    And the following "permission overrides" exist:
      | capability                   | permission | role          | contextlevel | reference |
      | mod/booking:addeditownoption | Allow      | ownoptionrole | System       |           |
    And the following "role assigns" exist:
      | user     | role          | contextlevel | reference |
      | trainer1 | ownoptionrole | System       |           |
    And the following "activities" exist:
      | activity | course | name       | intro              | bookingmanager | eventtype |
      | booking  | C1     | My booking | My booking details | admin          | Webinar   |
    And the following "mod_booking > options" exist:
      | booking    | text       | course | description | teachersforoption | importing | maxanswers |
      | My booking | Own option | C1     | Own option  | trainer1,trainer2 | 1         | 5          |

  Scenario: Own option role: open the teacher reports and the templates of the booking activity
    Given I log in as "trainer1"
    When I visit the booking page "/mod/booking/teachers_instance_report.php?cmid={cmid}" for option "Own option" in booking "My booking"
    Then I should not see "Access denied"
    When I visit the booking page "/mod/booking/optiondates_teachers_report.php?cmid={cmid}&optionid={optionid}" for option "Own option" in booking "My booking"
    Then I should not see "Access denied"
    When I visit the booking page "/mod/booking/teacher_performed_units_report.php?teacherid={userid}" for option "Own option" in booking "My booking"
    Then I should not see "Access denied"
    When I visit the booking page "/mod/booking/instancetemplatessettings.php" for option "Own option" in booking "My booking"
    Then I should not see "Access denied"
    When I visit the booking page "/mod/booking/bookinginstancetemplatessettings.php?id={cmid}" for option "Own option" in booking "My booking"
    Then I should not see "you do not currently have permissions"

  Scenario: Own option role: without the role the teacher reports and the templates are closed
    Given I log in as "trainer2"
    When I visit the booking page "/mod/booking/teachers_instance_report.php?cmid={cmid}" for option "Own option" in booking "My booking"
    Then I should see "Access denied"
    When I visit the booking page "/mod/booking/optiondates_teachers_report.php?cmid={cmid}&optionid={optionid}" for option "Own option" in booking "My booking"
    Then I should see "Access denied"
    When I visit the booking page "/mod/booking/teacher_performed_units_report.php?teacherid={userid}" for option "Own option" in booking "My booking"
    Then I should see "Access denied"
    When I visit the booking page "/mod/booking/instancetemplatessettings.php" for option "Own option" in booking "My booking"
    Then I should see "Access denied"

  Scenario: Own option role: send mail to the booked users from the report page of an own option
    Given the following config values are set as admin:
      | config                         | value | plugin  |
      | teachersallowmailtobookedusers | 1     | booking |
    And the following "mod_booking > answers" exist:
      | booking    | option     | user     |
      | My booking | Own option | student1 |
    When I log in as "trainer1"
    And I visit the booking page "/mod/booking/report.php?id={cmid}&optionid={optionid}" for option "Own option" in booking "My booking"
    Then I should see "Send e-mail to all booked users"
    And I log out
    When I log in as "trainer2"
    And I visit the booking page "/mod/booking/report.php?id={cmid}&optionid={optionid}" for option "Own option" in booking "My booking"
    Then I should not see "Send e-mail to all booked users"
