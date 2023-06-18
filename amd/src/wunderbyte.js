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

/* eslint-disable no-empty-function */

/**
 * WunderByte javascript library/framework
 *
 * @module mod_booking/wunderbyte
 * @copyright 2023 Kamil Hurajt <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Sorting framework.
 */
export function WunderByteJS() {
}

WunderByteJS.prototype.sortable = function(opt) {
    let sortableItems = [];
    let sortableContainer = null;
    let draggingItem = null;

    let options = {
        items: '.wb-item-sortable',
        container: '.wb-item-container'
    };

    options = {...options, ...opt};

    // eslint-disable-next-line no-console
    console.log(options);

    this.init = function() {
        sortableItems = document.querySelectorAll(options.items);
        sortableContainer = document.querySelector(options.container);
        sortableItems.forEach(item => {
            item.setAttribute('draggable', true);
        });
        if (sortableContainer) {
            sortableContainer.addEventListener('dragstart', this.sortable.bind(this));
        }
    };

    this.sortable = function(event) {
        draggingItem = event.target.closest('.list-group-item');

        // Limiting the movement type
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('Text', draggingItem.textContent);

        // Subscribing to the events
        sortableContainer.addEventListener('dragover', this.dragOver.bind(this), false);
        sortableContainer.addEventListener('dragend', this.dragEnd.bind(this), false);

        setTimeout(function() {
            draggingItem.classList.add('ghost');
        }, 0);
    };

    this.getVerticalCenter = function(element) {
        let r = element.getBoundingClientRect();
        return (r.bottom - r.top) / 2;
    };

    this.getMouseOffset = function(event) {
        const element = event.target.closest('.list-group-item');
        let r = element.getBoundingClientRect();
        return {
            x: event.pageX - r.left,
            y: event.pageY - r.top
        };
    };

    this.dragOver = function(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        var target = event.target.closest('.list-group-item');

        if (target && target !== draggingItem && target.nodeName == 'LI') {
            // Sorting

            const offset = this.getMouseOffset(event);
            const middleY = this.getVerticalCenter(target);

            if (offset.y > middleY && target.nextSibling) {
                sortableContainer.insertBefore(draggingItem, target.nextSibling);
            } else if (target && target.className.indexOf(options.item) !== -1) {
                sortableContainer.insertBefore(draggingItem, target);
            }
        }
    };

    this.dragEnd = function(event) {
        event.preventDefault();
        if (draggingItem) {
            draggingItem.classList.remove('ghost');
            sortableContainer.removeEventListener('dragover', this.dragOver, false);
            sortableContainer.removeEventListener('dragend', this.dragEnd, false);

            draggingItem = null;
        }
    };

    this.init();
};

WunderByteJS.prototype.dragable = function(opt) {
    let options = {
        container: '.wb-droppable',
        items: '.wb-item-draggable'
    };

    let draggingItem = null;

    options = {...options, ...opt};

    this.init = function() {
        var items = document.querySelectorAll(options.items);

        // Make items draggable
        items.forEach(item => {
            item.setAttribute('draggable', true);
            item.addEventListener('dragstart', this.dragStart.bind(this));
            item.addEventListener('dragend', this.dragEnd.bind(this));
        });

        var containers = document.querySelectorAll(options.container);

        // Make containers listen to drop events
        containers.forEach(container => {
            container.classList.add('wb-droppable');

            container.addEventListener('dragenter', this.dragEnter.bind(this));
            container.addEventListener('dragover', this.dragOver.bind(this));
            container.addEventListener('drop', this.dragDrop.bind(this));
        });
    };

    this.dragStart = function() {
        setTimeout(() => this.classList.add('hidden'), 0);
        draggingItem = this;
    };

    this.dragEnd = function() {
        this.classList.remove('hidden');
        draggingItem = null;
    };

    this.dragDrop = function() {
        this.append(draggingItem);
    };

    this.dragEnter = function(e) {
        e.preventDefault();
    };

    this.dragOver = function(e) {
        e.preventDefault();
    };

    return this.init();
};
