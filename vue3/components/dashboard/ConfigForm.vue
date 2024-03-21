<template>
  <div>
    <notifications width="100%" />
    <CapabilityButtons
      :configlist="configlist"
      :active-tab="activeTab"
      :changes-made="changesMade"
      @capabilityClicked="handleCapabilityClicked"
      @checkAll="handleCheckAll"
      @restoreConfig="changeTab"
    />
    <CapabilityOptions
      :selectedcapability="selectedCapability"
      :check="check"
      @changesMade="handleChangesMade"
    />
  </div>
</template>

<script setup>
  import { onMounted, ref } from 'vue';
  import { useStore } from 'vuex'

  import CapabilityButtons from '../../components/helper/CapabilityButtons.vue';
  import CapabilityOptions from '../../components/helper/CapabilityOptions.vue';

  const store = useStore()
  const selectedCapability = ref(null)
  const configlist = ref(null)
  const check = ref(null)
  const changesMade = ref({})
  const activeTab = ref(0)

  onMounted(async () => {
    // change to real value

    configlist.value = await store.dispatch('fetchTab', {
      contextid : store.state.contextid,
    });
  });

  const handleCapabilityClicked = (capability) => {
    selectedCapability.value = capability
  }

  const handleCheckAll = (checking) => {
    check.value = checking
  }

  const handleChangesMade = (changesMadeEmit) => {
    changesMade.value = changesMadeEmit
  }
</script>