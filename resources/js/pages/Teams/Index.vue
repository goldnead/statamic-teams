<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, CommandPaletteItem, EmptyStateMenu, EmptyStateItem,
    Icon, Stack, Heading, Field, Input, Select, DropdownItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'teams',      // [{ id, name, type, type_label, owner, members, invitations, join_method, read_only, created_at, show_url }]
    'columns',
    'types',      // [{ value, label }]
    'storeUrl',
    'wiringUrl',
    'canManage',
]);

const open = ref(false);
const name = ref('');
const type = ref(props.types[0]?.value ?? 'team');
const ownerEmail = ref('');
const saving = ref(false);
const errors = ref({});

function create() {
    saving.value = true;
    router.post(props.storeUrl, { name: name.value, type: type.value, owner_email: ownerEmail.value || null }, {
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { saving.value = false; },
    });
}

function reload() {
    router.reload({ only: ['teams'] });
}
</script>

<template>
    <Head :title="__('Teams')" />

    <div class="max-w-page mx-auto">
        <template v-if="teams.length === 0">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="users" class="size-5 text-gray-500" />{{ __('Teams') }}
                </h1>
            </header>
            <EmptyStateMenu :heading="__('A team is a workspace: a choir, a company, a household. Its members hold a role there, and access or a purchase can belong to the team instead of one person.')">
                <EmptyStateItem
                    v-if="canManage"
                    icon="users"
                    :heading="__('Create team')"
                    :description="__('No teams yet')"
                    @click="open = true"
                />
                <EmptyStateItem
                    :href="wiringUrl"
                    icon="node-connect"
                    :heading="__('Wiring')"
                    :description="__('Events, mails, automations and webhooks of this addon')"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('Teams')" icon="users">
                <Button :href="wiringUrl" :text="__('Wiring')" />
                <CommandPaletteItem v-if="canManage" :text="__('Create team')" category="actions" :action="() => (open = true)" />
                <Button v-if="canManage" :text="__('Create team')" variant="primary" @click="open = true" />
            </Header>

            <Listing
                :items="teams"
                :columns="columns"
                preferences-prefix="teams.index"
                @refreshing="reload"
            >
                <template #cell-name="{ row }">
                    <Link :href="row.show_url" class="font-medium hover:underline">{{ row.name }}</Link>
                    <Badge v-if="row.read_only" pill color="amber" :text="__('Read-only')" class="ms-2" />
                </template>
                <template #cell-owner="{ row }">
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ row.owner || '–' }}</span>
                </template>
                <template #cell-members="{ row }">
                    <span class="tabular-nums">{{ row.members }}</span>
                </template>
                <template #cell-invitations="{ row }">
                    <span class="tabular-nums">{{ row.invitations }}</span>
                </template>
                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('Open')" icon="eye" :href="row.show_url" />
                </template>
            </Listing>
        </template>

        <Stack v-model:open="open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="__('Create team')" size="lg" />
                </div>

                <div class="flex-1 space-y-5 px-6 py-5">
                    <Field :label="__('Name')" :error="errors.name" required>
                        <Input v-model="name" />
                    </Field>
                    <Field :label="__('Type')" :error="errors.type">
                        <Select v-model="type" :options="types" />
                    </Field>
                    <Field
                        :label="__('Owner')"
                        :error="errors.owner_email"
                        :instructions="__('Email address of an existing user. Leave empty and invite the owner later.')"
                    >
                        <Input v-model="ownerEmail" type="email" />
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Cancel')" variant="ghost" @click="open = false" />
                        <Button variant="primary" :text="__('Create')" :disabled="saving || !name" @click="create" />
                    </div>
                </div>
            </div>
        </Stack>
    </div>
</template>
