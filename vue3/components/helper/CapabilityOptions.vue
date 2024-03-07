<template>
  <div v-if="configurationList && configurationList.length > 0">
    <ul>
      <li
        v-for="(value, key) in configurationList"
        :key="key"
        draggable="true"
        style="cursor: move"
        :class="{ 'drag-over': key === draggedOverIndex }"
        @dragstart="handleDragStart(key, $event)"
        @dragover="handleDragOver(key, $event)"
        @dragleave="handleDragLeave(key, $event)"
        @drop="handleDrop(key, $event)"
        @dragend="handleDragEnd"
      >
        <span v-if="value.necessary">
          <input
            :id="'checkbox_' + key"
            type="checkbox"
            class="mr-2"
            disabled
            :checked="1"
          >
          <label :for="'checkbox_' + key">
            <strong>
              <div v-html="store.state.strings[value.classname]" />
            </strong>
          </label>
          <i> {{ store.state.strings.vue_capability_options_necessary }}</i>
        </span>
        <span v-else>
          <input
            :id="'checkbox_' + key"
            type="checkbox"
            class="mr-2"
            :checked="value.checked"
            :disabled="disableCheckbox(value)"
            @change="handleCheckboxChange(value)"
          >
          <label :for="'checkbox_' + key">
            <strong>
              <div v-html="store.state.strings[value.classname]" />
            </strong>
          </label>
        </span>
        <span class="blocked-message">{{ getBlockMessage(value) }}</span>

        <span v-if="value.subfields && Object.keys(value.subfields).length > 0" class="subfields-wrapper">
          <button @click="toggleSubfields(key)">
            <i :class="['fas', value.showSubfields ? 'fa-chevron-up' : 'fa-chevron-down']"></i>
          </button>
          <transition name="slide-fade">
            <ul v-show="value.showSubfields">
              <li v-for="(subvalue, subkey) in value.subfields" :key="subkey">
                <span class="enumeration-dot"></span>
                <span>
                  <input :id="'subcheckbox_' + subkey" type="checkbox" class="mr-2" :checked="subvalue.checked" @change="handleCheckboxChange(subvalue)">
                  <label :for="'subcheckbox_' + subkey">
                    <strong>{{ subvalue.name }}</strong>
                  </label>
                </span>
              </li>
            </ul>
          </transition>
        </span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useStore } from 'vuex'

const store = useStore()
const configurationList = ref([]);

const props = defineProps({
  selectedcapability: {
    type: Object,
    default: null,
  },
  check: {
    type: String,
    default: null,
  },
});
const draggedOverIndex = ref(null);

const emit = defineEmits([
  'changesMade'
])

const toggleSubfields = (key) => {
  configurationList.value[key].showSubfields = !configurationList.value[key].showSubfields;
};

onMounted(() => {  
  getConfigurationList()
});

watch(() => props.selectedcapability, async () => {
  getConfigurationList()
}, { deep: true } );

const getConfigurationList = (() => {
  if (props.selectedcapability && props.selectedcapability.json) {
    configurationList.value = JSON.parse(props.selectedcapability.json)
  } else {
    configurationList.value = null
  }
})

watch(() => configurationList.value, async () => {
  saveConfigurationList(configurationList.value)
}, { deep: true } );

watch(() => props.check, async () => {
  configurationList.value.forEach((configuration) => {
    if (!configuration.necessary) {
      if ( !props.check ) {
        configuration.checked = props.check
      } else if (!disableCheckbox(configuration)) {
        configuration.checked = props.check
      }
    }
  })
}, { deep: true } );

let draggedItemIndex = null;

const handleDragStart = (index, event) => {
  draggedItemIndex = index;
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', draggedItemIndex);
}

const handleDragOver = (index, event) => {
  event.preventDefault();
  draggedOverIndex.value = index;
}

const handleDragLeave = () => {
  draggedOverIndex.value = null;
}

const saveConfigurationList = (configurationList) => {
  if (configurationList != null) {
    const index = store.state.configlist.findIndex(obj => obj.id === props.selectedcapability.id
      && obj.capability === props.selectedcapability.capability);
    if (index !== -1) {
      if (store.state.configlist[index].json == JSON.stringify(configurationList)) {
        emit('changesMade', {
          changesMade: false,
          index: index,
          configurationList: JSON.stringify(configurationList)
        })
      }else {
        emit('changesMade', {
          changesMade: true,
          index: index,
          configurationList: JSON.stringify(configurationList)
        })
      }
    }
  }
}

const handleDrop = (index, event) => {
  event.preventDefault();
  const droppedItemIndex = event.dataTransfer.getData('text/plain');
  const itemToMove = configurationList.value[droppedItemIndex];
  configurationList.value.splice(droppedItemIndex, 1);
  configurationList.value.splice(index, 0, itemToMove);
  draggedOverIndex.value = null;
}

const handleDragEnd = () => {
  draggedItemIndex = null;
}

const disableCheckbox = (item) => {
  if (item.incompatible && item.incompatible.length > 0) {
    return item.incompatible.some(id => {
      const incompatibleItem = configurationList.value.find(configItem => configItem.id === id);
      return incompatibleItem && incompatibleItem.checked;
    });
  }
  return false;
}

const getBlockMessage = (item) => {
  if (item.incompatible && item.incompatible.length > 0) {
    let incompatibleNames = configurationList.value
      .filter(configurationItem => item.incompatible.includes(configurationItem.id))
      .map(configurationItem => store.state.strings[configurationItem.classname])
    return ` Blocked by: ${incompatibleNames.join(', ')}`;
  }
  return '';
};

const handleCheckboxChange = (value) => {
  configurationList.value.forEach((configuration) => {
    if (value == configuration) {
      configuration.checked = configuration.checked ? 0 : 1
    }
    const blocked = disableCheckbox(configuration)
    if (blocked) {
      configuration.checked = 0;
    }
  })
  saveConfigurationList(configurationList.value)
};
</script>

<style scoped>
li.drag-over {
  background-color: #cbc7c7;
  border-bottom: 2px dashed #333;
}
.blocked-message {
  color: red;
  font-size: 12px;
}

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
