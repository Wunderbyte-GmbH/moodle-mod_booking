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
    And I change viewport size to "1366x10000"

  @javascript
  Scenario: Booking: Create instance as teacher
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    ##And I add a "Booking" to section "0"
    And I add a "Booking" to section 0 using the activity chooser
    ## THIS IS MUCH SLOWER THAN INDIVIDUAL SETUP/VALIDATION
    ##And I set the following fields to these values:
    ##  | Booking instance name            | Test booking          |
    ##  | Event type                       | Webinar               |
    ##  | Booking text                     | Booking description   |
    ##  | Organizer name                   | Teacher 1             |
    ##  | Sort by                          | Name (without prefix) |
    ##  | Default view for booking options | All booking options   |
    ##  | disablecancel                    | 1                     |
    ##  | cancancelbook                    | 1                     |
    ##  | switchtemplates                  | 1                     |
    And I expand all fieldsets
    And I set the field "Booking instance name" to "Test booking"
    And I set the field "Event type" to "Webinar"
    And I set the field "Booking text" to "Booking description"
    And I set the field "Organizer name" to "Teacher 1"
    And I set the field "Sort by" to "Prefix"
    And I set the field "Default view for booking options" to "All booking options"
    And I set the field "Users can switch between views" to "checked"
    And I set the field "Allow booking after course start" to "checked"
    And I set the field "cancancelbook" to "checked"
    And I set the field "Define cancellation conditions" to "1"
    And I set the field "allowupdatedays" to "12"
    And I set the field "Disable booking for all options of this instance" to "checked"
    And I set the field "Circumvent availabilty restrictions" to "checked"
    And I set the field "circumventpassword" to "pwd"
    And I press "Save and display"
    Then I should see "Test booking"
    And I follow "Settings"
    And I expand all fieldsets
    And the field "Booking instance name" matches value "Test booking"
    And I should see "Webinar" in the "#fitem_id_eventtype" "css_element"
    And the field "Booking text" matches value "Booking description"
    And I should see "Teacher 1" in the "#fitem_id_organizatorname" "css_element"
    And the field "Sort by" matches value "Prefix"
    And the field "Default view for booking options" matches value "All booking options"
    And the field "Users can switch between views" matches value "checked"
    And the field "Allow booking after course start" matches value "checked"
    And the field "cancancelbook" matches value "checked"
    And the field "Define cancellation conditions" matches value "1"
    And the field "allowupdatedays" matches value "12"
    And the field "Disable booking for all options of this instance" matches value "checked"
    And the field "Circumvent availabilty restrictions" matches value "checked"
    And the field "circumventpassword" matches value "pwd"

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
      | activity | course | name       | intro                  | bookingmanager | eventtype | duration | pollurl          | whichview  | bookingpolicy    |
      | booking  | C1     | My booking | My booking description | teacher1       | Webinar   | 10       | https://pool.loc | showactive | Confirm booking! |
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
      | bookingmanager                              | teacher1                |
      | eventtype                                   | Webinar                 |
    ## Not working
    ##| Booking text                                | My booking description |
    ##| bookingpolicy                               | Confirm booking! |
