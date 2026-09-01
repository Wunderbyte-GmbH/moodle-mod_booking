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
 * Unit tests for the SkeletonContent Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { mount } from '@vue/test-utils';
import SkeletonContent from '../../../components/helper/SkeletonContent.vue';

describe('SkeletonContent', () => {
  it('renders with a random width and correct classes', () => {
    const wrapper = mount(SkeletonContent);

    // Get the loading animation spans
    const loadingAnimations = wrapper.findAll('.loading-animation');

    // Check if there is a loading animation for each data item
    expect(loadingAnimations.length).toBe(3); // Assuming there are three loading animations in the template

    // Check if each loading animation has a valid random width
    loadingAnimations.forEach((loadingAnimation) => {
      const width = loadingAnimation.element.style.width;
      expect(width).toMatch(/^\d+\.?\d*rem$/); // Matches a valid width value like '10rem' or '5.5rem'
    });
  });
});