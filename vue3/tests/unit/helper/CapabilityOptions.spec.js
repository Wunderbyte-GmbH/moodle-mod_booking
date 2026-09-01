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
 * Unit tests for the CapabilityOptions Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { createStore } from 'vuex';
import { render, screen, fireEvent } from '@testing-library/vue';
import CapabilityOptions from '../../../components/helper/CapabilityOptions.vue';
import '@testing-library/jest-dom';

describe('CapabilityButtons', () => {
  const selectedCapability = {
    id: 1,
    capability: 'YourCapability',
    json: '[{"id":1,"name":"Option 1","checked":0,"incompatible":[]},{"id":2,"name":"Option 2","checked":0,"incompatible":[1]},{"id":3,"name":"Option 3","checked":0,"incompatible":[1]}]'
  };

  const configCapability = [
    { id: 1, capability: 'Capability 1' },
    { id: 2, capability: 'Capability 2' },
  ];

  const store = createStore({
    state: {
      configlist: configCapability,
    },
  });

  it('renders the capability list when showButtons is true', async () => {
    await render(CapabilityOptions, {
      props: {
        selectedcapability: selectedCapability,
      },
      global: {
        plugins: [store],
      },
    });

    // Check if the capability list is rendered when showButtons is true
    expect(screen.getByText('Capability Configuration:')).toBeInTheDocument();
    expect(screen.getByText('Option 1')).toBeInTheDocument();
    expect(screen.getByText('Option 2')).toBeInTheDocument();
  });

  it('handles checkbox change correctly', async () => {
    await render(CapabilityOptions, {
      props: {
        selectedcapability: selectedCapability,
      },
      global: {
        plugins: [store],
      },
    });

    const checkbox1 = screen.getByLabelText('Option 1');
    const checkbox2 = screen.getByLabelText('Option 2');
    // Toggle checkbox
    await fireEvent.click(checkbox1);
    // Check if checkbox is unchecked
    expect(checkbox1.checked).toBe(true);
    expect(checkbox2).toBeDisabled();

    await fireEvent.click(checkbox1);
    // Check if checkbox is unchecked
    expect(checkbox1.checked).toBe(false);
    expect(checkbox2).not.toBeDisabled();
  });

  it('handles checkbox change correctly', async () => {
    await render(CapabilityOptions, {
      props: {
        selectedcapability: selectedCapability,
      },
      global: {
        plugins: [store],
      },
    });

    const option1 = screen.getByText('Option 1');
    const option2 = screen.getByText('Option 3');

    const dragStartEvent = new Event('dragstart', {
      bubbles: true,
      cancelable: true,
    });

    dragStartEvent.dataTransfer = {
      setData: jest.fn(),
      effectAllowed: 'move',
    };
  
    console.log('List before drop:', screen.getAllByRole('listitem').map(item => item.textContent));
    // Trigger the dragstart event on the draggable element
    fireEvent(option1, dragStartEvent);
    fireEvent.dragOver(option2);

    const dropEvent = new Event('drop', {
      bubbles: true,
      cancelable: true,
    });
    // Attach necessary properties to the dataTransfer object
    dropEvent.dataTransfer = {
      getData: jest.fn().mockReturnValue('0'), // Assuming the index of the dragged item is '0'
    };

    await fireEvent(option2, dropEvent);
    const listItems = screen.getAllByRole('listitem');
    expect(listItems[1]).toHaveTextContent('Option 3');
    expect(listItems[2]).toHaveTextContent('Option 1');
  });
}); 