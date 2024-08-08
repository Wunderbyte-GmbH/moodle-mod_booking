<template>
  <div class="mt-4">
    <div v-if="!showButtons">
      <p><strong>{{ store.state.strings.vuebookingstatscapability }}: {{ store.state.strings[choosenCapability.capability] }}</strong></p>
      <div class="row mt-2">
        <div class="col-md-12">
          <button
            class="btn btn-secondary mr-2"
            @click="handleBackButtonClick"
          >
            {{ store.state.strings.vuebookingstatsback }}
          </button>
          <button
            class="btn btn-primary mr-2"
            @click="saveContent"
          >
            {{ store.state.strings.vuebookingstatssave }}
          </button>
          <button
            class="btn btn-warning"
            :disabled="showConfirmation"
            @click="showConfirmation=true"
          >
            {{ store.state.strings.vuebookingstatsrestore }}
          </button>

          <!-- Confirmation dialog -->
          <div v-if="showConfirmationBack">
            <ConfirmationModal
              :show-confirmation-modal="showConfirmationBack"
              :strings="store.state.strings"
              @confirmBack="confirmBack"
            />
          </div>

          <transition name="slide-fade">
            <div
              v-if="changesMade.changesMade"
              class="unsaved-dialog"
            >
              {{ store.state.strings.vuecapabilityunsavedchanges }}
            </div>
          </transition>
          <transition name="slide-fade">
            <div
              v-if="showConfirmation"
              class="confirmation-dialog"
            >
              <div class="confirmation-content">
                <p>
                  {{ store.state.strings.vuecapabilityunsavedcontinue }}
                </p>
                <div class="btn-row">
                  <button
                    class="btn btn-secondary mr-2"
                    @click="showConfirmation=false"
                  >
                    {{ store.state.strings.vuebookingstatsno }}
                  </button>
                  <button
                    class="btn btn-primary mr-2"
                    @click="restoreContent"
                  >
                    {{ store.state.strings.vuebookingstatsyes }}
                  </button>
                </div>
              </div>
            </div>
          </transition>
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
                <label :for="'select_all'"><strong>{{ store.state.strings.vuebookingstatsselectall }}</strong></label>
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
import ConfirmationModal from '../modal/ConfirmationModal.vue'
import { ref, watch, computed } from 'vue'
import { notify } from "@kyvg/vue3-notification"
import { useStore } from 'vuex'

const store = useStore()
const showButtons = ref(true)
const choosenCapability = ref(null)
const selectAllChecked = ref(false);
const showConfirmation = ref(false)
const showConfirmationBack = ref(false)

const props = defineProps({
  configlist: {
      type: Array,
      default: null,
    },
  activeTab: {
    type: Number,
    default: null,
  },
  changesMade: {
    type: Object || Array,
    default: null,
  },
});

const configCapability = computed(() => {
  return props.configlist;
});

const emit = defineEmits([
  'capabilityClicked',
  'setParentContent',
  'restoreConfig'
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
  showConfirmation.value=false
}

watch(() => props.activeTab, async () => {
  showButtons.value = true
});

const saveContent = async () => {
  if (props.changesMade.changesMade){
    choosenCapability.value.json = props.changesMade.configurationList
    const result = await store.dispatch('setParentContent', choosenCapability.value)
    notificationSet(result, 'saved')
  } else {
    notify({
      title: store.state.strings.vuenotificationtitleunsave,
      text: store.state.strings.vuenotificationtextunsave,
      type: 'warn'
    });
  }
}

const restoreContent = async () => {
  showButtons.value = true
  showConfirmationBack.value = false
  showConfirmation.value=false
  let restoreChoosenCapability = { ...choosenCapability.value}
  restoreChoosenCapability.json = JSON.stringify({
    reset: true
  })
  const result = await store.dispatch('setParentContent', restoreChoosenCapability)
  notificationSet(result, 'restored')
  emit('restoreConfig', props.activeTab)
}

const notificationSet = (result, action) => {
  if(result.status == 'success'){
    notify({
      title: store.state.strings.vuenotificationtitleactionsuccess.replace('{$a}', action),
      text: store.state.strings.vuenotificationtextactionsuccess.replace('{$a}', action),
      type: 'success'
    });
  }else {
    notify({
      title: store.state.strings.vuenotificationtitleactionfail.replace('{$a}', action),
      text: store.state.strings.vuenotificationtextactionfail.replace('{$a}', action),
      type: 'warn'
    });
  }
}

const editAll = () => {
  emit('checkAll', selectAllChecked.value)
}

const handleBackButtonClick = () => {
  if (props.changesMade.changesMade) {
    showConfirmationBack.value = true
  } else {
    showButtons.value = true
    handleCapabilityClick(null)
  }
};

const confirmBack = async(confirmation) => {
  showConfirmationBack.value = false;
  if (confirmation) {
    showButtons.value = true
    handleCapabilityClick(null)
  }
};

</script>

<style scoped>
.bottom-line {
  border-bottom: 1px solid black;
  padding-bottom: 5px;
}

.unsaved-dialog, .confirmation-dialog {
  background-color: #ffffff;
  border-radius: 8px;
  padding: 10px;
  margin-top: 10px;
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
