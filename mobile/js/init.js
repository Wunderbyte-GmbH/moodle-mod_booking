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

/**
 * Offline provider.
 */

var BOOKING_TABLE = 'addon_mod_booking_responses';

// Define the database tables.
var siteSchema = {
    name: 'AddonModBookingOfflineProvider',
    version: 1,
    onlyCurrentSite: true,
    tables: [
        {
            name: BOOKING_TABLE,
            columns: [
                {
                    name: 'id',
                    type: 'INTEGER',
                    primaryKey: true
                },
                {
                    name: 'optionid',
                    type: 'INTEGER'
                },
                {
                    name: 'cmid',
                    type: 'INTEGER'
                },
                {
                    name: 'userid',
                    type: 'INTEGER'
                }
            ]
        }
    ]
};

/**
 * Class to handle offline presence submission.
 */
function AddonModBookingOfflineProvider() {
    // Register the schema so the tables are created.
    that.CoreSitesProvider.registerSiteSchema(siteSchema);
}

/**
 * Delete a response stored in DB.
 *
 * @param cmid Course module ID.
 * @param optionid Option ID.
 * @param userid User ID.
 * @param siteId Site ID. If not defined, current site.
 * @return Promise resolved if deleted, rejected if failure.
 */
AddonModBookingOfflineProvider.prototype.deleteResponse = function(cmid, optionid, userid, siteId) {
    return that.CoreSitesProvider.getSite(siteId).then(function(site) {
        return site.getDb().deleteRecords(BOOKING_TABLE, {cmid: cmid, optionid: optionid, userid: userid});
    });
};

/**
 * Get all offline responses.
 *
 * @param siteId Site ID. If not defined, current site.
 * @return Promise resolved with responses.
 */
AddonModBookingOfflineProvider.prototype.getResponses = function(siteId) {
    return that.CoreSitesProvider.getSite(siteId).then(function(site) {
        return site.getDb().getRecords(BOOKING_TABLE).then(function(records) {
            return records;
        });
    });
};

/**
 * Store a response.
 *
 * @param cmid Course module ID.
 * @param optionid Option ID.
 * @param userid User ID.
 * @param siteId Site ID. If not defined, current site.
 * @return Promise resolved when data is successfully stored.
 */
AddonModBookingOfflineProvider.prototype.saveResponses = function(cmid, optionid, userid, siteId) {
    return that.CoreSitesProvider.getSite(siteId).then(function(site) {
        var entry = {
            userid: userid,
            optionid: optionid,
            cmid: cmid
        };

        return site.getDb().insertRecord(BOOKING_TABLE, entry);
    });
};

var choiceBookingOffline = new AddonModBookingOfflineProvider();

/**
 * Response provider.
 */

/**
 * Class to handle responses.
 */
function AddonModBookingProvider() { }

AddonModBookingProvider.prototype.submitOfflineResponses = function(allowOffline, siteId) {
    siteId = siteId || that.CoreSitesProvider.getCurrentSiteId();
    var self = this;

    return choiceBookingOffline.getResponses(siteId).then(function(responses) {
        responses.map(function(response) {
            self.submitResponses(response.cmid, response.optionid, response.userid, allowOffline, siteId);
        });
    });
};

/**
 * Send the response.
 *
 * @param cmid Course module ID.
 * @param optionid Option ID.
 * @param userid User ID.
 * @param allowOffline Whether to allow storing the data in offline.
 * @param siteId Site ID. If not defined, current site.
 * @return Promise resolved with data: string if responses sent to server, false if stored in offline. Rejected if failure.
 */
AddonModBookingProvider.prototype.submitResponses = function(cmid, optionid, userid, allowOffline, siteId) {
    siteId = siteId || that.CoreSitesProvider.getCurrentSiteId();

    var self = this;

    // Convenience function to store the delete to be synchronized later.
    var storeOffline = function() {
        return choiceBookingOffline.saveResponses(cmid, optionid, userid, siteId).then(function() {
            return false;
        });
    };

    if (!that.CoreAppProvider.isOnline() && allowOffline) {
        // App is offline, store the action.
        return storeOffline();
    }

    // If there's already some data to be sent to the server, discard it first.
    return choiceBookingOffline.deleteResponse(cmid, optionid, userid, siteId).catch(function() {
        // Nothing was stored already.
    }).then(function() {
        // Now try to submit responses to the server.
        return self.submitResponsesOnline(cmid, optionid, userid, siteId).then(function(text) {
            return text;
        }).catch(function(error) {
            if (!allowOffline || that.CoreUtilsProvider.isWebServiceError(error)) {
                // The WebService has thrown an error, this means that responses cannot be submitted.
                return Promise.reject(error);
            }

            // Couldn't connect to server, store in offline.
            return storeOffline();
        });
    });
};

/**
 * Send responses. It will fail if offline or cannot connect.
 *
 * @param cmid Course module ID.
 * @param optionid Option ID.
 * @param userid User ID.
 * @param siteId Site ID. If not defined, current site.
 * @return Promise resolved if deleted, rejected if failure.
 */
AddonModBookingProvider.prototype.submitResponsesOnline = function(cmid, optionid, userid, siteId) {
    return that.CoreSitesProvider.getSite(siteId).then(function(site) {
        var params = {
            cmid: cmid,
            optionid: optionid,
            userid: userid
        };

        return site.write('mod_booking_confirm_user', params).then(function(response) {
            return response.message;
        });
    });
};

var result = {
    addonModBookingProvider: new AddonModBookingProvider()
};

result;