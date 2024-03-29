<template>
  <div>
    <notifications width="100%" />
    <Searchbar
      :tabs="tabsstored"
      @filteredTabs="updateFilteredTabs"
    />
    <div class="overflow-tabs-container">
      <div>
        <div
          v-if="tabs.length > 0"
          class="nav nav-tabs"
        >
          <div
            v-for="tab in tabs"
            :key="tab.id"
            class="nav-item"
          >
            <a
              :class="['nav-link', { 'active': activeTab === tab.id }]"
              @click="changeTab(tab.id)"
            >
              {{ tab.name }}
            </a>
          </div>
        </div>
        <div v-else>
          <div class="nav nav-tabs">
            <SkeletonTab
              v-for="index in 3"
              :key="index"
            />
          </div>
        </div>
      </div>
      <!-- Confirmation dialog -->
      <div v-if="showConfirmationModal">
        <ConfirmationModal
          :show-confirmation-modal="showConfirmationModal"
          :strings="store.state.strings"
          @confirmBack="confirmBack"
        />
      </div>
    </div>
    <transition
      name="fade"
      mode="out-in"
    >
      <div
        v-if="content"
        class="content-container"
      >
        <TabInformation
          :content="content"
          :strings="store.state.strings"
        />
        <BookingStats :bookingstats="content" />
        <CapabilityButtons
          :configlist="configlist"
          :active-tab="activeTab"
          :changes-made="changesMade"
          @capabilityClicked="handleCapabilityClicked"
          @checkAll="handleCheckAll"
          @restoreConfig="handleRestoreConfig"
        />
        <CapabilityOptions
          :selectedcapability="selectedCapability"
          :check="check"
          @changesMade="handleChangesMade"
        />
      </div>
      <div
        v-else
        class="content-container"
      >
        <SkeletonContent />
      </div>
    </transition>
  </div>
</template>

<script setup>
  import { ref, onMounted, watch } from 'vue'
  import Searchbar from '../components/FilterSearchbar.vue'
  import { useStore } from 'vuex'
  import SkeletonTab from '../components/helper/SkeletonTab.vue';
  import SkeletonContent from '../components/helper/SkeletonContent.vue';
  import CapabilityButtons from '../components/helper/CapabilityButtons.vue';
  import CapabilityOptions from '../components/helper/CapabilityOptions.vue';
  import BookingStats from '../components/dashboard/BookingStats.vue';
  import TabInformation from '../components/dashboard/TabInformation.vue';
  import ConfirmationModal from './modal/ConfirmationModal.vue'

  const content = ref();
  const store = useStore();
  const tabsstored = ref([]);
  const tabs = ref([]);
  const activeTab = ref(0);
  const selectedCapability = ref(null);
  const configlist = ref(null)
  const check = ref(null)
  const changesMade = ref({})
  const showConfirmationModal = ref(false)
  const indexTab = ref(null)

  // Trigger web services on mount
  onMounted(async() => {
    configlist.value = await store.dispatch('fetchTab', {
      coursecategoryid: 0,
      contextid : 0,
    });
    tabsstored.value = store.state.tabs
    tabs.value = store.state.tabs
    content.value = store.state.content

  });

  watch(() => store.state.tabs, async () => {
    tabsstored.value = store.state.tabs
    tabs.value = store.state.tabs
  }, { deep: true } );

  watch(() => store.state.content, async () => {
    content.value = store.state.content
  }, { deep: true } );

  async function changeTab(index) {
    indexTab.value = index
    if (changesMade.value && changesMade.value.changesMade) {
      showConfirmationModal.value = true
    } else {
      activeTab.value = indexTab.value;
      selectedCapability.value = null
      configlist.value = await store.dispatch('fetchTab', {
        coursecategoryid: indexTab.value,
        contextid : findElementById(tabs.value, indexTab.value),
      });
    }
  }
  async function handleRestoreConfig(index) {
    indexTab.value = index
    activeTab.value = indexTab.value;
    selectedCapability.value = null
    configlist.value = await store.dispatch('fetchTab', {
      coursecategoryid: indexTab.value,
      contextid : findElementById(tabs.value, indexTab.value),
    });
  }

  const confirmBack = async(confirmation) => {
    showConfirmationModal.value = false;
    if (confirmation) {
      activeTab.value = indexTab.value;
      selectedCapability.value = null
      changesMade.value = null
      configlist.value = await store.dispatch('fetchTab', {
        coursecategoryid: indexTab.value,
        contextid : findElementById(tabs.value, indexTab.value),
      });
    }
  };

  const updateFilteredTabs = (filteredTabsFromSearchbar) => {
    tabs.value = filteredTabsFromSearchbar;
  }

  const handleCapabilityClicked = (capability) => {
    selectedCapability.value = capability
  }

  const handleChangesMade = (changesMadeEmit) => {
    changesMade.value = changesMadeEmit
  }

  const handleCheckAll = (checking) => {
    check.value = checking
  }

  function findElementById(jsonData, idToFind) {
      for (var i = 0; i < jsonData.length; i++) {
          if (jsonData[i].id === idToFind) {
              return jsonData[i].contextid;
          }
      }
      return 0;
  }
</script>

<style scoped>

.fade-enter-active, .fade-leave-active {
  transition: all 0.5s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateX(30px);
}
.overflow-tabs-container {
  overflow-x: auto;
  white-space: nowrap;
}

.nav-item{
  margin-right: 2px;
}
.nav-tabs {
  display: flex !important;
  flex-wrap: nowrap !important;
  border-bottom: 1px solid #e0e0e0; /* Light gray bottom border */
}

.nav-link {
  background-color: #bdb5b5; /* White background */
  padding: 0.5rem 1rem;
  color: #555555c7; /* Dark gray text color */
}

.nav-link.active {
  background-color: #528cef; /* Blue background for active tab */
  color: #fff; /* White text color for active tab */
}

.content-container {
  width: 100%;
  background: #f5f5f5; /* Light gray background for content */
  min-height: 300px;
  border-bottom-left-radius: 3rem;
  border-bottom-right-radius: 3rem;
  padding: 1rem;
}
</style>