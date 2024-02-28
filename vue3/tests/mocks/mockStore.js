// mockStore.js

import { createStore } from 'vuex';

const mockState = {
  view: 'default', // or any default state you want to set
  strings: {
    fromlearningtitel: 'Goal Title',
    goalnameplaceholder: 'Enter Goal Name',
    fromlearningdescription: 'Goal Description',
    goalsubjectplaceholder: 'Enter Goal Description',
  },
  learningpath: {
    name: 'Testing',
    description: 'Testing description',
  }
};

const store = createStore({
  state() {
    return mockState;
  },
});

export default store;