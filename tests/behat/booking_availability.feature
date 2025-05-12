@mod @mod_booking @booking_availability
Feature: Test booking options avaialbility conditions
  As a teacher I configure various availability conditions
  For different booking options

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name   |
      | text     | sport     | Sport  |
      | text     | credit    | Credit |
    Given the following "users" exist:
      | username | firstname | lastname | email                 | idnumber | profile_field_sport | profile_field_credit |
      | teacher1 | Teacher   | 1        | teacher1@example.com  | T1       |                     |                      |
      | admin1   | Admin     | 1        | admin1@example.com    | A1       |                     |                      |
      | student1 | Student   | 1        | student1@example1.com | S1       | football            |                      |
      | student2 | Student   | 2        | student2@example2.com | S2       | tennis              |                      |
      | student3 | Student   | 3        | student3@example3.com | S3       | football            | 100                  |
    And the following "cohorts" exist:
      | name                    | idnumber | visible |
      | System booking cohort 1 | SBC1     | 1       |
      | System booking cohort 2 | SBC2     | 1       |
    And the following "cohort members" exist:
      | user     | cohort |
      | student2 | SBC1   |
      | student3 | SBC1   |
      | student3 | SBC2   |
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
      | student3 | C1     | student        |
    And I clean booking cache
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                           | course | description | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Option - advanced availability | C1     | Deskr       | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
      | My booking | Option - availability by dates | C1     | Deskr       | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +5 days ##   |
      | My booking | Option - dependency            | C1     | Deskr       | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0              | 0              | ## +5 days ##     | ## +6 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Configure availability condition by dates - until
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only after a certain date" to ""
    And I set the field "Booking is possible only until a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingclosingtime[day]   | 10                 |
      | bookingclosingtime[month] | May                |
      | bookingclosingtime[year]  | ## - 1 year ##%Y## |
    And I press "Save"
    And I should see "Cannot be booked anymore" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "May 10" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "## - 1 year ##%Y##" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Cannot be booked anymore" in the ".allbookingoptionstable_r2" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r2" "css_element"
    ## Update availability as a teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only after a certain date" to ""
    And I set the field "Booking is possible only until a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingclosingtime[day]   | 10                 |
      | bookingclosingtime[month] | May                |
      | bookingclosingtime[year]  | ## + 1 year ##%Y## |
    And I press "Save"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Configure availability condition by dates - after
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only until a certain date" to ""
    And I set the field "Booking is possible only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]   | ##yesterday##%d## |
      | bookingopeningtime[month] | ##yesterday##%B## |
      | bookingopeningtime[year]  | ##yesterday##%Y## |
    And I press "Save"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r2" "css_element"
    ## Update availability as a teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only until a certain date" to ""
    And I set the field "Booking is possible only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]    | 10                 |
      | bookingopeningtime[month]  | March              |
      | bookingopeningtime[year]   | ## + 1 year ##%Y## |
    And I press "Save"
    And I should see "Can be booked from" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "March 10" in the ".allbookingoptionstable_r2" "css_element"
    And I should see "## + 1 year ##%Y##" in the ".allbookingoptionstable_r2" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Can be booked from" in the ".allbookingoptionstable_r2" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Configure bookingoption-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "User has previously booked a certain option" to "checked"
    And I wait "1" seconds
    And I set the field "Must be already booked" to "Option - dependency"
    And I wait "1" seconds
    And I press "Save"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure userprofile-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A chosen user profile field should have a certain value" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_userprofilefield_field    | Email address   |
      | bo_cond_userprofilefield_operator | contains (text) |
      | bo_cond_userprofilefield_value    | gmail.com       |
    And I press "Save"
    And I should see "Only users with user profile field email set to value gmail.com are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    ## Update availability as a teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A chosen user profile field should have a certain value" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_userprofilefield_field    | Email address   |
      | bo_cond_userprofilefield_operator | contains (text) |
      | bo_cond_userprofilefield_value    | example1.com    |
    And I press "Save"
    ## Verify availability as a student
    Given I am on the "My booking" Activity page logged in as student1
    Then I should not see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure usercustomprofile-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A custom user profile field should have a certain value" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customuserprofilefield_field              | Sport                                   |
      | bo_cond_customuserprofilefield_operator           | has exactly this value (text or number) |
      | bo_cond_customuserprofilefield_value              | football                                |
      | bo_cond_customuserprofilefield_connectsecondfield | AND additional field                    |
      | bo_cond_customuserprofilefield_field2             | Credit                                  |
      | bo_cond_customuserprofilefield_operator2          | is bigger than (number)                 |
      | bo_cond_customuserprofilefield_value2             | 500                                     |
    And I press "Save"
    And I should see "Only users with custom user profile field sport set to value football are allowed to book." in the ".allbookingoptionstable_r3" "css_element"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student3
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    ## Update availability as a teacher
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    ##And I set the field "A chosen user profile field should have a certain value" to "checked"
    ##And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customuserprofilefield_value2             | 50       |
    And I press "Save"
    ## Verify availability as a student
    Given I am on the "My booking" Activity page logged in as student3
    Then I should not see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    ## Verify NOT availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure user-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I press "Save"
    Then I should see "Only the following users are allowed to book:" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Student 2" in the ".allbookingoptionstable_r3" "css_element"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Booking not allowed" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student2
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure max participants limit
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I press "Save"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student2
    Then I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure participants limit and waiting list
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I set the following fields to these values:
      | Max. number of participants           | 2 |
      | Max. number of places on waiting list | 1 |
      | Min. number of participants           | 1 |
    And I press "Save"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Student 1 (student1@example1.com)" "text"
    And I click on "Student 2 (student2@example2.com)" "text"
    And I click on "Add" "button"
    ## Check avaialbility as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student3
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "You are on the waiting list" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure max participants with admin overbooking
    Given the following config values are set as admin:
       | config                    | value | plugin  |
       | allowoverbooking          | 1     | booking |
    And I log in as "admin"
    When I am on the "My booking" Activity page logged in as admin
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I press "Save"
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Student 1 (student1@example1.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I follow "Booking"
    And I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure combined availability conditions - date or option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only until a certain date" to ""
    And I set the field "Booking is possible only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]   | 10                 |
      | bookingopeningtime[month] | March              |
      | bookingopeningtime[year]  | ## + 1 year ##%Y## |
    And I set the field "User has previously booked a certain option" to "checked"
    And I wait "1" seconds
    And I set the field "User has previously booked a certain option" to "checked"
    And I set the field "Must be already booked" to "Option - dependency"
    And I press "Save"
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Can be booked from" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Can be booked from" in the ".allbookingoptionstable_r1" "css_element"
    ## Configure OR option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "bo_cond_previouslybooked_overrideconditioncheckbox" to "checked"
    And I set the field "bo_cond_previouslybooked_overrideoperator" to "OR"
    And I wait "1" seconds
    ## And I set the field "Condition" to "Only bookable within a certain time"
    And I set the field with xpath "//*[contains(@id, 'fitem_id_bo_cond_previouslybooked_overridecondition')]//*[contains(@id, 'form_autocomplete_input')]" to "Only bookable within a certain time"
    And I press "Save"
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Can be booked from" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure combined availability conditions - overbooking given to user
    Given I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Start" in the ".allbookingoptionstable_r3" "css_element"
    ## Setup overbooking given to user
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I set the field "bo_cond_selectusers_overrideconditioncheckbox" to "checked"
    And I set the field "bo_cond_selectusers_overrideoperator" to "OR"
    And I set the field with xpath "//*[contains(@id, 'fitem_id_bo_cond_selectusers_overridecondition')]//*[contains(@id, 'form_autocomplete_input')]" to "Fully booked"
    And I press "Save"
    And I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    ## Check availability as student2
    When I am on the "My booking" Activity page logged in as student2
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "/ 1" in the ".allbookingoptionstable_r3 .col-ap-availableplaces" "css_element"
    And I should see "1" in the ".allbookingoptionstable_r3 .col-ap-availableplaces .text-danger" "css_element"

  @javascript
  Scenario: Configure booking availability by setup invisible booking option
    Given I am on the "My booking" Activity page logged in as student1
    And I should see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Visibility" to "Hide from normal users (visible to entitled users only)"
    And I press "Save"
    And I should see "Invisible" in the ".allbookingoptionstable_r1" "css_element"
    And I am on the "My booking" Activity page logged in as student1
    Then I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Option - availability by dates" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure availability to fill modal agreement form
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | select                           |
      | bo_cond_customform_label_1_1    | Choose what you agree            |
      | bo_cond_customform_notempty_1_1 | 1                                |
    And I set the field "bo_cond_customform_value_1_1" to multiline:
    """
    1 => option one
    2 => option two
    """
    And I press "Save"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Choose what you agree" in the ".condition-customform" "css_element"
    And I set the field "customform_select_1" to "option one"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure availability with modal form and data deletion
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1 | shorttext               |
      | bo_cond_customform_label_1_1  | Personal requirement:   |
      | bo_cond_customform_select_1_2 | deleteinfoscheckboxuser |
    And I press "Save"
    And I log out
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Personal requirement:" in the ".condition-customform" "css_element"
    And I should see "Would you like the information provided here to be deleted after the event is over?" in the ".condition-customform" "css_element"
    And I set the field "customform_shorttext_1" to "lactose-free milk"
    And I set the field "customform_deleteinfoscheckboxuser" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Check customform value as admin
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "<< Back to responses"
    And I should see "student1" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "lactose-free milk" in the "#mod_booking_all_users_sort_new_r0" "css_element"

  @javascript
  Scenario: Configure availability with modal form and multiple elements
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | static                |
      | bo_cond_customform_label_1_1    | Static lavel          |
      | bo_cond_customform_value_1_1    | Static text           |
      | bo_cond_customform_select_1_2   | url                   |
      | bo_cond_customform_label_1_2    | Provide URL:          |
      | bo_cond_customform_value_1_2    | Provide a valid URL   |
      | bo_cond_customform_notempty_1_2 | 1                     |
      | bo_cond_customform_select_1_3   | mail                  |
      | bo_cond_customform_label_1_3    | Provide email:        |
      | bo_cond_customform_value_1_3    | Provide a valid email |
      | bo_cond_customform_notempty_1_3 | 1                     |
    And I press "Save"
    And I log out
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Static lavel" in the ".condition-customform" "css_element"
    And I should see "Static text" in the ".condition-customform" "css_element"
    And I should see "Provide URL:" in the ".condition-customform" "css_element"
    And I should see "Provide email:" in the ".condition-customform" "css_element"
    ## Chack form validation
    And I follow "Continue"
    And I should see "The URL is not valid or does not start with http or https." in the ".condition-customform" "css_element"
    And I should see "The email address is invalid." in the ".condition-customform" "css_element"
    And I set the field "customform_url_2" to "https://test.com"
    And I set the field "customform_mail_3" to "test@test.com"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Check customform value as admin
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "<< Back to responses"
    And I should see "student1" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "https://test.com" in the "#mod_booking_all_users_sort_new_r0" "css_element"
    And I should see "test@test.com" in the "#mod_booking_all_users_sort_new_r0" "css_element"

  @javascript
  Scenario: Configure availability to fill inline agreement form
    Given the following config values are set as admin:
       | config                 | value | plugin  |
       | turnoffmodals          | 1     | booking |
    And I log in as "admin"
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | advcheckbox            |
      | bo_cond_customform_label_1_1    | Confirm your intention |
      | bo_cond_customform_notempty_1_1 | 1                      |
    And I press "Save"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Confirm your intention" in the ".allbookingoptionstable_r1 .condition-customform" "css_element"
    And I set the field "customform_advcheckbox_1" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".allbookingoptionstable_r1 .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Start" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Option availability: check users cohort settings
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "User is enrolled in certain cohort(s)" to "checked"
    And I wait "1" seconds
    ## Teacher: hide unavailable option and require both cohort membership
    And I set the following fields to these values:
      | Cohort(s)                                    | System booking cohort 1,System booking cohort 2 |
      | bo_cond_enrolledincohorts_cohortids_operator | User has to be member of all cohorts            |
      | bo_cond_enrolledincohorts_sqlfiltercheck     | 1                                               |
    And I press "Save"
    And I log out
    ## Check availability as students - only student3 supposed to see
    When I am on the "My booking" Activity page logged in as student1
    Then I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    And I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    ## And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student3
    And I should see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    ## Teacher: show unavailable option and require at least one cohort membership
    And I am on the "My booking" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I wait "1" seconds
    ##And I set the field "bo_cond_enrolledincohorts_sqlfiltercheck" to ""
    And I set the following fields to these values:
     | bo_cond_enrolledincohorts_cohortids_operator | User has to be member to at least one of these cohorts |
     | bo_cond_enrolledincohorts_sqlfiltercheck     |                                                        |
    And I press "Save"
    And I log out
    ## Check availability as student1
    And I am on the "My booking" Activity page logged in as student1
    And I should see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Booking not allowed because you are not enrolled in at least one of the following cohort(s): System booking cohort 1, System booking cohort 2" in the ".allbookingoptionstable_r1" "css_element"
    ## And I should see "Booking not allowed because you are not enrolled in all of the following cohort(s): System booking cohort 1, System booking cohort 2" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
    And I am on the "My booking" Activity page logged in as student2
    And I should see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I log out
