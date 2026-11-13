<script setup lang="ts">
import { useId, useSlots } from 'vue';

import { CircleHelp } from '@vben/icons';

import { Switch, VbenTooltip } from '@vben-core/shadcn-ui';

defineOptions({
  name: 'PreferenceSwitchItem',
});

const props = withDefaults(defineProps<{ disabled?: boolean; tip?: string }>(), {
  disabled: false,
  tip: '',
});

const checked = defineModel<boolean>();

const slots = useSlots();
const labelId = useId();

function handleClick() {
  if (props.disabled) return;
  checked.value = !checked.value;
}
</script>

<template>
  <div
    :class="{
      'pointer-events-none opacity-50': disabled,
    }"
    class="my-1 flex w-full items-center justify-between rounded-md px-2 py-2.5 hover:bg-accent"
    @click="handleClick"
  >
    <span :id="labelId" class="flex items-center text-sm">
      <slot></slot>

      <VbenTooltip v-if="slots.tip || tip" side="bottom">
        <template #trigger>
          <CircleHelp class="ml-1 size-3 cursor-help" />
        </template>
        <slot name="tip">
          <template v-if="tip">
            <p v-for="(line, index) in tip.split('\n')" :key="index">
              {{ line }}
            </p>
          </template>
        </slot>
      </VbenTooltip>
    </span>
    <span v-if="$slots.shortcut" class="mr-2 ml-auto text-xs opacity-60">
      <slot name="shortcut"></slot>
    </span>
    <Switch v-model="checked" :aria-labelledby="labelId" :disabled="disabled" @click.stop />
  </div>
</template>
