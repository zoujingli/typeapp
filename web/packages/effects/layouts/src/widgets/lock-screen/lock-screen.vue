<script setup lang="ts">
import { computed, onMounted, reactive, ref, useTemplateRef } from 'vue';

import { LockKeyhole } from '@vben/icons';
import { $t, useI18n } from '@vben/locales';
import { storeToRefs, useAccessStore } from '@vben/stores';

import { useScrollLock } from '@vben-core/composables';
import { useVbenForm, z } from '@vben-core/form-ui';
import { VbenAvatar, VbenButton } from '@vben-core/shadcn-ui';

import { useNow } from '@vueuse/core';

interface Props {
  avatar?: string;
  timezone?: string;
  verifyPassword?: (password: string) => Promise<boolean>;
}

defineOptions({
  name: 'LockScreen',
});

const props = withDefaults(defineProps<Props>(), {
  avatar: '',
});

defineEmits<{ toLogin: [] }>();

const { locale } = useI18n();
const accessStore = useAccessStore();

const now = useNow();
const clockParts = computed(() => new Intl.DateTimeFormat(locale.value, { timeZone: props.timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(now.value));
const hour = computed(() => clockParts.value.find(part => part.type === 'hour')?.value);
const minute = computed(() => clockParts.value.find(part => part.type === 'minute')?.value);
const meridiem = computed(() => new Intl.DateTimeFormat(locale.value, { timeZone: props.timezone, hour: 'numeric', hour12: true }).formatToParts(now.value).find(part => part.type === 'dayPeriod')?.value);
const date = computed(() => new Intl.DateTimeFormat(locale.value, { timeZone: props.timezone, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'long' }).format(now.value));

const showUnlockForm = ref(false);
const screen = useTemplateRef<HTMLDivElement>('screen');
const { lockScreenPassword } = storeToRefs(accessStore);

function trapFocus(event: KeyboardEvent) {
  const items = [...(screen.value?.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), [tabindex="0"]') || [])].filter(item => item.getClientRects().length);
  const first = items[0];
  const last = items.at(-1);
  if (event.shiftKey && (document.activeElement === first || document.activeElement === screen.value)) {
    event.preventDefault(); last?.focus();
  } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === screen.value)) {
    event.preventDefault(); first?.focus();
  }
}

function containFocus(event: FocusEvent) {
  if (event.relatedTarget instanceof Node && !screen.value?.contains(event.relatedTarget)) screen.value?.focus();
}

onMounted(() => requestAnimationFrame(() => screen.value?.focus()));

const [Form, { form, validate, getFieldComponentRef }] = useVbenForm(
  reactive({
    commonConfig: {
      hideLabel: true,
      hideRequiredMark: true,
    },
    schema: computed(() => [
      {
        component: 'VbenInputPassword' as const,
        componentProps: {
          autocomplete: 'current-password',
          placeholder: $t('ui.widgets.lockScreen.placeholder'),
        },
        fieldName: 'password',
        label: $t('authentication.password'),
        rules: z.string().min(1, { message: $t('authentication.passwordTip') }),
      },
    ]),
    showDefaultActions: false,
  }),
);

const validPass = computed(
  () => lockScreenPassword?.value === form?.values?.password,
);

async function handleSubmit() {
  const { valid } = await validate();
  if (valid) {
    if (props.verifyPassword ? await props.verifyPassword(form.values.password) : validPass.value) {
      accessStore.unlockScreen();
    } else {
      form.setFieldError('password', $t('authentication.passwordErrorTip'));
    }
  }
}

function toggleUnlockForm() {
  showUnlockForm.value = !showUnlockForm.value;
  if (showUnlockForm.value) {
    requestAnimationFrame(() => {
      getFieldComponentRef('password')
        ?.$el?.querySelector('[name="password"]')
        ?.focus();
    });
  }
}

useScrollLock();
</script>

<template>
  <div ref="screen" role="dialog" aria-modal="true" :aria-label="$t('ui.widgets.lockScreen.title')" tabindex="-1" class="fixed z-2000 size-full bg-background" @keydown.tab="trapFocus" @focusout="containFocus">
    <transition name="slide-left">
      <div v-show="!showUnlockForm" class="size-full">
        <button
          class="group fixed top-6 left-1/2 z-2001 flex-col-center -translate-x-1/2 cursor-pointer text-xl font-semibold text-foreground/80 hover:text-foreground"
          @click="toggleUnlockForm"
        >
          <LockKeyhole
            class="size-5 transition-all duration-300 group-hover:scale-125"
          />
          <span>{{ $t('ui.widgets.lockScreen.unlock') }}</span>
        </button>
        <div class="flex-center size-full">
          <div class="flex w-full justify-center gap-4 px-4 sm:gap-6 md:gap-8">
            <div
              class="relative flex-center h-35 w-35 rounded-xl bg-accent text-[36px] sm:h-40 sm:w-40 sm:text-[42px] md:h-50 md:w-50 md:text-[72px]"
            >
              <span
                class="absolute top-3 left-3 text-xs font-semibold sm:text-sm md:text-xl"
              >
                {{ meridiem }}
              </span>
              {{ hour }}
            </div>
            <div
              class="flex-center h-35 w-35 rounded-xl bg-accent text-[36px] sm:h-40 sm:w-40 sm:text-[42px] md:h-50 md:w-50 md:text-[72px]"
            >
              {{ minute }}
            </div>
          </div>
        </div>
      </div>
    </transition>

    <transition name="slide-right">
      <div
        v-if="showUnlockForm"
        class="flex-center size-full"
        @keydown.enter.prevent="handleSubmit"
      >
        <div class="mb-10 flex-col-center w-[90%] max-w-75 px-4">
          <VbenAvatar :src="avatar" class="enter-x mb-6 size-20" />
          <div class="enter-x mb-2 w-full items-center">
            <Form />
          </div>
          <VbenButton class="enter-x w-full" @click="handleSubmit">
            {{ $t('ui.widgets.lockScreen.entry') }}
          </VbenButton>
          <VbenButton
            class="enter-x my-2 w-full"
            variant="ghost"
            @click="$emit('toLogin')"
          >
            {{ $t('ui.widgets.lockScreen.backToLogin') }}
          </VbenButton>
          <VbenButton
            class="enter-x mr-2 w-full"
            variant="ghost"
            @click="toggleUnlockForm"
          >
            {{ $t('common.back') }}
          </VbenButton>
        </div>
      </div>
    </transition>

    <div
      class="enter-y absolute bottom-5 w-full text-center text-xl md:text-2xl xl:text-xl 2xl:text-3xl"
    >
      <div v-if="showUnlockForm" class="enter-x mb-2 text-2xl md:text-3xl">
        {{ hour }}:{{ minute }}
        <span class="text-base md:text-lg">{{ meridiem }}</span>
      </div>
      <div class="text-xl md:text-3xl">{{ date }}</div>
    </div>
  </div>
</template>
