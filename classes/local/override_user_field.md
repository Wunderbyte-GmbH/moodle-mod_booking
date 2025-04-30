# Functionality Override user field / circumvent user field restriction

This class allows you to circumvent a restriction that some booking options are only bookable for users with certain values in certain profile fields (standard or custom).

To use this functionality the functionality must be enabled in the settings of the booking instance.
An optional param can be set there.
To trigger the user field to be overriden, use optional params for the optionview.php
cvpwd=password (as defined in the booking instance) and cvfield=fieldshortname_value the value will then be stored in the user preferences for the corresponding fieldshortname.

Persons with capability "updatebooking" can also set this preference for other users, when adding the userid param to the optionview url.

This diagram shows the relationships and structure of the `override_user_field` class, which interacts with `booking` and `singleton_service` in the Moodle module.

```mermaid
classDiagram
    class override_user_field {
        <<class>>
        -string $key
        -string $value
        -string $password
        +bool set_userprefs(string $param, int $userid = 0)
        +bool password_is_valid(int $cmid, string $pwd = "")
    }

    class booking {
        <<static>>
        +static get_value_of_json_by_key(int $bookingid, string $key) JSON
    }

    class singleton_service {
        <<static>>
        +static get_instance_of_booking_by_cmid(int $cmid) booking
    }

    override_user_field ..> booking : uses
    override_user_field ..> singleton_service : uses