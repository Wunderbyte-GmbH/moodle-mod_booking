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
    And I visit "/admin/category.php?category=modbookingfolder"
    And I follow "Booking: Price categories"
    And I set the following fields to these values:
      | Default price category name | Base Price |
      | Default price value         | 70         |
      | Sort order (number)         | 1          |
    And I set the field "Add price category" to "checked"
    And I set the following fields to these values:
      | pricecategoryidentifier2 | specialprice  |
      | pricecategoryname2       | Special Price |
      | defaultvalue2            | 60            |
      | pricecatsortorder2       | 2             |
    And I press "Save changes"
    Then I should see "Price categories were saved"
    And I wait "1" seconds
    Given I am on "Course 1" course homepage
    Then I should see "My booking"
    And I follow "My booking"
    And I should see "New option - duplication source"
    And I click on "Settings" "icon"
    And I follow "Edit this booking option"
    And I set the following fields to these values:
      | Prefix              | MIB                   |
      | Booking option name | Topic: Statistics     |
      | Description         | Class om Statistics   |
      | Internal annotation | Statistics for medics |
      | Add new location    | MI Departmant         |
      | Add new institution | Morphology Institute  |
      | Address             | Ternopil              |
    And I set the field "Limit the number of participants" to "checked"
    And I set the following fields to these values:
      | Max. number of participants           | 10    |
      | Max. number of places on waiting list | 5     |
      | Min. number of participants           | 3     |
      | duration[number]                      | 2     |
      | duration[timeunit]                    | hours |
    And I click on "Start and end time of course are known" "checkbox"
    And I set the following fields to these values:
      | coursestarttime[day]    | ##tomorrow##%d##      |
      | coursestarttime[month]  | ##tomorrow##%B##      |
      | coursestarttime[year]   | ##tomorrow##%Y##      |
      | coursestarttime[hour]   | 09                    |
      | coursestarttime[minute] | 00                    |
      | courseendtime[day]      | ##tomorrow##%d##      |
      | courseendtime[month]    | ## + 2 month ## %B ## |
      | courseendtime[year]     | ##tomorrow##%Y##      |
      | courseendtime[hour]     | 18                    |
      | courseendtime[minute]   | 00                    |
      | Teachers poll url       | https://google.com    |
      | reoccurringdatestring   | FR, 13:30 - 14:30     |
    And I set the field "Add to course calendar" to "Add to calendar (visible only to course participants)"
    And I set the field "Assign teachers:" to "Teacher 1 (teacher1@example.com)"
    And I wait "1" seconds
    And I set the following fields to these values:
      | pricegroup_default[bookingprice_default]           | 75                            |
      | pricegroup_specialprice[bookingprice_specialprice] | 65                            |
      | Notification message                               | Advanced notification message |
      | Before booked                                      | Before booked message         |
      | After booked                                       | After booked message          |
    And I press "Save and go back"
    ## Create 1st copy
    And I click on "Settings" "icon"
    And I follow "Duplicate this booking option"
    And I set the field "Booking option name" to "Topic: Statistics - Copy 1"
    And I press "Save and go back"
    ## Create 2nd copy
    And I click on "Settings" "icon"
    And I follow "Duplicate this booking option"
    And I set the field "Booking option name" to "Topic: Statistics - Copy 2"
    And I press "Save and go back"
    ## Verify name for 1st copy
    And I should see "Topic: Statistics - Copy 1"
    And I should see "Topic: Statistics - Copy 2"
    ## Verify options for 2nd copy
    And I click on "#mod_booking_all_options_r2_c3 .dropdown .icon" "css_element"
    And I click on "#mod_booking_all_options_r2_c3 .fa-pencil" "css_element"
    And the following fields match these values:
      | Prefix              | MIB                        |
      | Booking option name | Topic: Statistics - Copy 2 |
      | Description         | Class om Statistics        |
      | Internal annotation | Statistics for medics      |
      | Address             | Ternopil                   |
    And I should see "MI Departmant" in the "#fitem_id_location" "css_element"
    And I should see "Morphology Institute" in the "#fitem_id_institution" "css_element"
    And I should see "Teacher 1 (teacher1@example.com)" in the "#id_bookingoptionteacherscontainer" "css_element"
    And the field "Limit the number of participants" matches value "checked"
    And the field "Start and end time of course are known" matches value "checked"
    And the following fields match these values:
      | Max. number of participants           | 10                            |
      | Max. number of places on waiting list | 5                             |
      | Min. number of participants           | 3                             |
      | duration[number]                      | 2                             |
      | duration[timeunit]                    | hours                         |
      | coursestarttime[day]                  | ##tomorrow##%d##              |
      | coursestarttime[month]                | ##tomorrow##%B##              |
      | coursestarttime[year]                 | ##tomorrow##%Y##              |
      | coursestarttime[hour]                 | 09                            |
      | coursestarttime[minute]               | 00                            |
      | courseendtime[day]                    | ##tomorrow##%d##              |
      | courseendtime[month]                  | ## + 2 month ## %B ##         |
      | courseendtime[year]                   | ##tomorrow##%Y##              |
      | courseendtime[hour]                   | 18                            |
      | courseendtime[minute]                 | 00                            |
      | Teachers poll url                     | https://google.com            |
      | reoccurringdatestring                 | FR, 13:30 - 14:30             |
      | pricegroup_default[bookingprice_default]           | 75                            |
      | pricegroup_specialprice[bookingprice_specialprice] | 65                            |
      | Notification message                  | Advanced notification message |
      | Before booked                         | Before booked message         |
      | After booked                          | After booked message          |
