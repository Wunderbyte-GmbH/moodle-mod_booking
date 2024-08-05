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
            {{ strings.vueheadingmodal }}
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
          <p>{{ strings.vueconfirmmodal }}</p>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-secondary"
            @click="cancelBack"
          >
            {{ strings.vuebookingstatsno }}
          </button>
          <button
            type="button"
            class="btn btn-primary"
            @click="confirmBack"
          >
            {{ strings.vuebookingstatsyes }}
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