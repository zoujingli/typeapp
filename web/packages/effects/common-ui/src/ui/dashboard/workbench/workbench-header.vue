<script lang="ts" setup>
import { VbenAvatar } from '@vben-core/shadcn-ui';

interface Props {
  avatar?: string;
  stats?: { label: string; value: number | string }[];
}

defineOptions({
  name: 'WorkbenchHeader',
});

withDefaults(defineProps<Props>(), {
  avatar: '',
  stats: () => [],
});
</script>
<template>
  <div class="card-box p-4 py-6 lg:flex">
    <VbenAvatar :src="avatar" class="size-20" />
    <div
      v-if="$slots.title || $slots.description"
      class="flex flex-col justify-center md:mt-0 md:ml-6"
    >
      <h1 v-if="$slots.title" class="text-md font-semibold md:text-xl">
        <slot name="title"></slot>
      </h1>
      <span v-if="$slots.description" class="mt-1 text-foreground/80">
        <slot name="description"></slot>
      </span>
    </div>
    <div v-if="stats.length" class="mt-4 flex flex-1 justify-end gap-8 md:mt-0 md:gap-12">
      <div v-for="stat in stats" :key="stat.label" class="flex flex-col justify-center text-right last:mr-4 md:last:mr-10">
        <span class="text-foreground/80">{{ stat.label }}</span>
        <span class="text-2xl">{{ stat.value }}</span>
      </div>
    </div>
  </div>
</template>
