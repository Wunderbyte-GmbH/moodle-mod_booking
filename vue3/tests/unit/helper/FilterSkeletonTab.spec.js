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