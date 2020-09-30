var that = this;

this.callDone = function() {
    this.CoreUtilsProvider.scanQR().then((text) => {
        // The variable "text" contains the value of the QR code.
        that.CoreDomUtilsProvider.showToast(text);
//        alert(text);
    });
};