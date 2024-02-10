@mod @mod_booking @booking_availability
Feature: Test booking options avaialbility conditions
  As a teacher I configure various availability conditions
  For different booking options

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 | idnumber |
      | teacher1 | Teacher   | 1        | teacher1@example.com  | T1       |
      | admin1   | Admin     | 1        | admin1@example.com    | A1       |
      | student1 | Student   | 1        | student1@example1.com | S1       |
      | student2 | Student   | 2        | student2@example2.com | S2       |
      | student3 | Student   | 3        | student3@example3.com | S3       |
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
    And the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Activate e-mails (confirmations, notifications and more) | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | Yes                                                      | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                           | course | description | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 | optiondateid_2 | daystonotify_2 | coursestarttime_2 | courseendtime_2 |
      | My booking | Option - advanced availability | C1     | Deskr       | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
      | My booking | Option - availability by dates | C1     | Deskr       | 1           | 0              | 0              | ## +2 days ##     | ## +3 days ##   | 0              | 0              | ## +4 days ##     | ## +5 days ##   |
      | My booking | Option - dependency            | C1     | Deskr       | 1           | 0              | 0              | ## +3 days ##     | ## +4 days ##   | 0              | 0              | ## +5 days ##     | ## +6 days ##   |

  @javascript
  Scenario: Configure availability condition by dates - until
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
      | bookingclosingtime[year]  | ## - 1 year ##%Y## |
    And I press "Save and go back"
    And I wait until the page is ready
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
    And I press "Save and go back"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r2" "css_element"

  @javascript
  Scenario: Configure availability condition by dates - after
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r2" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r2" "css_element"
    And I follow "Availability conditions"
    And I set the field "Booking is possible only until a certain date" to ""
    And I set the field "Booking is possible only after a certain date" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bookingopeningtime[day]   | ##yesterday##%d## |
      | bookingopeningtime[month] | ##yesterday##%B## |
      | bookingopeningtime[year]  | ##yesterday##%Y## |
    And I press "Save and go back"
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
    And I press "Save and go back"
    And I wait until the page is ready
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
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "User has previously booked a certain option" to "checked"
    And I wait "1" seconds
    And I set the field "Must be already booked" to "Option - dependency"
    And I wait "1" seconds
    And I press "Save and go back"
    ## Verify availability as a student
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Only users who have previously booked" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure userprofile-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "A chosen user profile field should have a certain value" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_userprofilefield_field    | Email address   |
      | bo_cond_userprofilefield_operator | contains (text) |
      | bo_cond_userprofilefield_value    | gmail.com       |
    And I press "Save and go back"
    And I wait until the page is ready
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
    And I press "Save and go back"
    ## Verify availability as a student
    Given I am on the "My booking" Activity page logged in as student1
    Then I should not see "Not allowed to book" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure user-dependent availability condition
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I press "Save and go back"
    And I wait until the page is ready
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
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I press "Save and go back"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student2
    Then I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure participants limit and waiting list
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I set the following fields to these values:
      | Max. number of participants           | 2 |
      | Max. number of places on waiting list | 1 |
      | Min. number of participants           | 1 |
    And I press "Save and go back"
    And I wait until the page is ready
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Student 1 (student1@example1.com)" "text"
    And I click on "Student 2 (student2@example2.com)" "text"
    And I click on "Add" "button"
    ## Check avaialbility as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
    Given I am on the "My booking" Activity page logged in as student3
    Then I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Fully booked - You are on the waiting list" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure max participants with admin overbooking
    Given I log in as "admin"
    And I set the following administration settings values:
      | Allow overbooking | 1 |
    When I am on the "My booking" Activity page logged in as admin
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I press "Save and go back"
    And I wait until the page is ready
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book other users" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Student 1 (student1@example1.com)" "text"
    And I click on "Add" "button"
    And I follow "<< Back to responses"
    And I follow "Booking"
    And I wait until the page is ready
    And I should see "Fully booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"

  @javascript
  Scenario: Configure combined availability conditions - date or option
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
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
    And I press "Save and go back"
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Can be booked from" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
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
    And I press "Save and go back"
    When I am on the "My booking" Activity page logged in as student1
    Then I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I should not see "Can be booked from" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure combined availability conditions - overbooking given to user
    Given I am on the "My booking" Activity page logged in as student1
    And I should see "Book now" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Click again to confirm booking" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Click again to confirm booking" "text" in the ".allbookingoptionstable_r3" "css_element"
    And I should see "Booked" in the ".allbookingoptionstable_r3" "css_element"
    ## Setup overbooking given to user
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r3" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r3" "css_element"
    And I set the field "Max. number of participants" to "1"
    And I follow "Availability conditions"
    And I set the field "Only specific user(s) are allowed to book" to "checked"
    And I set the field "User(s) allowed to book" to "Student 2"
    And I set the field "bo_cond_selectusers_overrideconditioncheckbox" to "checked"
    And I set the field "bo_cond_selectusers_overrideoperator" to "OR"
    And I set the field with xpath "//*[contains(@id, 'fitem_id_bo_cond_selectusers_overridecondition')]//*[contains(@id, 'form_autocomplete_input')]" to "Fully booked"
    And I press "Save and go back"
    And I wait until the page is ready
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
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the field "Visibility" to "Hide from normal users (visible to entitled users only)"
    And I press "Save and go back"
    And I wait until the page is ready
    And I should see "Invisible" in the ".allbookingoptionstable_r1" "css_element"
    And I am on the "My booking" Activity page logged in as student1
    Then I should not see "Option - advanced availability" in the ".allbookingoptionstable_r1" "css_element"
    And I should see "Option - availability by dates" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure availability to fill modal agreement form
    Given I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_value_1_1 | Confirm your intention |
      | bo_cond_customform_label_1_2 | Yes                    |
    And I press "Save and go back"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Confirm your intention" in the ".condition-customform" "css_element"
    And I set the field "customform_checkbox_2" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"

  @javascript
  Scenario: Configure availability to fill inline agreement form
    Given I log in as "admin"
    And I set the following administration settings values:
      | Turn off modals | 1 |
    When I am on the "My booking" Activity page logged in as teacher1
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Edit booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_value_1_1 | Confirm your intention |
      | bo_cond_customform_label_1_2 | Yes                    |
    And I press "Save and go back"
    ## Check availability as students
    Given I am on the "My booking" Activity page logged in as student1
    Then I should see "Book now" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Confirm your intention" in the ".allbookingoptionstable_r1 .condition-customform" "css_element"
    And I set the field "customform_checkbox_2" to "checked"
    And I follow "Continue"
    And I should see "You have successfully booked Option - advanced availability" in the ".allbookingoptionstable_r1 .condition-confirmation" "css_element"
    And I follow "Close"
    And I should see "Booked" in the ".allbookingoptionstable_r1" "css_element"
