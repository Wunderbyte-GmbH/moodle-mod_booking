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
 * Unit tests for the SkeletonTab Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { mount } from '@vue/test-utils';
import SkeletonTab from '../../../components/helper/SkeletonTab.vue';

describe('SkeletonTab', () => {
  it('renders with a random width and correct classes', () => {
    const wrapper = mount(SkeletonTab);

    // Check if randomWidth has a valid value
    const randomWidth = wrapper.vm.randomWidth;
    expect(randomWidth).toMatch(/^\d+\.?\d*rem$/);

    // Check if the component has the correct classes
    expect(wrapper.classes()).toContain('skeleton-tab');
    expect(wrapper.find('.nav-link').classes()).toContain('loading-animation');

    // Check if the component has the correct inline style
    const skeletonTabElement = wrapper.find('.skeleton-tab');
    expect(skeletonTabElement.element.style.width).toBe(randomWidth);
  });
});