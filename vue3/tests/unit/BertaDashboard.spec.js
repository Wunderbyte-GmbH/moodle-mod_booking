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
 * Unit tests for the BertaDashboard Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { render, screen, fireEvent } from '@testing-library/vue';
import BertaDashboard from '../../components/BertaDashboard.vue';
import { createStore } from 'vuex';
import '@testing-library/jest-dom';


const mockDispatch = jest.fn();

const store = createStore({
  state: {
      tabs: [
        { id: 1, name: 'Tab 1' },
        { id: 2, name: 'Tab 2' },
      ],
      content: {
        name: 'Sample Content',
        coursecount: 5,
        path: '/sample-path',
      },
      configlist: [
        { id: 1, capability:'Can read'},
        { id: 2, capability:'Can write'},
      ],
  },
  actions: {
    fetchTabs: jest.fn(),
    fetchParentContent: jest.fn(),
  },
  dispatch: mockDispatch,
});


describe('ComponentUnderTest', () => {
  it('renders correctly and shows tabs', async () => {
    const { container } = await render(BertaDashboard, {
      global: {
        plugins: [store],
      },
    });

    // Check if the component renders correctly
    await expect(screen.getByText('Tab 1')).toBeInTheDocument();
    expect(screen.getByText('Tab 2')).toBeInTheDocument();
  });

  it('changes tab when clicked', async () => {
    const { container } = await render(BertaDashboard, {
      global: {
        plugins: [store],
      },
    });

    // Click on the second tab
    await fireEvent.click(screen.getByText('Tab 2'));

    // Check if the second tab becomes active
    await expect(screen.getByText('Tab 2')).toHaveClass('active');
  });

  it('updates filtered tabs when search bar emits event', async () => {
    const { container } = await render(BertaDashboard, {
      global: {
        plugins: [store],
      },
    });

    // Simulate search bar emitting filteredTabs event with only one tab
    const inputElement = screen.getByPlaceholderText('Filter tabs...');
    await fireEvent.update(inputElement, 'Tab 1');

    // Check if the tab list updates accordingly
    expect(screen.queryByText('Tab 2')).not.toBeInTheDocument();
  });

  it('displays content based on selected tab', async () => {
    const { container } = await render(BertaDashboard, {
      global: {
        plugins: [store],
      },
    });

    // Click on the first tab
    await fireEvent.click(screen.getByText('Tab 1'));

    // Check if content related to Tab 1 is displayed
    expect(screen.getByText('Sample Content')).toBeInTheDocument();
    expect(screen.getByText('5')).toBeInTheDocument();
    expect(screen.getByText('/sample-path')).toBeInTheDocument();
  });

  it('handles capability clicked event', async () => {
    const { container } = await render(BertaDashboard, {
      global: {
        plugins: [store],
      },
    });

    // Simulate a capability being clicked
    const capabilityButton = screen.getByText('Can read');
    await fireEvent.click(capabilityButton);

    expect(screen.getByText('Capability: Can read')).toBeInTheDocument();
  });

});