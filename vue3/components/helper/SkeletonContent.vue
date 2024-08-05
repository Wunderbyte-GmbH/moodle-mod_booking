<template>
  <div>
    <p class="mb-0">
      <strong>{{ store.state.strings.vuedashboardname }}:</strong>
      <span
        class="loading-animation"
        :style="{ width: generateRandomWidth() }"
      />
    </p>
    <p class="mb-0">
      <strong>{{ store.state.strings.vuedashboardcoursecount }}:</strong>
      <span
        class="loading-animation"
        :style="{ width: generateRandomWidth() }"
      />
    </p>
    <p class="mb-0">
      <strong>{{ store.state.strings.vuedashboardpath }}:</strong>
      <span
        class="loading-animation"
        :style="{ width: generateRandomWidth() }"
      />
    </p>
  </div>
</template>

<script setup>
  import { useStore } from 'vuex'
  const store = useStore();
  const generateRandomWidth = () => `${Math.floor(Math.random() * 13) + 8}rem`
</script>

<style scoped>
.loading-animation {
  display: inline-block;
  height: 1.25rem; /* Adjust height as per your design */
  background-color: #252222; /* Placeholder color */
  border-radius: 0.5rem;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(143, 139, 139, 0.5) 50%, rgba(255, 255, 255, 0) 100%);
  background-size: 200% 100%;
  animation: loading 1.5s infinite linear;
}

@keyframes loading {
  0% {
    background-position: 100% 50%;
  }
  100% {
    background-position: 0 50%;
  }
}
</style>
