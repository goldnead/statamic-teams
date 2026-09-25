<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, CommandPaletteItem, DropdownItem, Icon,
    ConfirmationModal, Field, Select, Description, Alert, EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'roles',       // [{ id, handle, title, source, all, protected, permissions, perm_0…, removed, usage: { members, invitations }, resettable, edit_url, reset_url, delete_url }]
    'columns',
    'permissions', // [{ value, label }], same order as the perm_<index> columns
    'ownerRole',
    'defaultRole',
    'createUrl',
]);

const errors = ref({});
const deleteTarget = ref(null);
const resetTarget = ref(null);
const reassignTo = ref(null);

const held = (row) => row ? row.usage.members + row.usage.invitations : 0;

// Where the holders of a deleted role can go: any other active role but
// the owner role (nobody is promoted by a deletion).
const targets = computed(() => props.roles
    .filter((r) => ! r.removed && r.handle !== props.ownerRole && r.handle !== deleteTarget.value?.handle)
    .map((r) => ({ value: r.handle, label: r.title })));

const sourceBadge = computed(() => ({
    customised: { color: 'amber', text: __('Changed') },
    cp: { color: 'blue', text: __('Created in the CP') },
    removed: { color: 'red', text: __('Deleted') },
}));

function openDelete(row) {
    errors.value = {};
    reassignTo.value = held(row) > 0 ? (row.handle === props.defaultRole ? null : props.defaultRole) : null;
    deleteTarget.value = row;
}

function destroy() {
    router.delete(deleteTarget.value.delete_url, {
        data: reassignTo.value ? { reassign_to: reassignTo.value } : {},
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; deleteTarget.value = null; },
    });
}

function reset() {
    router.post(resetTarget.value.reset_url, {}, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { resetTarget.value = null; },
    });
}

function reload() {
    router.reload({ only: ['roles'] });
}
</script>

<template>
    <Head :title="[__('Roles'), __('Teams')]" />

    <div class="max-w-page mx-auto">
        <template v-if="roles.length === 0">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="users" class="size-5 text-gray-500" />{{ __('Roles') }}
                </h1>
            </header>
            <EmptyStateMenu :heading="__('A role bundles what a member may do in their team. Every team uses these roles; a team can add its own on its page.')">
                <EmptyStateItem :href="createUrl" icon="users" :heading="__('Create role')" :description="__('No roles yet')" />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('Roles')" icon="users">
                <CommandPaletteItem :text="__('Create role')" category="actions" :action="() => router.visit(createUrl)" />
                <Button :href="createUrl" :text="__('Create role')" variant="primary" />
            </Header>

            <Alert v-if="errors.role" variant="error" :text="errors.role" class="mb-4" />

            <Listing
                :items="roles"
                :columns="columns"
                preferences-prefix="teams.roles"
                :allow-presets="false"
                :allow-search="roles.length > 10"
                @refreshing="reload"
            >
                <template #cell-title="{ row }">
                    <Link v-if="row.edit_url" :href="row.edit_url" class="font-medium hover:underline">{{ row.title }}</Link>
                    <span v-else class="font-medium text-gray-500 line-through">{{ row.title }}</span>
                    <Badge v-if="row.handle === ownerRole" pill color="green" :text="__('Owner role')" class="ms-2" />
                    <Badge v-else-if="row.handle === defaultRole" pill :text="__('Default role')" class="ms-2" />
                    <Badge v-if="sourceBadge[row.source]" pill :color="sourceBadge[row.source].color" :text="sourceBadge[row.source].text" class="ms-2" />
                </template>
                <template v-for="(permission, index) in permissions" :key="permission.value" #[`cell-perm_${index}`]="{ row }">
                    <Icon v-if="row[`perm_${index}`] && ! row.removed" name="checkmark" class="size-4 text-green-600 dark:text-green-400" :aria-label="permission.label" />
                    <span v-else class="text-gray-300 dark:text-gray-600" aria-hidden="true">–</span>
                </template>
                <template #cell-members="{ row }">
                    <span class="tabular-nums">{{ row.usage.members }}</span>
                </template>
                <template #prepended-row-actions="{ row }">
                    <DropdownItem v-if="row.edit_url" :text="__('Edit')" icon="edit" :href="row.edit_url" />
                    <DropdownItem v-if="row.resettable" :text="__('Reset to default')" icon="history" @click="resetTarget = row" />
                    <DropdownItem v-if="row.delete_url && ! row.protected" :text="__('Delete')" icon="trash" variant="destructive" @click="openDelete(row)" />
                </template>
            </Listing>

            <Description class="mt-4" :text="__('The owner role always holds every permission. The owner role and the default role for new members cannot be deleted. A team can adjust any role for itself on its page.')" />
        </template>

        <ConfirmationModal
            :open="deleteTarget !== null && held(deleteTarget) > 0"
            :title="__('Move members first')"
            danger
            :disabled="!reassignTo"
            :button-text="__('Move and delete')"
            @cancel="deleteTarget = null"
            @confirm="destroy"
        >
            <div v-if="deleteTarget" class="space-y-5">
                <Description :text="__(':members members and :invitations open invitations hold the role :role. Choose the role they get instead, then the role is deleted.', { members: deleteTarget.usage.members, invitations: deleteTarget.usage.invitations, role: deleteTarget.title })" />
                <Field :label="__('Move to')" :error="errors.role">
                    <Select v-model="reassignTo" :options="targets" :placeholder="__('Choose a role')" />
                </Field>
            </div>
        </ConfirmationModal>

        <ConfirmationModal
            :open="deleteTarget !== null && held(deleteTarget) === 0"
            :title="__('Delete role')"
            :body-text="__('Nobody holds this role. Delete it?')"
            danger
            :button-text="__('Delete')"
            @cancel="deleteTarget = null"
            @confirm="destroy"
        />

        <ConfirmationModal
            :open="resetTarget !== null"
            :title="__('Reset to default')"
            :body-text="__('Name and permissions go back to what the configuration says. Members keep the role.')"
            :button-text="__('Reset')"
            @cancel="resetTarget = null"
            @confirm="reset"
        />
    </div>
</template>
