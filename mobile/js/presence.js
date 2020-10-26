var that = this;

this.callDone = function() {
    that.CoreUtilsProvider.scanQR().then(function(text) {
        if (typeof text !== 'undefined' && Number.isInteger(parseInt(text))) {

            return that.CoreSitesProvider.getSite().then(function(site) {

                var params = {
                    cmid: that.CONTENT_OTHERDATA.cmid,
                    optionid: that.CONTENT_OTHERDATA.optionid,
                    userid: text
                };

                return site.write('mod_booking_confirm_user', params).then(function(response) {
                    that.CoreDomUtilsProvider.showToast(response.message);
                });
            });
        } else {
            that.CoreDomUtilsProvider.showToast(that.TranslateService.instant('plugin.mod_booking.wrongqrcode'));
        }
    });
};