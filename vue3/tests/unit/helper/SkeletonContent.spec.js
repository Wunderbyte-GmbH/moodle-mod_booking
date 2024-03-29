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