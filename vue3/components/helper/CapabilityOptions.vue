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
      store.state.configlist[index].json = JSON.stringify(configurationList)
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
    const incompatibleNames = item.incompatible
      .map(id => configurationList.value.find(configItem => configItem.id === id))
      .filter(incompatibleItem => incompatibleItem && incompatibleItem.checked)
      .map(incompatibleItem => incompatibleItem.name);
      if (incompatibleNames.length > 0) {
      return ` Blocked by: ${incompatibleNames.join(', ')}`;
    }
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
</style>
