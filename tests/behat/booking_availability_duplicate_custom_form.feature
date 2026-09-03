@mod @mod_booking @booking_availability_duplicate_custom_form
Feature: Test booking options avaialbility custom form conditions with duplicated options
  As a teacher I configure custom form availability conditions
  Duplicate booking options and validate custom form in the duplicated option

  Background:
    Given the following "custom profile fields" exist:
      | datatype | shortname | name   |
      | text     | sport     | Sport  |
    Given the following "users" exist:
      | username | firstname | lastname | email                 | idnumber | profile_field_sport |
      | teacher1 | Teacher   | 1        | teacher1@example.com  | T1       |                     |
      | admin1   | Admin     | 1        | admin1@example.com    | A1       |                     |
      | student1 | Student   | 1        | student1@example1.com | S1       | football            |
      | student2 | Student   | 2        | student2@example2.com | S2       | tennis              |
      | student3 | Student   | 3        | student3@example3.com | S3       | football            |
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
      | activity | course | name       | intro                  | bookingmanager | eventtype | Default view for booking options | Booking option name  |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | All bookings                     | New option - Webinar |
    And the following "mod_booking > options" exist:
      | booking    | text                           | course | description | datesmarker | optiondateid_0 | daystonotify_0 | coursestarttime_0 | courseendtime_0 | optiondateid_1 | daystonotify_1 | coursestarttime_1 | courseendtime_1 |
      | My booking | Option - advanced availability | C1     | Deskr       | 1           | 0              | 0              | ## tomorrow ##    | ## +2 days ##   | 0              | 0              | ## +3 days ##     | ## +4 days ##   |
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Configure availability with modal custom form and multiple elements and duplicate it and validate the copy
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
      | bo_cond_customform_select_1_4   | shorttext             |
      | bo_cond_customform_label_1_4    | Personal requirement: |
      | bo_cond_customform_select_1_5   | advcheckbox           |
      | bo_cond_customform_label_1_5    | Valid?                |
      | bo_cond_customform_select_1_6   | select                |
      | bo_cond_customform_label_1_6    | Choose what you agree |
      | bo_cond_customform_notempty_1_6 | 1                     |
    And I set the field "bo_cond_customform_value_1_6" to multiline:
    """
    A
    B
    C
    """
    And I press "Save"
    ## Duplicate option
    And I click on "Settings" "icon" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Duplicate this booking option" "link" in the ".allbookingoptionstable_r1" "css_element"
    And I set the following fields to these values:
      | Booking option name | Customform - Copy |
    And I press "Save"
    And I log out
    ## Check availability as students
    When I am on the "My booking" Activity page logged in as student1
    And I should see "Customform - Copy" in the ".allbookingoptionstable_r1" "css_element"
    And I click on "Book now" "text" in the ".allbookingoptionstable_r1" "css_element"
    Then I should see "Static text" in the ".condition-customform" "css_element"
    And I should see "Provide URL:" in the ".condition-customform" "css_element"
    And I should see "Provide email:" in the ".condition-customform" "css_element"
    ## Check form validation
    And I follow "Continue"
    And I should see "The URL is not valid or does not start with http or https." in the ".condition-customform" "css_element"
    And I should see "The email address is invalid." in the ".condition-customform" "css_element"
    And I should see "Must not be empty." in the ".condition-customform" "css_element"
    And I set the field "customform_url_2" to "https://test.com"
    And I set the field "customform_mail_3" to "test@test.com"
    And I set the field "customform_shorttext_4" to "TestText"
    And I set the field "customform_advcheckbox_5" to "1"
    And I set the field "customform_select_6" to "B"
    And I follow "Continue"
    And I should see "You have successfully booked Customform - Copy" in the ".condition-confirmation" "css_element"
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
    And I should see "TestText" in the "#mod_booking_all_users_sort_new_r0_c11" "css_element"
    And I should see "1" in the "#mod_booking_all_users_sort_new_r0_c12" "css_element"
    And I should see "B" in the "#mod_booking_all_users_sort_new_r0_c13" "css_element"
