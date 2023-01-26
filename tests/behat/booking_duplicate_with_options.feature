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
      | admin1   | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And I create booking option "New option - duplication source" in "My booking"

  @javascript
  Scenario: Duplicate session with multiple options
    Given I log in as "admin"
    When I am on "Course 1" course homepage
    Then I should see "My booking"
    And I follow "My booking"
    And I should see "New option - duplication source"
    And I click on "Settings" "icon"
    And I follow "Edit this booking option"
    And I set the following fields to these values:
      | Prefix              | MIB |
      | Booking option name | Topic: Statistics |
      | Description | Class om Statistics |
      | Internal annotation | Statistics for medics |
      | Add new location | MI Departmant |
      | Add new institution | Morphology Institute |
      | Address | Ternopil |
    Then I set the field "Limit the number of participants" to "checked"
    And I set the following fields to these values:
      | Max. number of participants              | 15 |
      | Max. number of places on waiting list | 5 |
      | Min. number of participants | 5 |
      | duration[number] | 2 |
      | duration[timeunit] | hours |
    Then I click on "Start and end time of course are known" "checkbox"
    Then I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I set the following fields to these values:
      | coursestarttime[day]    | ##tomorrow##%d## |
      | coursestarttime[month]  | ##tomorrow##%B## |
      | coursestarttime[year]   | ##tomorrow##%Y## |
      | coursestarttime[hour]   | 09               |
      | coursestarttime[minute] | 00               |
      | courseendtime[day]    | ##tomorrow##%d##     |
      | courseendtime[month]  | ## + 2 month ## %B ## |
      | courseendtime[year]   | ##tomorrow##%Y## |
      | courseendtime[hour]   | 18                   |
      | courseendtime[minute] | 00                   |
      | Teachers poll url | https://google.com |
    Then I press "Custom dates..."
    And I set the following fields to these values:
      | optiondatestart[0][day]    | ##tomorrow##%d## |
      | optiondatestart[0][month]  | ## + 1 month ## %B ## |
      | optiondatestart[0][year]   | ##tomorrow##%Y## |
      | optiondatestart[0][hour]   | 13               |
      | optiondatestart[0][minute] | 00               |
      | optiondateend[0][day]    | ## + 2 days ## %d ##  |
      | optiondateend[0][month]  | ## + 1 month ## %B ## |
      | optiondateend[0][year]   | ##tomorrow##%Y## |
      | optiondateend[0][hour]   | 18                   |
      | optiondateend[0][minute] | 00                   |
    And I press "Save changes"
    ## And I set the following fields to these values:
    ##  | fitem_id_teachersforoption | teacher1 |
    ## And I wait "100" seconds
    And I press "Save"
    ## And I follow "Settings"
    And I click on "Settings" "icon"
    And I follow "Duplicate this booking option"
    And I press "Save and go back"
    And I click on "Settings" "icon"
    And I follow "Duplicate this booking option"
    And I press "Save and go back"
    ## And I click on "Settings" "icon"
    And I click on "#mod_booking_all_options_r1_c3 .dropdown .icon" "css_element"
    And I should see "Topic: Statistics - Copy"
    And I click on "#mod_booking_all_options_r1_c1 .fa-calendar" "css_element"
    And I wait "1" seconds
    Then I should see "## +1 year ##%Y##" in the "#mod_booking_all_options_r1_c1" "css_element"
    And I should see "30 January" in the "#mod_booking_all_options_r1_c1" "css_element"
    And I should see "12:00 PM - 8:00 PM" in the "#mod_booking_all_options_r1_c1" "css_element"
