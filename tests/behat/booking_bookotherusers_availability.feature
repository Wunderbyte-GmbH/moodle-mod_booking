@mod @mod_booking @booking_bookotherusers_availability
Feature: Availability conditions on the book other users page
  As a privileged user booking other users
  I want the global setting to decide whether availability conditions
  are ignored, shown as a confirmable warning or block the booking

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@allowed.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name        | intro       | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BFO booking | BFO booking | teacher1       | Webinar   | All bookings                     |
    ## Only users with an email containing "allowed.com" meet the availability condition.
    And the following "mod_booking > options" exist:
      | booking     | text              | course | description | bo_cond_userprofilefield_1_default_restrict | bo_cond_userprofilefield_field | bo_cond_userprofilefield_operator | bo_cond_userprofilefield_value |
      | BFO booking | Restricted option | C1     | Deskr       | 1                                           | email                          | ~                                 | allowed.com                    |
    ## A second instance with max 1 booking per user; student2 already used up the limit.
    And the following "activities" exist:
      | activity | course | name          | intro         | bookingmanager | eventtype | Default view for booking options | maxperuser |
      | booking  | C1     | Limit booking | Limit booking | teacher1       | Webinar   | All bookings                     | 1          |
    ## The options need dates: only answers of options with courseendtime 0 or in the future
    ## count towards the max bookings per user limit (a NULL courseendtime would not count).
    And the following "mod_booking > options" exist:
      | booking       | text           | course | description | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 |
      | Limit booking | Limit option 1 | C1     | Deskr       | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
      | Limit booking | Limit option 2 | C1     | Deskr       | 1           | 0              | 0              | ## +2 days ##     | ## +4 days ##   |
    And the following "mod_booking > answers" exist:
      | booking       | option         | user     |
      | Limit booking | Limit option 1 | student2 |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Book other users: availability conditions are ignored by default
    Given I am on the "BFO booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student One (student1@allowed.com)" "text"
    And I click on "Student Two (student2@example.com)" "text"
    When I click on "Add" "button"
    Then I should see "All 2 selected users have successfully been assigned to this booking option." in the "#user-notifications" "css_element"
    And I should see "student1@allowed.com" in the "#removeselect" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Book other users: warning about users not meeting the availability conditions has to be confirmed
    Given the following config values are set as admin:
      | config                     | value | plugin  |
      | bookotherusersavailability | 1     | booking |
    And I am on the "BFO booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student One (student1@allowed.com)" "text"
    And I click on "Student Two (student2@example.com)" "text"
    When I click on "Add" "button"
    Then I should see "The following users do not meet the availability conditions of this booking option:"
    And I should see "Student Two: Only users with user profile field email set to value allowed.com are allowed to book."
    And I should see "Do you want to book the selected users anyway?"
    ## Cancelling books nobody.
    When I press "Cancel"
    Then I should not see "student1@allowed.com" in the "#removeselect" "css_element"
    And I should not see "student2@example.com" in the "#removeselect" "css_element"
    ## Confirming books all selected users.
    When I click on "Student One (student1@allowed.com)" "text"
    And I click on "Student Two (student2@example.com)" "text"
    And I click on "Add" "button"
    And I press "Book anyway"
    Then I should see "All 2 selected users have successfully been assigned to this booking option." in the "#user-notifications" "css_element"
    And I should see "student1@allowed.com" in the "#removeselect" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Book other users: users not meeting the availability conditions are blocked
    Given the following config values are set as admin:
      | config                     | value | plugin  |
      | bookotherusersavailability | 2     | booking |
    And I am on the "BFO booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Student One (student1@allowed.com)" "text"
    And I click on "Student Two (student2@example.com)" "text"
    When I click on "Add" "button"
    Then I should see "The following users were not booked because they do not meet the availability conditions of this booking option:" in the "#user-notifications" "css_element"
    And I should see "Student Two: Only users with user profile field email set to value allowed.com are allowed to book." in the "#user-notifications" "css_element"
    And I should see "student1@allowed.com" in the "#removeselect" "css_element"
    And I should not see "student2@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Book other users: by default the max number of bookings per user still blocks the booking
    Given I am on the "Limit booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the "//div[contains(@class, 'allbookingoptionstable_r') and contains(., 'Limit option 2')]" "xpath_element"
    And I click on "Book other users" "link" in the "//div[contains(@class, 'allbookingoptionstable_r') and contains(., 'Limit option 2')]" "xpath_element"
    And I click on "Student Two (student2@example.com)" "text"
    When I click on "Add" "button"
    Then I should see "The following users could not be booked due to reaching the max number of bookings per user or lack of available places for the booking option:" in the "#user-notifications" "css_element"
    And I should not see "student2@example.com" in the "#removeselect" "css_element"

  @javascript
  Scenario: Book other users: confirmed warning overrides the max number of bookings per user
    Given the following config values are set as admin:
      | config                     | value | plugin  |
      | bookotherusersavailability | 1     | booking |
    And I am on the "Limit booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the "//div[contains(@class, 'allbookingoptionstable_r') and contains(., 'Limit option 2')]" "xpath_element"
    And I click on "Book other users" "link" in the "//div[contains(@class, 'allbookingoptionstable_r') and contains(., 'Limit option 2')]" "xpath_element"
    And I click on "Student Two (student2@example.com)" "text"
    When I click on "Add" "button"
    Then I should see "The following users do not meet the availability conditions of this booking option:"
    And I should see "Student Two: User has reached the max number of bookings (already booked in booking options: Limit option 1)"
    When I press "Book anyway"
    Then I should see "All 1 selected users have successfully been assigned to this booking option." in the "#user-notifications" "css_element"
    And I should see "student2@example.com" in the "#removeselect" "css_element"
