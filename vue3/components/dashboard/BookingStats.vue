<template>
  <div v-if="bookingstats.json && bookingstats.json.booking">
    <table class="table mt-2">
      <thead>
        <tr>
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

</script>
