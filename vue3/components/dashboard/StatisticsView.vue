<template>
  <div class="w-100" ref="root" >
    <div>{{ date }} {{ orga }}</div>

  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import "vue-select/dist/vue-select.css";
import { useStore } from 'vuex'

const store = useStore();

const root = ref(null);

const date = ref();

const orga = ref();

const color = '#0063A6';

// const gradient = ctx.createLinearGradient(0, 0, 0, 400);
//       gradient.addColorStop(0, 'rgba(54, 162, 235, 0.5)');
//       gradient.addColorStop(1, 'rgba(54, 162, 235, 0)');
  const verlauf = computed(() => {
//     nextTick().then(() => {
//     // DOM is now updated
//     const canvas = document.querySelector('canvas');
//      const ctx = canvas.getContext('2d');
//       let gradient = ctx.createLinearGradient(0, 0, 0, 400);
//       gradient.addColorStop(0, 'rgba(54, 162, 235, 0.5)');
//       gradient.addColorStop(1, 'rgba(54, 162, 235, 0)');
//     console.log('canvas', canvas, gradient);
//     dataLine.datasets[0].backgroundColor = gradient;
// })
    // const canvas = document.querySelector('canvas').getContext('2d');
    // console.log('canvas', canvas);
    // const ctx = canvas.value.getContext('2d');
    //   let gradient = ctx.createLinearGradient(0, 0, 0, 400);
    //   gradient.addColorStop(0, 'rgba(54, 162, 235, 0.5)');
    //   gradient.addColorStop(1, 'rgba(54, 162, 235, 0)');
      return 'rgba(54, 162, 235, 0)';
  }) ;

  onMounted(() => {
    console.log('daata',dataLine)
    // dataLine.value.datasets[0].backgroundColor = 'purple';
    // await nextTick()
    // console.log('tpldasdasdasd', root.value.$refs.canvas);
  // const canvas = document.querySelector('canvas').getContext('2d');
  //   console.log('canvas', canvas);
  });

  const dataLine = {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
        datasets: [
          {
            label: 'Total Profits',
            data: [10, 30, 50, 20, 25, 45, 35, 20, 50, 0],
            backgroundColor : color,
            borderColor: 'rgba(54, 1, 235, 0)',
            borderWidth: 2,
            fill: true,
            tension: 0.4 // This makes the line smooth
          }
        ]
       };

  const options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Buchungen',
                    },
                },
                scales: {
                    // y: {
                    //     beginAtZero: true,
                    //     max: 60,
                    // }
                }
            }

</script>
<style lang="scss" scoped>
@import "vue-select/dist/vue-select.css";
 @import './scss/custom.scss';

.infoblock {
  min-height: 100px;
  background: $vuelightcontent;
  border-radius: 1.5rem;
}

  canvas {
    max-width: 100% !important;
  }

  :global(.dp__pointer) {
    border-radius: 8px;
    height: 40px !important;
  }

  :global(#vs1__combobox) {
    height: 40px !important;
  }

  .myselects {
    background-color: white;
    border: 2px solid $vueprimary;
    border-radius: 8px;


    input {
      border-radius: 8px !important;
    }
  }

  .inputspan {
    position: absolute;
    z-index: 9;
    left: 2em;
    top: -10px;
    font-size: small;
    padding: 0 10px;
  }

  .inputspan::after {
    position: absolute;
    background: white;
    content: '';
    left: 0;
    bottom: 6px;
    height: 5px;
    width: 100%;
    z-index: -1;
  }
</style>