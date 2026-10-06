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
 * Benchmark Trend Chart helper module.
 *
 * @module     bookingextension_agent/benchmark_trend_chart
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = (containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    let done = false;
    const scrollToRight = () => {
        container.scrollLeft = container.scrollWidth;
        const canvas = container.querySelector('canvas');
        if (canvas && canvas.offsetWidth > 0) {
            setTimeout(() => {
                container.scrollLeft = container.scrollWidth;
            }, 50);
            done = true;
            if (observer) {
                observer.disconnect();
            }
            if (resizeObserver) {
                resizeObserver.disconnect();
            }
            if (interval) {
                clearInterval(interval);
            }
        }
    };

    // Setup MutationObserver.
    const observer = new MutationObserver(() => {
        if (!done) {
            scrollToRight();
        }
    });
    observer.observe(container, { childList: true, subtree: true });

    // Setup ResizeObserver.
    let resizeObserver = null;
    if (window.ResizeObserver) {
        resizeObserver = new ResizeObserver(() => {
            if (!done) {
                scrollToRight();
            }
        });
        resizeObserver.observe(container);
        if (container.firstElementChild) {
            resizeObserver.observe(container.firstElementChild);
        }
    }

    // Polling fallback.
    let count = 0;
    const interval = setInterval(() => {
        if (!done) {
            scrollToRight();
            count++;
            if (count > 30) {
                clearInterval(interval);
                if (observer) {
                    observer.disconnect();
                }
                if (resizeObserver) {
                    resizeObserver.disconnect();
                }
            }
        } else {
            clearInterval(interval);
        }
    }, 100);

    // Call immediately and on load.
    scrollToRight();
    window.addEventListener('load', scrollToRight);
    document.addEventListener('DOMContentLoaded', scrollToRight);
};
