<template>
  <div>
    <notifications width="100%" />
    <CapabilityButtons 
      :configlist="configlist" 
      @capabilityClicked="handleCapabilityClicked"
      @checkAll="handleCheckAll"
    />
    <CapabilityOptions 
      :selectedcapability="selectedCapability"
      :check="check"
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

  onMounted(async () => {
    // change to real value
    configlist.value = await store.dispatch('fetchTab', store.state.contextid);
  });

  const handleCapabilityClicked = (capability) => {
    selectedCapability.value = capability
  }
  const handleCheckAll = (checking) => {
    check.value = checking
  }
</script>