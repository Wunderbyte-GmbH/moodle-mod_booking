<template>
  <span
    v-if="subfields && Object.keys(subfields).length > 0"
    class="subfields-wrapper"
  >
    <button @click="toggleSubfields(key)">
      <i
        :class="['fas', subfieldsVisible[key] ? 'fa-chevron-up' : 'fa-chevron-down']"
      />
    </button>
    <transition name="slide-fade">
      <ul v-show="subfieldsVisible[key]">
        <li
          v-for="(subvalue, subkey) in subfields"
          :key="subkey"
        >
          <span class="enumeration-dot" />
          <span>
            <input
              :id="'subcheckbox_' + subkey"
              type="checkbox"
              class="mr-2"
              :checked="subvalue.checked"
              @change="handleCheckboxChange(subvalue)"
            >
            <label :for="'subcheckbox_' + subkey">
              <strong>{{ subvalue.name }}</strong>
            </label>
          </span>
        </li>
      </ul>
    </transition>
  </span>
</template>

<script setup>
import { onMounted, ref } from 'vue';

  const props = defineProps({
    subfields: {
      type: Object,
      required: true,
    },
    subfieldsVisible: {
      type: Object,
      required: true,
    },
  });

  const emit = defineEmits([
    'handleCheckboxChange'
  ])


  const subfieldsVisibleObj = ref(null)

  onMounted(() => {
    subfieldsVisibleObj.value = props.subfieldsVisible
  })

  const toggleSubfields = (key) => {
    subfieldsVisibleObj.value[key] = !subfieldsVisibleObj.value[key];
  };

  const handleCheckboxChange = (subvalue) => {
    emit('handleCheckboxChange', subvalue)
  };
</script>

<style scoped >
.subfields-wrapper {
  margin-left: 20px; /* Adjust as needed */
}

.subfields-wrapper > button {
  margin-right: 5px;
  background: none;
  border: none;
  cursor: pointer;
}

.subfields-wrapper ul {
  margin-top: 5px;
  padding-left: 20px; /* Indent subfields */
}

.subfields-wrapper ul li {
  list-style-type: none; /* Remove default list style */
}

.enumeration-dot {
  display: inline-block;
  width: 5px;
  height: 5px;
  background-color: #000;
  border-radius: 50%;
  margin-right: 5px; /* Adjust as needed */
}

.slide-fade-enter-active, .slide-fade-leave-active {
  transition: all 0.5s ease;
}

.slide-fade-enter-from, .slide-fade-leave-to {
  opacity: 0;
  transform: translateY(-20px);
}

</style>