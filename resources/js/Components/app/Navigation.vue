<template>
  <Head title="Techindeck Drive" />
  <nav class="min-w-[200px]" id="nav-color">
    <Link :href="route('myFiles')" class="h-[80px] px-3 flex items-center gap-3">
      <!-- <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800" /> -->
      <TechindeckLogo class="block h-9 w-auto fill-current text-gray-800" />
      DAMS
    </Link>
    <div class="px-3">
      <CreateNewDropdown />

      <div class="py-3">
        <NavLink :href="route('myFiles')" :active="$page.props.route_name === 'myFiles'">
          <MyFilesVue class="mr-2" /> My Files</NavLink
        >
        <NavLink
          :href="route('file.sharedWithMe')"
          :active="$page.props.route_name === 'file.sharedWithMe'"
        >
          <File class="mr-2" />Shared with me</NavLink
        >
        <NavLink
          :href="route('file.sharedByMe')"
          :active="$page.props.route_name === 'file.sharedByMe'"
        >
          <File class="mr-2" /> Shared by me</NavLink
        >
        <NavLink :href="route('trash')" :active="$page.props.route_name === 'trash'">
          <Trans class="mr-2" /> Trash</NavLink
        >
      </div>
    </div>
  </nav>
</template>

<script setup>
// Imports
import { Link } from "@inertiajs/vue3";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import CreateNewDropdown from "@/Components/app/CreateNewDropdown.vue";
import NavLink from "@/Components/NavLink.vue";
import Trans from "@/Pages/Icons/Trans.vue";
import File from "@/Pages/Icons/File.vue";
import MyFilesVue from "@/Pages/Icons/MyFile.vue";
import TechindeckLogo from "@/Components/TechindeckLogo.vue";

// Uses

// Refs

// Props & Emit

// Computed

// Methods

// Hooks
</script>

<style scoped>
#nav-color {
  background-color: #edf0f1;
}
</style>
