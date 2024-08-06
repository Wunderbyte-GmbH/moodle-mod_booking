<template>
  <div v-if="bookingstats.json && bookingstats.json.booking">
    <h5>{{store.state.strings.dashboardbookinginstances}}</h5>
    <table class="table mt-2">
      <thead class="thead-light">
        <tr>
          <th>{{ store.state.strings.vuedashboardchecked }}</th>
          <th>{{ store.state.strings.vuedashboardname }}</th>
          <th>{{ store.state.strings.vuebookingstatsbookingoptions }}</th>
          <th>{{ store.state.strings.vuebookingstatsbooked }}</th>
          <th>{{ store.state.strings.vuebookingstatswaiting }}</th>
          <th>{{ store.state.strings.vuebookingstatsreserved }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="bookingStat in bookingstats.json.booking"
          :key="'bookingstats' + bookingStat.id"
        >
          <td>
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" :id="'checkbox_' + bookingStat.id"
              :checked="bookingStat.checked"
              @change="handleCheckboxChange(bookingStat)">
              <label class="custom-control-label" :for="'checkbox_' + bookingStat.id"></label>
            </div>
            <!-- <input
              :id="'checkbox_' + bookingStat.id"
              type="checkbox"
              class="form-check-input mr-2" role="switch"
              :checked="bookingStat.checked"
              @change="handleCheckboxChange(bookingStat)"
            > -->
          </td>
          <td>
            <a :href="'/mod/booking/view.php?id=' + bookingStat.id">
              {{ bookingStat.name }}
            </a>
          </td>
          <td>{{ bookingStat.bookingoptions }}</td>
          <td>{{ bookingStat.booked }}</td>
          <td>{{ bookingStat.waitinglist }}</td>
          <td>{{ bookingStat.reserved }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>

  import { useStore } from 'vuex'
  const store = useStore();

  defineProps({
    bookingstats: {
      type: Object,
      default: null,
    },
  });

  const handleCheckboxChange = async (bookingStat) => {
    await store.dispatch('setCheckedBookingInstance', bookingStat)
  }

</script>



<style lang="scss" scoped>
 @import './scss/custom.scss';

  .thead-light th {
    background: $vuelightcontent;
  }
</style>
