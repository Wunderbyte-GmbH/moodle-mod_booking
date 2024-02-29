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
            class="btn btn-primary"
            @click="saveContent"
          >
            {{ store.state.strings.vue_booking_stats_save }}
          </button>
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
import { ref, watch, defineEmits, computed } from 'vue'
import { notify } from "@kyvg/vue3-notification"
import { useStore } from 'vuex'

const store = useStore()
const showButtons = ref(true)
const choosenCapability = ref(null)
const selectAllChecked = ref(false);

const props = defineProps({
  configlist: {
      type: Array,
      default: null,
    },
});

const configCapability = computed(() => {
  return props.configlist;
});

const emit = defineEmits(['capabilityClicked'])


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

const saveContent = async () => {
  const result = await store.dispatch('setParentContent', choosenCapability.value)
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
</style>
