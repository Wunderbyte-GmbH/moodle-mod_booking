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