<template>
  <div>
    <div
      v-if="content"
      class="content-container"
    >
      <template v-if="content.name">
        <strong>{{ strings.vue_dashboard_name }}: </strong> {{ content.name }}
        <br>
      </template>
      <template v-if="content.coursecount">
        <strong>{{ strings.vue_dashboard_course_count }}: </strong> {{ content.coursecount }}
        <br>
      </template>
      <template v-if="content.path">
        <strong>{{ strings.vue_dashboard_path }}: </strong> {{ content.path }}
        <br>
      </template>
      <a
        href="/course/editcategory.php?parent=0"
        class="mb-0"
      >
        {{ strings.vue_dashboard_create_oe }}
      </a>
      <br>
      <template v-if="content.contextid">
        <a
          :href="'/admin/roles/assign.php?contextid=' + content.contextid"
          class="mb-0"
        >
          {{ strings.vue_dashboard_assign_role }}
        </a>
      </template>
      <br>
      <template v-if="content.id">
        <a
          :href="'/course/edit.php?category=' + content.id"
          class="mb-0">
          {{ strings.vue_dashboard_new_course }}
        </a>
      </template>
    </div>
  </div>
</template>

<script setup>
  defineProps({
    content: {
      type: Object,
      required: true,
    },
    strings: {
      type: Object,
      required: true,
    },
  });

</script>