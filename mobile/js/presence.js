var that = this;

this.callDone = function() {
    alert("dela");
    this.CoreUtilsProvider.scanQR().then((text) => {
        // The variable "text" contains the value of the QR code.
        console.log(text);
    });
};