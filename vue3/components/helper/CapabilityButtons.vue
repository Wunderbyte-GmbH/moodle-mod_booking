<template>
  <div class="mt-4">
    <div v-if="!showButtons">
      <p><strong>{{ store.state.strings.vue_booking_stats_capability }}: {{ choosenCapability.name }}</strong></p>
      <div class="row mt-2">
        <div class="col-md-12">
          <button
            class="btn btn-secondary mr-2"
            @click="showButtons = true; handleCapabilityClick(null)"
          >
            {{ store.state.strings.vue_booking_stats_back }}
          </button>
          <button
            class="btn btn-primary mr-2"
            @click="saveContent"
          >
            {{ store.state.strings.vue_booking_stats_save }}
          </button>
          <button
            class="btn btn-warning"
            :disabled="showConfirmation"
            @click="showConfirmation=true"
          >
            {{ store.state.strings.vue_booking_stats_restore }}
          </button>
          <transition name="slide-fade">
            <div 
              v-if="changesMade.changesMade"
              class="unsaved-dialog"
            >
              There are unsaved changes
            </div>
          </transition>
          <div 
            v-if="showConfirmation" 
            class="confirmation-dialog"
          >
            <div class="confirmation-content">
              <p>You really want to reset this configuration?</p>
              <!-- Buttons in a new row -->
              <div class="btn-row">
                <button
                  class="btn btn-secondary mr-2"
                  @click="showConfirmation=false"
                >
                  {{ store.state.strings.vue_booking_stats_no }}
                </button>
                <button
                  class="btn btn-primary mr-2"
                  @click="restoreContent"
                >
                  {{ store.state.strings.vue_booking_stats_yes }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="row mt-2">
        <div class="col-md-12">
          <ul class="bottom-line">
            <li>
              <span>
                <input
                  id="'select_all'"
                  v-model="selectAllChecked"
                  class="mr-2"
                  type="checkbox"
                  @change="editAll"
                >
                <label :for="'select_all'"><strong>{{ store.state.strings.vue_booking_stats_select_all }}</strong></label>
              </span>
            </li>
          </ul>
        </div>
      </div>
    </div>
    <div v-else>
      <div class="row">
        <div class="col-md-12">
          <button
            v-for="capability in configCapability"
            :key="capability.id"
            class="btn btn-outline-primary mr-2 mb-2"
            @click="showButtons = false; handleCapabilityClick(capability)"
          >
            {{ store.state.strings[capability.capability] }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { notify } from "@kyvg/vue3-notification"
import { useStore } from 'vuex'

const store = useStore()
const showButtons = ref(true)
const choosenCapability = ref(null)
const selectAllChecked = ref(false);
const showConfirmation = ref(false)

const props = defineProps({
  configlist: {
      type: Array,
      default: null,
    },
  activeTab: {
    type: Array,
    default: null,
  },
  changesMade: {
    type: Array,
    default: null,
  },
});

const configCapability = computed(() => {
  return props.configlist;
});

const emit = defineEmits([
  'capabilityClicked',
  'setParentContent'
])


watch(() => choosenCapability.value, async () => {
  if (choosenCapability.value == null) {
    emit('capabilityClicked', null)
  }else {
    emit('capabilityClicked', choosenCapability.value)
  }
}, { deep: true } );

const handleCapabilityClick = (capability) => {
  choosenCapability.value = capability;
}

watch(() => props.activeTab, async () => {
  showButtons.value = true
});

const saveContent = async () => {
  if (props.changesMade.changesMade){
    choosenCapability.value.json = props.changesMade.configurationList
    const result = await store.dispatch('setParentContent', choosenCapability.value)
    notificationSet(result)
  } else {
    notify({
      title: 'No unsaved changes detected',
      text: 'There were no unsaved changes detected.',
      type: 'warning'
    });
  }
}

const restoreContent = async () => {
  showButtons.value = true
  showConfirmation.value = false
  let restoreChoosenCapability = { ...choosenCapability.value}
  restoreChoosenCapability.json = JSON.stringify({
    reset: true
  })
  const result = await store.dispatch('setParentContent', restoreChoosenCapability)
  notificationSet(result)
  emit('restoreConfig', props.activeTab)
}

const notificationSet = (result) => {
  if(result.status == 'success'){
    notify({
      title: 'Configuration was saved',
      text: 'Configuration was saved successfully.',
      type: 'success'
    });
  }else {
    notify({
      title: 'Configuration was saved',
      text: 'Something went wrong while saving. The changes have not been changed.',
      type: 'warn'
    });
  }
} 

const editAll = () => {
  emit('checkAll', selectAllChecked.value)
}
</script>

<style scoped>
.bottom-line {
  border-bottom: 1px solid black;
  padding-bottom: 5px;
}

.unsaved-dialog, .confirmation-dialog {
  background-color: #ffffff; /* White background */
  border-radius: 8px; /* Rounded corners */
  padding: 10px; /* Padding for content */
  margin-top: 10px; /* Space between buttons and content */
}

.btn-row {
  margin-top: 10px; /* Space between buttons */
}

.slide-fade-enter-active, .slide-fade-leave-active {
  transition: all 0.5s ease;
}

.slide-fade-enter-from, .slide-fade-leave-to {
  opacity: 0;
  transform: translateY(-20px);
}
</style>
