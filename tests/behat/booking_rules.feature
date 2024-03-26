@mod @mod_booking @booking_rules
Feature: Create global booking rules as admin and insure they are working.

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
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options | Send confirmation e-mail |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     | Yes                      |
    And the following "mod_booking > options" exist:
      | booking     | text            | course | description | limitanswers | maxanswers | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | optiondateid_2 | daystonotify_2 | coursestarttime_2 | courseendtime_2 |
      | BookingCMP  | Option-tenis    | C1     | Deskr1      | 1            | 2          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
      | BookingCMP  | Option-football | C1     | Deskr2      | 1            | 4          | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +4 days ##   |
    ## And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking rules: create settings for booking rules via UI as admin and edit it
    Given I log in as "admin"
    And I visit "/mod/booking/edit_rules.php"
    And I click on "Add rule" "text"
    ## And I set the field "Campaign type" to "Change price or booking limit"
    And I set the following fields to these values:
      | Custom name for the rule | notifyadmin    |
      | Rule                     | React on event |
    And I wait "2" seconds
    And I set the following fields to these values:
      | Event                 | Substitution teacher was added (optiondates_teacher_added)     |
      | Condition of the rule | Directly select users without connection to the booking option |
    And I wait "1" seconds
    ## Mandatory workaround for autocomplete field
    And I set the field "Select the users you want to target" to "admin"
    ##And I set the field "Select the users you want to target" to "Admin User (ID: 2) | moodle@example.com"
    And I wait "1" seconds
    And I set the following fields to these values:
      | Subject                             | Teacher was substituted              |
      | Message                             | Teacher was substituted successfully |
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "notifyadmin"
    And I click on "Edit" "text" in the ".booking-rules-list" "css_element"
    And I wait "1" seconds
    And I set the field "Custom name for the rule" to "rule1-notifyadmin"
    And I click on "Save changes" "button"
    And I wait until the page is ready
    And I should see "rule1-notifyadmin"
