@mod @mod_booking @booking_enrollink
Feature: Create enrollink availability form for booking options with connected course as admin and booking it as a student.

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname     | name         |
      | text     | userpricecat  | userpricecat |
    And the following "mod_booking > pricecategories" exist:
      | ordernum | identifier | name  | defaultvalue | disabled | pricecatsortorder |
      | 1        | default    | Price | 69           | 0        | 1                 |
      | 2        | discount1  | Disc1 | 59           | 0        | 2                 |
      | 3        | discount2  | Disc2 | 49           | 0        | 3                 |
    And the following "users" exist:
      | username | firstname | lastname | email                | idnumber | profile_field_userpricecat |
      | teacher1 | Teacher   | 1        | teacher1@example.com | T1       |                            |
      | student1 | Student   | 1        | student1@example.com | S1       | default                    |
      | student2 | Student   | 2        | student2@example.com | S2       | discount1                  |
      | student3 | Student   | 3        | student3@example.com | S3       | discount2                  |
    And the following config values are set as admin:
      | config             | value        | plugin  |
      | pricecategoryfield | userpricecat | booking |
    And the following "core_payment > payment accounts" exist:
      | name           |
      | Account1       |
    And the following "local_shopping_cart > payment gateways" exist:
      | account  | gateway | enabled | config                                                                                |
      | Account1 | paypal  | 1       | {"brandname":"Test paypal","clientid":"Test","secret":"Test","environment":"sandbox"} |
    And the following "local_shopping_cart > user credits" exist:
      | user     | credit | currency |
      | student1 | 300    | EUR      |
      | student2 | 350    | EUR      |
      | student3 | 400    | EUR      |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course1  | C1        | 0        | 1                |
      | Course2  | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C1     | manager        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | teacher1 | C2     | editingteacher |
      | teacher1 | C2     | manager        |
    And the following "activities" exist:
      | activity | course | name       | intro               | bookingmanager | eventtype | Default view for booking options |
      | booking  | C1     | BookingCMP | Booking description | teacher1       | Webinar   | All bookings                     |
    And the following "mod_booking > options" exist:
      | booking     | text         | course | description | chooseorcreatecourse | enrolmentstatus | useprice | maxanswers | datesmarker | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | BookingCMP  | Option-form  | C1     | Price-form  | 0                    | 2               | 1        | 6          | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking option enrollink: create and validate
    Given I am on the "BookingCMP" Activity page logged in as teacher1
    And I click on "Edit booking option" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I wait until the page is ready
    And I expand all fieldsets
    ## And I follow "Availability conditions"
    And I set the field "Form needs to be filled out before booking" to "checked"
    And I wait "1" seconds
    And I set the following fields to these values:
      | bo_cond_customform_select_1_1   | enrolusersaction |
      | bo_cond_customform_label_1_1    | Number of user   |
      | bo_cond_customform_value_1_1    | 2                |
    ##And I press "Save"
    ## Should be valiation error.
    ##And I should see "A related course is needed because of your availabilty condition(s)." in the "//div[contains(@id, 'fitem_id_chooseorcreatecourse_')]" "xpath_element"
    ## And I follow "Moodle course"
    ## To avoid duplicated field label "Connected Moodle course"!
    And I set the field "chooseorcreatecourse" to "Connected Moodle course"
    And I wait "1" seconds
    And I set the field with xpath "//div[contains(@id, 'fitem_id_courseid_')]//input[contains(@id, 'form_autocomplete_input-')]" to "Course2"
    And I press "Save"
    And I wait "21" seconds
    And I log out
