<script setup lang="ts">
import { AuthPageLayout } from '@vben/layouts';
import { siteSettings } from '../api/identity';
</script>
<template>
  <AuthPageLayout :app-name="siteSettings.name" :logo="siteSettings.logo_url" :page-title="siteSettings.name" :page-description="siteSettings.description" :toolbar-list="['theme']" />
</template>
