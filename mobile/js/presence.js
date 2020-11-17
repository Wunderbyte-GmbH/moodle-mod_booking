// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

var that = this;
var allowOffline = this.CoreConfigConstants.versioncode > 3800; // In 3.8.0 and older plugins couldn't add DB schemas.

/**
 * Send responses to the site.
 */
this.callDone = function () {
    var promise;

    that.CoreUtilsProvider.scanQR().then(function (text) {
        if (typeof text !== 'undefined' && Number.isInteger(parseInt(text))) {
            promise = Promise.resolve();

            promise.then(function () {
                // Submit the responses now.
                var modal = that.CoreDomUtilsProvider.showModalLoading('core.sending', true);
                that.addonModBookingProvider.submitResponses(that.CONTENT_OTHERDATA.cmid, that.CONTENT_OTHERDATA.optionid, text,
                    allowOffline).then(function (online) {
                        if (online === false) {
                            that.CoreDomUtilsProvider.showToast(that.TranslateService.instant('plugin.mod_booking.offlinesyncedlater'));
                        } else {
                            that.CoreDomUtilsProvider.showToast(online);
                        }
                    }).catch((message) => {
                        that.CoreDomUtilsProvider.showErrorModalDefault(message, 'Error submitting responses.', true);
                    }).finally(() => {
                        modal.dismiss();
                    });
            }).catch(() => {
                // User cancelled, ignore.
                that.CoreDomUtilsProvider.showErrorModalDefault(message, 'Error submitting responses.', true);
            });
        } else {
            that.CoreDomUtilsProvider.showToast(that.TranslateService.instant('plugin.mod_booking.wrongqrcode'));
        }
    });
};


this.moduleName = this.TranslateService.instant('plugin.mod_booking.modulename');
this.isOnline = this.CoreAppProvider.isOnline();

// Refresh online status when changes.
var onlineObserver = this.Network.onchange().subscribe(function () {
    that.isOnline = that.CoreAppProvider.isOnline();
    if (that.CoreAppProvider.isOnline() === true) {
        that.addonModBookingProvider.submitOfflineResponses(allowOffline);
    }
});

var syncObserver;

/**
 * Component being destroyed.
 */
this.ngOnDestroy = function () {
    onlineObserver && onlineObserver.unsubscribe();
};