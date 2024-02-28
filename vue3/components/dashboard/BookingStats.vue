<template>
  <div v-if="bookingstats.json && bookingstats.json.booking">
    <table class="table mt-2">
      <thead>
        <tr>
          <th>Name</th>
          <th>Booking Options</th>
          <th>Booked</th>
          <th>Waiting List</th>
          <th>Reserved</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="bookingStat in bookingstats.json.booking" :key="'bookingstats' + bookingStat.id">
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

const props = defineProps({
  bookingstats: {
    type: Array,
    default: null,
  },
});

</script>
