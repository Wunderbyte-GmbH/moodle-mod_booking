<template>
  <div>
    <div v-if="content" class="content-container">
      <div v-if="content.name" class="infoblock d-flex flex-row">
        <div class="col-md-6 pt-3 pl-5">
          <div class="row d-flex justify-content-start">
            <template v-if="content.name">
              <h3 class="font-weight-normal">
                {{ content.name }}
              </h3>
              <br>
            </template>
          </div>
          <div class="row d-flex justify-content-start">
            <template v-if="content.coursecount">
              <p>
                <i class="fa-regular fa-calendar mr-2 text-primary" />
                <strong>{{ strings.dashboardcoursecount }}: </strong> {{ content.coursecount }}
              </p>
            </template>
          </div>
        </div>
        <div class="col-md-6 d-dlex justify-content-end align-items-center row m-0">
          <template v-if="indextab == 0">
            <a role="button" href="/course/editcategory.php?parent=0" class="mb-0 block-link btn button-primary">
              <i class="fa-solid fa-plus mr-1" />
              {{ strings.dashboardcreateoe }}
            </a>
          </template>
          <template v-if="content.contextid">
            <a role="button" :href="'/admin/roles/assign.php?contextid=' + content.contextid"
              class="mb-0 block-link btn button-primary">
              <i class="fa-solid fa-person mr-1" />
              {{ strings.dashboardassignrole }}
            </a>
          </template>
          <template v-if="content.id">
            <a role="button" :href="'/course/edit.php?category=' + content.id"
              class="mb-0 block-link btn button-secondary">
              <i class="fa-solid fa-plus mr-1" />
              {{ strings.dashboardnewcourse }}
            </a>
          </template>
          <a role="button" :href="'/course/index.php?categoryid=' + content.id"
            class="mb-0 block-link btn button-secondary">
            <i class="fa-solid fa-list mr-1" />
            {{ strings.dashboardgotocategory }}
          </a>
        </div>
      </div>
      <div class="cards-container row m-0 mt-4 justify-content-center">
        <div class="col-md-2 mb-2">
          <div class="card h-100">
            <div class="d-flex align-items-center mb-auto">
              <h1 class="font-weight-normal mr-auto">
                <span v-if="content.bookedcount">{{ content.waitinglistcount }}</span>
                <span v-else>/</span>
              </h1>
              <i class="fa-regular fa-bookmark text-success" />
            </div>
            <div class="d-flex align-items-center justify-content-start">
              <span class="pre">{{ strings.dashboardnewbookings }}</span>
            </div>
          </div>
        </div>
        <div class="col-md-2 mb-2">
          <div class="card h-100">
            <div class="d-flex align-items-center  mb-auto">
              <h1 class="font-weight-normal mr-auto">
                <span v-if="content.waitinglistcount">{{ content.waitinglistcount }}</span>
                <span v-else>/</span>
              </h1>
              <i class="fa-solid fa-bolt text-info" />
            </div>
            <div class="d-flex align-items-center justify-content-start">
              <span class="pre">{{ strings.dashboardpplwl }}</span>
            </div>
          </div>
        </div>
        <div class="col-md-2 mb-2">
          <div class="card h-100">
            <div class="d-flex align-items-center  mb-auto">
              <h1 class="font-weight-normal mr-auto">
                0
              </h1>
              <i class="fa-regular fa-circle-xmark text-danger" />
            </div>
            <div class="d-flex align-items-center justify-content-start">
              <span class="pre">{{ strings.dashboardneuestornos }}</span>
            </div>
          </div>
        </div>
        <div class="col-md-2 mb-2">
          <div class="card h-100">
            <div class="d-flex align-items-center  mb-auto">
              <h1 class="font-weight-normal mr-auto">
                0
              </h1>
              <i class="fa-solid fa-thumbs-down text-warning" />
            </div>
            <div class="d-flex align-items-center justify-content-start">
              <span class="pre" v-cloak>{{ strings.dashboardnoshows }}</span>
            </div>
          </div>
        </div>
        <div class="col-md-2 mb-2 ml-auto">
          <a role="button" class="card w-100 h-100 m-0" href="/local/entities/entities.php">
            <div class="d-flex align-items-center  mb-auto">
              <i class="fa-solid fa-location-dot text-dark ml-auto" />
            </div>
            <div class="d-flex align-items-center justify-content-start mt-auto">
              <span class="text-dark pre" v-cloak>{{ strings.dashboardmanagelocation }}</span>
            </div>
          </a>
        </div>
      </div>
      <div v-if="content.courses && content.courses.length > 0" class="courses mt-3">
        <h5 class="mb-4">{{ strings.courses }} <a role="button" data-toggle="collapse" href="#collapseCourses"
            aria-expanded="false" aria-controls="collapseCourses"><i class="fa-solid fa-square-caret-down"></i></a></h5>
        <div class="collapse" id="collapseCourses">
          <div class="list-group d-flex flex-row flex-wrap">
            <div v-for="course in content.courses" :key="course.id" class="m-1">
              <a class="btn button-secondary px-4" role="button" :href="`/course/view.php?id=${course.id}`">{{
                course.fullname
                }}</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { isProxy, toRaw } from 'vue';
const props = defineProps({
  content: {
    type: Object,
    required: true,
  },
  strings: {
    type: Object,
    required: true,
  },
  indextab: {
    type: Number,
    required: true,
  },
});

const GetAccStats = async () => {
  console.log('meine daten', props.content.json);
  let allData = [];
  if (isProxy(props.content)) { //this If() block is not really necessary
    const rawObject = await toRaw(props.content);
    console.log(rawObject)
    if (rawObject.json.length > 0) {
      rawObject.json.forEach(element => {
        console.warn('element', element);
      });
    }

  }

}

GetAccStats()

</script>

<style lang="scss" scoped>
@import './scss/custom.scss';

.pre {
  width: 100%;
  white-space: break-spaces;
}

.block-link {
  display: block;
  margin-bottom: 10px;
  /* Add spacing between links */
  text-decoration: none;
  /* Remove underline */
}

.infoblock {
  min-height: 100px;
  background: $vuelightcontent;
  border-radius: 1.5rem;
}

a {
  width: fit-content;
  margin: 5px;
  padding: 5px;
}

.card {
  min-height: 140px;
  padding: 20px;
  border: 0;
  -webkit-box-shadow: 3px 3px 7px 0px rgba(0, 0, 0, 0.08);
  -moz-box-shadow: 3px 3px 7px 0px rgba(0, 0, 0, 0.08);
  box-shadow: 3px 3px 7px 0px rgba(0, 0, 0, 0.08);

  i {
    font-size: 2.5rem;
  }
}

a.card:hover {
  text-decoration: none;
  outline: 4px solid $vuesecondary;
}
</style>