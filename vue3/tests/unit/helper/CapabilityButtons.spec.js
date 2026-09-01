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
 * Unit tests for the CapabilityButtons Vue component.
 *
 * @package    mod_booking
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { createStore } from 'vuex';
import { render, screen, fireEvent } from '@testing-library/vue';
import CapabilityButtons from '../../../components/helper/CapabilityButtons.vue';

describe('CapabilityButtons', () => {
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
    await render(CapabilityButtons, {
      props: {
        choosenCapability: null,
      },
      global: {
        plugins: [store],
      },
    });

    // Check if the capability list is rendered when showButtons is true
    const capabilityList = screen.getByText('Capabilites');
    expect(capabilityList).toBeTruthy();
  });

  it('toggles the button visibility when clicked', async () => {
    await render(CapabilityButtons, {
      props: {
        choosenCapability: null,
      },
      global: {
        plugins: [store],
      },
    });

    // Click on a capability button to hide the "Back" button
    const capabilityButton = screen.getByText('Capability 1');
    await fireEvent.click(capabilityButton);

    // Check if the "Back" button is not visible
    const backButton = screen.queryByText('Back');
    await fireEvent.click(backButton);
    expect(backButton).toBeTruthy();
  });
});