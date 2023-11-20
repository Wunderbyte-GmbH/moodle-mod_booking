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
/**
 * jQuery Bar Rating Plugin v1.2.2
 *
 * http://github.com/antennaio/jquery-bar-rating
 *
 * Copyright (c) 2012-2016 Kazik Pietruszewski
 *
 * This plugin is available under the MIT license.
 * http://www.opensource.org/licenses/mit-license.php
 *
 * @param {any} factory factory
 */
(function(factory) {
    if (typeof define === 'function' && define.amd) {
        // AMD
        define(['jquery'], factory);
    }
}(function($) {

    var BarRating = (function() {

        /**
         * BarRating function.
         */
        function BarRating() {
            var self = this;

            // Wrap element in a wrapper div
            var wrapElement = function() {
                var classes = ['br-wrapper'];

                if (self.options.theme !== '') {
                    classes.push('br-theme-' + self.options.theme);
                }

                self.$elem.wrap($('<div />', {
                    'class': classes.join(' ')
                }));
            };

            // Unwrap element
            var unwrapElement = function() {
                self.$elem.unwrap();
            };

            // Find option by value
            var findOption = function(value) {
                if ($.isNumeric(value)) {
                    value = Math.floor(value);
                }

                return $('option[value="' + value + '"]', self.$elem);
            };

            // Get initial option
            var getInitialOption = function() {
                var initialRating = self.options.initialRating;

                if (!initialRating) {
                    return $('option:selected', self.$elem);
                }

                return findOption(initialRating);
            };

            // Get empty option
            var getEmptyOption = function() {
                var $emptyOpt = self.$elem.find('option[value="' + self.options.emptyValue + '"]');

                if (!$emptyOpt.length && self.options.allowEmpty) {
                    $emptyOpt = $('<option />', {'value': self.options.emptyValue});

                    return $emptyOpt.prependTo(self.$elem);
                }

                return $emptyOpt;
            };

            // Get data
            var getData = function(key) {
                var data = self.$elem.data('barrating');

                if (typeof key !== 'undefined') {
                    return data[key];
                }

                return data;
            };

            // Set data
            var setData = function(key, value) {
                if (value !== null && typeof value === 'object') {
                    self.$elem.data('barrating', value);
                } else {
                    self.$elem.data('barrating')[key] = value;
                }
            };

            // Save data on element
            var saveDataOnElement = function() {
                var $opt = getInitialOption();
                var $emptyOpt = getEmptyOption();

                var value = $opt.val();
                var text = $opt.data('html') ? $opt.data('html') : $opt.text();

                // If the allowEmpty option is not set let's check if empty option exists in the select field
                var allowEmpty = (self.options.allowEmpty !== null) ? self.options.allowEmpty : !!$emptyOpt.length;

                var emptyValue = ($emptyOpt.length) ? $emptyOpt.val() : null;
                var emptyText = ($emptyOpt.length) ? $emptyOpt.text() : null;

                setData(null, {
                    userOptions: self.options,

                    // Initial rating based on the OPTION value
                    ratingValue: value,
                    ratingText: text,

                    // Rating will be restored by calling clear method
                    originalRatingValue: value,
                    originalRatingText: text,

                    // Allow empty ratings?
                    allowEmpty: allowEmpty,

                    // Rating value and text of the empty OPTION
                    emptyRatingValue: emptyValue,
                    emptyRatingText: emptyText,

                    // Read-only state
                    readOnly: self.options.readonly,

                    // Did the user already select a rating?
                    ratingMade: false
                });
            };

            // Remove data on element
            var removeDataOnElement = function() {
                self.$elem.removeData('barrating');
            };

            // Return current rating text
            var ratingText = function() {
                return getData('ratingText');
            };

            // Return current rating value
            var ratingValue = function() {
                return getData('ratingValue');
            };

            // Build widget and return jQuery element
            var buildWidget = function() {
                var $w = $('<div />', {'class': 'br-widget'});

                // Create A elements that will replace OPTIONs
                self.$elem.find('option').each(function() {
                    var val, text, html, $a;

                    val = $(this).val();

                    // Create ratings - but only if val is not defined as empty
                    if (val !== getData('emptyRatingValue')) {
                        text = $(this).text();
                        html = $(this).data('html');
                        if (html) {
 text = html;
}

                        $a = $('<a />', {
                            'href': '#',
                            'data-rating-value': val,
                            'data-rating-text': text,
                            'html': (self.options.showValues) ? text : ''
                        });

                        $w.append($a);
                    }

                });

                // Append .br-current-rating div to the widget
                if (self.options.showSelectedRating) {
                    $w.append($('<div />', {'text': '', 'class': 'br-current-rating'}));
                }

                // Additional classes for the widget
                if (self.options.reverse) {
                    $w.addClass('br-reverse');
                }

                if (self.options.readonly) {
                    $w.addClass('br-readonly');
                }

                return $w;
            };

            // Return a jQuery function name depending on the 'reverse' setting
            var nextAllorPreviousAll = function() {
                if (getData('userOptions').reverse) {
                    return 'nextAll';
                } else {
                    return 'prevAll';
                }
            };

            // Set the value of the select field
            var setSelectFieldValue = function(value) {
                // Change selected option
                findOption(value).prop('selected', true);

                if (getData('userOptions').triggerChange) {
                    self.$elem.change();
                }
            };

            // Reset select field
            var resetSelectField = function() {
                $('option', self.$elem).prop('selected', function() {
                    return this.defaultSelected;
                });

                if (getData('userOptions').triggerChange) {
                    self.$elem.change();
                }
            };

            // Display the currently selected rating
            var showSelectedRating = function(text) {
                // Text undefined?
                text = text ? text : ratingText();

                // Special case when the selected rating is defined as empty
                if (text == getData('emptyRatingText')) {
                    text = '';
                }

                // Update .br-current-rating div
                if (self.options.showSelectedRating) {
                    self.$elem.parent().find('.br-current-rating').text(text);
                }
            };

            // Return rounded fraction of a value (14.4 -> 40, 0.99 -> 90)
            var fraction = function(value) {
                return Math.round(((Math.floor(value * 10) / 10) % 1) * 100);
            };

            // Remove all classes from elements
            var resetStyle = function() {
                // Remove all classes starting with br-*
                self.$widget.find('a').removeClass(function(index, classes) {
                    return (classes.match(/(^|\s)br-\S+/g) || []).join(' ');
                });
            };

            // Apply style by setting classes on elements
            var applyStyle = function() {
                var $a = self.$widget.find('a[data-rating-value="' + ratingValue() + '"]');
                var initialRating = getData('userOptions').initialRating;
                var baseValue = $.isNumeric(ratingValue()) ? ratingValue() : 0;
                var f = fraction(initialRating);
                var $all, $fractional;

                resetStyle();

                // Add classes
                $a.addClass('br-selected br-current')[nextAllorPreviousAll()]()
                    .addClass('br-selected');

                if (!getData('ratingMade') && $.isNumeric(initialRating)) {
                    if ((initialRating <= baseValue) || !f) {
                        return;
                    }

                    $all = self.$widget.find('a');

                    if ($a.length) {
                        $fractional = $a[(getData('userOptions').reverse) ? 'prev' : 'next']();
                    } else {
                        $fractional = $all[(getData('userOptions').reverse) ? 'last' : 'first']();
                    }

                    $fractional.addClass('br-fractional');
                    $fractional.addClass('br-fractional-' + f);
                }
            };

            // Check if the element is deselectable?
            var isDeselectable = function($element) {
                if (!getData('allowEmpty') || !getData('userOptions').deselectable) {
                    return false;
                }

                return (ratingValue() == $element.attr('data-rating-value'));
            };

            // Handle click events
            var attachClickHandler = function($elements) {
                $elements.on('click.barrating', function(event) {
                    var $a = $(this),
                        options = getData('userOptions'),
                        value,
                        text;

                    event.preventDefault();

                    value = $a.attr('data-rating-value');
                    text = $a.attr('data-rating-text');

                    // Is current and deselectable?
                    if (isDeselectable($a)) {
                        value = getData('emptyRatingValue');
                        text = getData('emptyRatingText');
                    }

                    // Remember selected rating
                    setData('ratingValue', value);
                    setData('ratingText', text);
                    setData('ratingMade', true);

                    setSelectFieldValue(value);
                    showSelectedRating(text);

                    applyStyle();

                    // OnSelect callback
                    options.onSelect.call(
                        self,
                        ratingValue(),
                        ratingText(),
                        event
                    );

                    return false;
                });
            };

            // Handle mouseenter events
            var attachMouseEnterHandler = function($elements) {
                $elements.on('mouseenter.barrating', function() {
                    if (!self.options.initialRating) {
                        var $a = $(this);
                        resetStyle();
                        $a.addClass('br-active')[nextAllorPreviousAll()]()
                            .addClass('br-active');
                        showSelectedRating($a.attr('data-rating-text'));
                    }
                });
            };

            // Handle mouseleave events
            var attachMouseLeaveHandler = function() {
                self.$widget.on('mouseleave.barrating blur.barrating', function() {
                    showSelectedRating();
                    applyStyle();
                });
            };

            // Somewhat primitive way to remove 300ms click delay on touch devices
            // for a more advanced solution consider setting `fastClicks` option to false
            // and using a library such as fastclick (https://github.com/ftlabs/fastclick)
            var fastClicks = function($elements) {
                $elements.on('touchstart.barrating', function(event) {
                    event.preventDefault();
                    event.stopPropagation();

                    $(this).click();
                });
            };

            // Disable clicks
            var disableClicks = function($elements) {
                $elements.on('click.barrating', function(event) {
                    event.preventDefault();
                });
            };

            var attachHandlers = function($elements) {
                // Attach click event handler
                attachClickHandler($elements);

                if (self.options.hoverState) {
                    // Attach mouseenter event handler
                    attachMouseEnterHandler($elements);

                    // Attach mouseleave event handler
                    attachMouseLeaveHandler($elements);
                }
            };

            var detachHandlers = function($elements) {
                // Remove event handlers in the ".barrating" namespace
                $elements.off('.barrating');
            };

            var setupHandlers = function(readonly) {
                var $elements = self.$widget.find('a');

                if (getData('userOptions').fastClicks) {
                    fastClicks($elements);
                }

                if (readonly) {
                    detachHandlers($elements);
                    disableClicks($elements);
                } else {
                    attachHandlers($elements);
                }
            };

            this.show = function() {
                // Run only once
                if (getData()) {
                    return;
                }

                // Wrap element
                wrapElement();

                // Save data
                saveDataOnElement();

                // Build & append widget to the DOM
                self.$widget = buildWidget();
                self.$widget.insertAfter(self.$elem);

                applyStyle();

                showSelectedRating();

                setupHandlers(self.options.readonly);

                // Hide the select field
                self.$elem.hide();
            };

            this.readonly = function(state) {
                if (typeof state !== 'boolean' || getData('readOnly') == state) {
                    return;
                }

                setupHandlers(state);
                setData('readOnly', state);
                self.$widget.toggleClass('br-readonly');
            };

            this.set = function(value) {
                var options = getData('userOptions');

                if (self.$elem.find('option[value="' + value + '"]').length === 0) {
                    return;
                }

                // Set data
                setData('ratingValue', value);
                setData('ratingText', self.$elem.find('option[value="' + value + '"]').text());
                setData('ratingMade', true);

                setSelectFieldValue(ratingValue());
                showSelectedRating(ratingText());

                applyStyle();

                // OnSelect callback
                if (!options.silent) {
                    options.onSelect.call(
                        this,
                        ratingValue(),
                        ratingText()
                    );
                }
            };

            this.clear = function() {
                var options = getData('userOptions');

                // Restore original data
                setData('ratingValue', getData('originalRatingValue'));
                setData('ratingText', getData('originalRatingText'));
                setData('ratingMade', false);

                resetSelectField();
                showSelectedRating(ratingText());

                applyStyle();

                // OnClear callback
                options.onClear.call(
                    this,
                    ratingValue(),
                    ratingText()
                );
            };

            this.destroy = function() {
                var value = ratingValue();
                var text = ratingText();
                var options = getData('userOptions');

                // Detach handlers
                detachHandlers(self.$widget.find('a'));

                // Remove widget
                self.$widget.remove();

                // Remove data
                removeDataOnElement();

                // Unwrap the element
                unwrapElement();

                // Show the element
                self.$elem.show();

                // OnDestroy callback
                options.onDestroy.call(
                    this,
                    value,
                    text
                );
            };
        }

        BarRating.prototype.init = function(options, elem) {
            this.$elem = $(elem);
            this.options = $.extend({}, $.fn.barrating.defaults, options);

            return this.options;
        };

        return BarRating;
    })();

    $.fn.barrating = function(method, options) {
        // eslint-disable-next-line consistent-return
        return this.each(function() {
            var plugin = new BarRating();

            // Plugin works with select fields
            if (!$(this).is('select')) {
                $.error('Sorry, this plugin only works with select fields.');
            }

            // Method supplied
            if (plugin.hasOwnProperty(method)) {
                plugin.init(options, this);
                if (method === 'show') {
                    return plugin.show(options);
                } else {
                    // Plugin exists?
                    if (plugin.$elem.data('barrating')) {
                        plugin.$widget = $(this).next('.br-widget');
                        return plugin[method](options);
                    }
                }

                // No method supplied or only options supplied
            } else if (typeof method === 'object' || !method) {
                options = method;
                plugin.init(options, this);
                return plugin.show();
            } else {
                $.error('Method ' + method + ' does not exist on jQuery.barrating');
            }
        });
    };

    $.fn.barrating.defaults = {
        theme: '',
        initialRating: null, // Initial rating
        allowEmpty: null, // Allow empty ratings?
        emptyValue: '', // This is the expected value of the empty rating
        showValues: false, // Display rating values on the bars?
        showSelectedRating: true, // Append a div with a rating to the widget?
        deselectable: true, // Allow to deselect ratings?
        reverse: false, // Reverse the rating?
        readonly: false, // Make the rating ready-only?
        fastClicks: true, // Remove 300ms click delay on touch devices?
        hoverState: true, // Change state on hover?
        silent: false, // Supress callbacks when controlling ratings programatically
        triggerChange: true, // Trigger change event when ratings are set or reset
        onSelect: function(value, text, event) {
            value = text;
            value = event;
        }, // Callback fired when a rating is selected
        onClear: function(value, text) {
            value = text;
        }, // Callback fired when a rating is cleared
        onDestroy: function(value, text) {
            value = text;
        } // Callback fired when a widget is destroyed
    };

    $.fn.barrating.BarRating = BarRating;

}));
