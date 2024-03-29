<template>
  <div 
    class="skeleton-tab" 
    :style="{ width: randomWidth }"
  >
    <div 
      class="nav-link loading-animation"
    />
  </div>
</template>


<script setup>
import { ref } from 'vue'

const randomWidth = ref(`${Math.floor(Math.random() * 6) + 4}rem`) // Adjust range and units as needed
</script>

<style scoped>
.skeleton-tab {
  height: 2rem; /* Adjust height as per your design */
  background-color: #ccc; /* Placeholder color */
  border-top-left-radius: 1rem;
  border-top-right-radius: 1rem;
}

.loading-animation {
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.5) 50%, rgba(255, 255, 255, 0) 100%);
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
