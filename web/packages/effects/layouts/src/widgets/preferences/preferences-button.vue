<script lang="ts" setup>
import { Settings } from '@vben/icons';
import { $t } from '@vben/locales';
import { useTemplateRef } from 'vue';

import { VbenIconButton } from '@vben-core/shadcn-ui';

import Preferences from './preferences.vue';

const emit = defineEmits<{ clearPreferencesAndLogout: [] }>();
const preferencesRef = useTemplateRef<InstanceType<typeof Preferences>>('preferencesRef');

function clearPreferencesAndLogout() {
  emit('clearPreferencesAndLogout');
}
</script>
<template>
  <Preferences ref="preferencesRef" @clear-preferences-and-logout="clearPreferencesAndLogout">
    <VbenIconButton :tooltip="$t('preferences.title')" class="hover:animate-[shrink_0.3s_ease-in-out]" @click="preferencesRef?.open()">
      <Settings class="size-4 text-foreground" />
    </VbenIconButton>
  </Preferences>
</template>
