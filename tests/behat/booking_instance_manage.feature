@mod @mod_booking @booking_instance_manage
Feature: In a course add a booking instance and manage it
  As an administrator or a teacher
  I need to add booking instance and configure it

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
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And I clean booking cache

  @javascript
  Scenario: Booking: Create instance as teacher
    Given I log in as "teacher1"
    And I change viewport size to "1366x7000"
    And I am on "Course 1" course homepage with editing mode on
    ##And I add a "Booking" to section "0"
    And I add a "Booking" to section 0 using the activity chooser
    And I set the following fields to these values:
      | Booking instance name            | Test booking                                           |
      | Event type                       | Webinar                                                |
      | Booking text                     | This is the description for the test booking instance. |
      | Organizer name                   | Teacher 1                                              |
      | Sort by                          | Name (without prefix)                                  |
      | Default view for booking options | All booking options                                    |
    And I press "Save and return to course"
    Then I should see "Test booking"

  @javascript
  Scenario: Booking: Create instance template as admin verify and delete it
    Given the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | duration | pollurl          | whichview  | copymail | bookingpolicy    |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 10       | https://pool.loc | showactive | 1        | Confirm booking! |
    And I am on the "My booking" Activity page logged in as admin
    ## Create booking instance template
    And I click on "More" "link" in the ".secondary-navigation .dropdownmoremenu" "css_element"
    And I click on "Add booking instance to template" "link" in the "[data-key=\"nav_saveinstanceastemplate\"]" "css_element"
    And I set the field "Name" to "InstanceTemplate"
    And I press "Save changes"
    And I should see "This booking instance was successfully saved as template."
    When I visit "/mod/booking/instancetemplatessettings.php"
    Then I should see "InstanceTemplate" in the "#instancetemplatessettings_r0" "css_element"
    And I click on "Delete" "button" in the "#instancetemplatessettings_r0" "css_element"
    And I should see "Template was deleted!"
    And I should not see "InstanceTemplate"

  @javascript
  Scenario: Booking: Create instance template as teacher and apply it
    Given the following "activities" exist:
      | activity | course | name       | intro                  | bookingmanager | eventtype | duration | pollurl          | whichview  | copymail | bookingpolicy    |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 10       | https://pool.loc | showactive | 1        | Confirm booking! |
    And I change viewport size to "1366x10000"
    And I am on the "My booking" Activity page logged in as teacher1
    ## Create booking instance template
    And I click on "More" "link" in the ".secondary-navigation .dropdownmoremenu" "css_element"
    And I click on "Add booking instance to template" "link" in the "[data-key=\"nav_saveinstanceastemplate\"]" "css_element"
    And I set the field "Name" to "InstanceTemplate"
    And I press "Save changes"
    And I should see "This booking instance was successfully saved as template."
    ## Create a booking instance useing template
    And I am on "Course 1" course homepage with editing mode on
    ##And I add a "Booking" to section "0"
    And I add a "Booking" to section 0 using the activity chooser
    And I wait "1" seconds
    And I set the field "Populate from template" to "InstanceTemplate"
    And I wait "1" seconds
    ## Verify fields populated from template
    And the following fields match these values:
      | Booking instance name                       | My booking              |
      | Duration                                    | 10                      |
      | Poll url                                    | https://pool.loc        |
      | Default view for booking options            | Active booking options  |
    ## Not working
      ##| Send confirmation e-mail to booking manager | 1 |
      ##| Booking text                                | My booking description |
      ##| bookingpolicy                               | Confirm booking! |
    ## Defaults - untested
      ##| eventtype                                   | Webinar |
      ##| bookingmanager                              | teacher1 |
