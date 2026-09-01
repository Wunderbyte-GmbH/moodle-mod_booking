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
 * Unit tests for the FilterSearchbar Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { render, screen, fireEvent } from '@testing-library/vue';
import Searchbar from '../../components/FilterSearchbar.vue';

describe('Searchbar', () => {
  it('filters tabs based on user input', async () => {
    const tabs = [
      { id: 1, name: 'Tab 1' },
      { id: 2, name: 'Tab 2' },
      { id: 3, name: 'Tab 3' },
    ];

    const { container, emitted } = render(Searchbar, {
      props: {
        tabs: tabs,
      },
    });

    // Check if input element exists
    const inputElement = screen.getByPlaceholderText('Filter tabs...');
    expect(inputElement).toBeTruthy();

    // Simulate user input
    await fireEvent.update(inputElement, 'Tab 1');

    // Check if the emitted event is correct
    expect(emitted()).toHaveProperty('filteredTabs');
    expect(emitted().filteredTabs[0][0]).toEqual([tabs[0]]);

    await fireEvent.update(inputElement, 'Testing');
    expect(emitted().filteredTabs[1][0]).toEqual([]);
  });
});