<template>
  <div 
    class="modal" 
    tabindex="-1" 
    role="dialog" 
    :class="{ 'show': showConfirmationModal }"
    style="background-color: #161717a1;"
  >
    <div 
      class="modal-dialog" 
      role="document"
    >
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            {{ strings.vue_heading_modal }}
          </h5>
          <button 
            type="button" 
            class="close" 
            @click="cancelBack"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <p>{{ strings.vue_confirm_modal }}</p>
        </div>
        <div class="modal-footer">
          <button 
            type="button" 
            class="btn btn-secondary" 
            @click="cancelBack"
          >
            {{ strings.vue_booking_stats_no }}
          </button>
          <button 
            type="button" 
            class="btn btn-primary" 
            @click="confirmBack"
          >
            {{ strings.vue_booking_stats_yes }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>

  defineProps({
    showConfirmationModal: {
      type: Boolean,
      required: true,
    },
    strings: {
      type: Object,
      required: true,
    },
  });

  const emit = defineEmits([
    'confirmBack'
  ])

  const cancelBack = () => {
    emit('confirmBack', false)
  };

  const confirmBack = async() => {
    emit('confirmBack', true)
  };

</script>