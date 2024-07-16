<template>
  <div v-if="bookingstats.json && bookingstats.json.booking">
    <h5>{{store.state.strings.vue_dashboard_booking_instances}}</h5>
    <table class="table mt-2">
      <thead class="thead-light">
        <tr>
          <th>{{ store.state.strings.vue_dashboard_checked }}</th>
          <th>{{ store.state.strings.vue_dashboard_name }}</th>
          <th>{{ store.state.strings.vue_booking_stats_booking_options }}</th>
          <th>{{ store.state.strings.vue_booking_stats_booked }}</th>
          <th>{{ store.state.strings.vue_booking_stats_waiting }}</th>
          <th>{{ store.state.strings.vue_booking_stats_reserved }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="bookingStat in bookingstats.json.booking"
          :key="'bookingstats' + bookingStat.id"
        >
          <td>
            <input
              :id="'checkbox_' + bookingStat.id"
              type="checkbox"
              class="mr-2"
              :checked="bookingStat.checked"
              @change="handleCheckboxChange(bookingStat)"
            >
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
