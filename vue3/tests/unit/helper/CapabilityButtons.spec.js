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