<template>
  <div>
    <notifications width="100%" />
    <Searchbar :tabs="tabsstored" @filteredTabs="updateFilteredTabs" />
    <div class="bgfull">
      <span class="dbhead">{{ store.state.strings.dashboardmydashboard }}</span>
      <div class="d-flex align-items-end">
        <a @click="scrollLeft()" class="text-white ml5em" style="font-size: 1.3em;
    margin-right: 1rem; padding-bottom: 10px"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="overflow-tabs-container d-flex justify-content-start" ref="scrollMe">
          <div v-if="tabs.length > 0" class="nav nav-tabs justify-content-center navouter">
            <div v-for="tab in tabs" :key="tab.id" class="nav-item">
              <a :class="['nav-link', { 'active': activeTab === tab.id }]" @click="changeTab(tab.id)">
                <span class="tabunselected">{{ tab.name }}</span>
              </a>
            </div>
          </div>
          <div v-else>
            <div class="nav nav-tabs">
              <SkeletonTab v-for="index in 3" :key="index" />
            </div>
          </div>
        </div>
        <a @click="scrollRight()" class="text-white mr5em ml-auto" style="font-size: 1.3em;
    margin-left: 1rem; padding-bottom: 10px;"><i class="fa-solid fa-arrow-right"></i></a>
        <!-- Confirmation dialog -->
        <div v-if="showConfirmationModal">
          <ConfirmationModal :show-confirmation-modal="showConfirmationModal" :strings="store.state.strings"
            @confirmBack="confirmBack" />
        </div>
      </div>
    </div>
    <transition name="fade" mode="out-in">
      <div v-if="content" class="content-container">
        <ul v-cloak id="myTab" class="nav nav-tabs justify-content-center bottomtabs mt-4" role="tablist">
          <li class="nav-item" role="presentation">
            <button id="home-tab" class="nav-link active" data-toggle="tab" data-target="#home" type="button" role="tab"
              aria-controls="home" aria-selected="true">
              {{ store.state.strings.dashboardoverview }}
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button id="profile-tab" class="nav-link" data-toggle="tab" data-target="#profile" type="button" role="tab"
              aria-controls="profile" aria-selected="false">
              {{ store.state.strings.dashboardbookingfields }}
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button id="contact-tab" class="nav-link" data-toggle="tab" data-target="#contact" type="button" role="tab"
              aria-controls="contact" aria-selected="false">
              {{ store.state.strings.dashboardstats }}
            </button>
          </li>
        </ul>
        <div id="myTabContent" class="tab-content">
          <div id="home" class="tab-pane fade show active" role="tabpanel" aria-labelledby="home-tab">
            <TabInformation class="mt-4 mb-3" :content="content" :strings="store.state.strings" :indextab="indexTab" />
            <BookingStats :bookingstats="content" />
          </div>
          <div id="profile" class="tab-pane fade" role="tabpanel" aria-labelledby="profile-tab">
            <CapabilityButtons :configlist="configlist" :active-tab="activeTab" :changes-made="changesMade"
              @capabilityClicked="handleCapabilityClicked" @checkAll="handleCheckAll"
              @restoreConfig="handleRestoreConfig" />
            <CapabilityOptions :selectedcapability="selectedCapability" :check="check"
              @changesMade="handleChangesMade" />
          </div>
          <div id="contact" class="tab-pane fade" role="tabpanel" aria-labelledby="contact-tab">
            <StatisticsView class="mt-4" />
          </div>
        </div>
      </div>
      <div v-else class="content-container">
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
import StatisticsView from './dashboard/StatisticsView.vue';

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
const indexTab = ref(0)
const scrollMe = ref(null);

// Trigger web services on mount
onMounted(async () => {
  configlist.value = await store.dispatch('fetchTab', {
    coursecategoryid: 0,
    contextid: 0,
  });
  tabsstored.value = store.state.tabs
  tabs.value = store.state.tabs
  content.value = store.state.content
});

watch(() => store.state.tabs, async () => {
  tabsstored.value = store.state.tabs
  tabs.value = store.state.tabs
}, { deep: true });

watch(() => store.state.content, async () => {
  content.value = store.state.content
}, { deep: true });

async function changeTab(index) {
  indexTab.value = index
  if (changesMade.value && changesMade.value.changesMade) {
    showConfirmationModal.value = true
  } else {
    activeTab.value = indexTab.value;
    selectedCapability.value = null
    configlist.value = await store.dispatch('fetchTab', {
      coursecategoryid: indexTab.value,
      contextid: findElementById(tabs.value, indexTab.value),
    });
  }
}


const scrollLeft = () => {
  if (scrollMe.value) {
    scrollMe.value.scrollBy({
      top: 0,
      left: -100, // Adjust the value as needed for scroll distance
      behavior: 'smooth'
    });
  }
};

const scrollRight = () => {
  if (scrollMe.value) {
    scrollMe.value.scrollBy({
      top: 0,
      left: +100, // Adjust the value as needed for scroll distance
      behavior: 'smooth'
    });
  }
};

async function handleRestoreConfig(index) {
  indexTab.value = index
  activeTab.value = indexTab.value;
  selectedCapability.value = null
  configlist.value = await store.dispatch('fetchTab', {
    coursecategoryid: indexTab.value,
    contextid: findElementById(tabs.value, indexTab.value),
  });
}

const confirmBack = async (confirmation) => {
  showConfirmationModal.value = false;
  if (confirmation) {
    activeTab.value = indexTab.value;
    selectedCapability.value = null
    changesMade.value = null
    configlist.value = await store.dispatch('fetchTab', {
      coursecategoryid: indexTab.value,
      contextid: findElementById(tabs.value, indexTab.value),
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

<style lang="scss" scoped>
@import './scss/custom.scss';

.fade-enter-active,
.fade-leave-active {
  transition: all 0.5s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateX(30px);
}

.overflow-tabs-container {
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
}

.nav-item {
  margin-right: 2px;
}

.nav-tabs {
  display: flex !important;
  flex-wrap: nowrap !important;
  border: 0;
}

.nav-link {
  padding: 0.5rem 1rem;
  color: white;
  /* Dark gray text color */
  border: 0;
  cursor: pointer;
  padding-bottom: 15px
}

.bottomtabs .nav-link {
  color: black
}

.bottomtabs .nav-link.active {
  border-bottom: 2px solid #098790;
}

.nav-link:focus {
  outline: 0;
  box-shadow: none !important;
}

.nav-link.active {
  /* Blue background for active tab */
}

.content-container {
  width: 100%;
  max-width: 1300px;
  margin: 0 auto;
}

.overflow-tabs-container {
  min-height: 200px;
  // background: linear-gradient(258.38deg, #098790 1.99%, #001F33 98.96%);
  display: flex;
  justify-content: center;
  align-items: end;
  position: relative;
  // margin-left: 5em;
  // margin-right: 5em;

}

.ml5em {
  margin-left: 5em;
}

.mr5em {
  margin-right: 5em;
}

.bgfull {
  background: linear-gradient(258.38deg, #098790 1.99%, #001F33 98.96%);
  margin-left: -5em;
  margin-right: -5em;
  position: relative;
}

.navouter {
  z-index: 2;
}

.dbhead {
  position: absolute;
  width: 100%;
  bottom: 50%;
  left: 0;
  text-align: center;
  color: white;
  font-size: 1.6rem;
}

.tabunselected {
  // border-bottom: 1px solid white;
  // padding-bottom: 2px;
}


@media (max-width: 767.98px) {
  .nav-tabs {
    background: transparent !important;

    .nav-link {
      background: transparent;
    }
  }

}

// .overflow-tabs-container::after {
//   content: '';
//   background: red;
//   width: 100%;
//   margin-left: -5em;
//   margin-right: -5em;
//   height: 100%;
//   position: absolute;
//   z-index: 0;
//   bottom: 0;
//   left: 0;
// }</style>