M.mod_booking = {};

M.mod_booking.init = function (Y) {



    Y.on('click', function (e) {
        var checkbox = e.target;

        if (checkbox.get('checked')) {
            Y.all('input.usercheckbox').each(function () {
                this.set('checked', 'checked');
            });
        } else {
            Y.all('input.usercheckbox').each(function () {
                this.set('checked', '');
            });
        }
    }, '#usercheckboxall');

    Y.on('click', function (e) {
        Y.all('input.usercheckbox').each(function () {
            this.set('checked', 'checked');
        });
    }, '#checkall');

    Y.on('click', function (e) {
        Y.all('input.usercheckbox').each(function () {
            this.set('checked', '');
        });
    }, '#checknone');

    Y.on('click', function (e) {
        Y.all('input.usercheckbox').each(function () {
            if (this.get('value') == 0) {
                this.set('checked', 'checked');
            }
        });
    }, '#checknos');
};
