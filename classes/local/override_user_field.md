# Functionality Override user field / circumvent user field restriction

This class allows you to circumvent a restriction that some booking options are only bookable for users with certain values in certain profile fields (standard or custom).

To use this functionality, it must be enabled in the settings of the booking instance.
An optional password can be set there.
To trigger the user field to be overriden, use optional params for the optionview.php
cvpwd=password (if given as defined in the booking instance) and cvfield=fieldshortname_value the value will then be stored in the user preferences for the corresponding fieldshortname.
The cirumvention is specific for each booking instance (cmid). So if a user clicks on a link containing these params, she will be able to circumvent conditions with this userfield only for this specific booking instance. Once she clicks on the link with circumvent params from another booking instance, the circumvention applies only for this booking instance.

In booking instances with this setting activated, if a bookingoption contains an availability condition of a userfield, it is possible to copy a circumvent link into the clipboard. To do so, expand the cog wheel and click on "Copy access link for externals". Given you have the capability to edit the bookingoption.
Please note that this link is only generated for userfield availability conditions with operator "equals" or "contains".

Persons with capability "updatebooking" can also enable circumvent preference for other users, when adding the userid param to the optionview url.

This diagram shows the relationships and structure of the `override_user_field` class, which interacts with `booking` and `singleton_service` in the Moodle module:

```mermaid
classDiagram
    class override_user_field {
        <<Class>>
        - string password
        - int cmid
        + string key
        + string value

        + __construct(int cmid)
        + bool set_userprefs(string param, int userid=0)
        + bool password_is_valid(string pwd="")
        + string get_value_for_user(string profilefield, int userid)
        + string get_circumvent_link(int optionid)
    }

    class singleton_service {
        <<static>>
        + get_instance_of_booking_by_cmid(int cmid)
        + get_instance_of_booking_option_settings(int optionid)
    }

    class booking {
        <<static>>
        + get_value_of_json_by_key(int bookingid, string key)
    }

    class moodle_url {
        + __construct(string url, array params)
        + string out(bool rewrite)
    }

    override_user_field --> singleton_service : uses
    override_user_field --> booking : uses
    override_user_field --> moodle_url : creates