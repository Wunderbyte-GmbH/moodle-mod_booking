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
      
      <template v-if="indextab == 0">
        <a
          href="/course/editcategory.php?parent=0"
          class="mb-0 block-link"
        >
          {{ strings.vue_dashboard_create_oe }}
        </a>
      </template>
      <template v-if="content.contextid">
        <a
          :href="'/admin/roles/assign.php?contextid=' + content.contextid"
          class="mb-0 block-link"
        >
          {{ strings.vue_dashboard_assign_role }}
        </a>
      </template>
      <template v-if="content.id">
        <a
          :href="'/course/edit.php?category=' + content.id"
          class="mb-0 block-link"
        >
          {{ strings.vue_dashboard_new_course }}
        </a>
      </template>
      <a
        :href="'/course/index.php?categoryid=' + content.id"
        class="mb-0 block-link"
      >
        {{ strings.vue_dashboard_goto_category }}
      </a>
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
    indextab: {
      type: String,
      required: true,
    },
  });
</script>

<style scoped>
.block-link {
  display: block;
  margin-bottom: 10px; /* Add spacing between links */
  text-decoration: none; /* Remove underline */
}
</style>