<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Badge, Button, Dropdown, DropdownMenu, DropdownItem,
    Field, Input, Select, Switch, Listing, Modal, ConfirmationModal, CommandPaletteItem, Description,
    Stack, Heading, Checkbox, CheckboxGroup,
} from '@statamic/cms/ui';

const props = defineProps([
    'team',               // { id, uuid, name, type, type_label, owner, join_method, join_code, read_only, is_personal, billing, created_at }
    'members',            // [{ membership_id, id, email, name, role, role_label, meta, joined_at, update_url, delete_url }]
    'memberColumns',
    'invitations',        // [{ id, email, role, role_label, status, expires_on, resend_url, delete_url }]
    'invitationColumns',
    'roles',              // [{ value, label }]
    'metaLabels',         // { voice_part: 'Stimmgruppe', … } from teams.meta_labels
    'metaValueLabels',    // { voice_part: { bass: 'Bass', … } } from teams.meta_value_labels
    'vatIdChecked',       // bool: statamic-invoices checks VAT IDs at the checkout
    'entitlementSubject', // 'team:<id>'
    'teamRoles',          // [{ id, handle, label, scope, overrides_global, permissions, permission_labels, permission_count, all, owner, members, usage, update_url, delete_url }]
    'roleColumns',
    'permissions',        // [{ value, label }]
    'canManageRoles',
    'rolesPageUrl',
    'urls',
    'canManage',
]);

// Roles of this team
const ownRoles = computed(() => props.teamRoles.filter((r) => r.scope === 'team'));
const roleEditor = ref(null);      // { mode: 'create' | 'adjust' | 'edit', handle, label, permissions, url }
const roleDelete = ref(null);
const roleReassign = ref(null);
const roleHeld = (row) => row ? row.usage.members + row.usage.invitations : 0;
const roleTargets = computed(() => props.teamRoles
    .filter((r) => ! r.owner && r.handle !== roleDelete.value?.handle)
    .map((r) => ({ value: r.handle, label: r.label })));

function openRoleCreate() {
    errors.value = {};
    roleEditor.value = { mode: 'create', handle: '', label: '', permissions: [], url: props.urls.roles };
}

function openRoleEdit(row) {
    errors.value = {};
    roleEditor.value = row.scope === 'team'
        ? { mode: 'edit', handle: row.handle, label: row.label, permissions: [...row.permissions], url: row.update_url }
        // A global role adjusted for this team: a team role of the same handle.
        : { mode: 'adjust', handle: row.handle, label: row.label, permissions: [...row.permissions], url: props.urls.roles };
}

function saveRole() {
    const role = roleEditor.value;
    const payload = { label: role.label, permissions: role.permissions };
    const done = { ...options, onSuccess: () => { errors.value = {}; roleEditor.value = null; } };

    if (role.mode === 'edit') {
        router.patch(role.url, payload, done);
    } else {
        router.post(role.url, { ...payload, handle: role.handle }, done);
    }
}

function openRoleDelete(row) {
    errors.value = {};
    roleReassign.value = null;
    roleDelete.value = row;
}

function deleteRole() {
    router.delete(roleDelete.value.delete_url, {
        ...options,
        data: roleReassign.value ? { reassign_to: roleReassign.value } : {},
        onSuccess: () => { errors.value = {}; roleDelete.value = null; },
    });
}

const errors = ref({});
// Errors a field shows itself stay out of the banner; everything else
// (last owner, a refused role change) goes up top.
const fieldKeys = ['name', 'email', 'handle', 'label', 'permissions'];
const generalErrors = computed(() => Object.entries(errors.value)
    .filter(([key]) => ! fieldKeys.includes(key) && ! key.startsWith('billing'))
    .map(([, message]) => message));

// Details
const name = ref(props.team.name);
const joinMethod = ref(props.team.join_method);
const readOnly = ref(!! props.team.read_only);
const joinMethods = computed(() => [
    { value: 'invitation_only', label: __('Invitation only') },
    { value: 'join_code', label: __('Invitation or join code') },
]);

// Billing address: the fields payments reads (see README, "Payments").
const billingFields = ['company', 'name', 'email', 'line1', 'line2', 'postal_code', 'city', 'country', 'vat_id'];
const billing = ref(Object.fromEntries(billingFields.map((key) => [key, props.team.billing?.[key] ?? ''])));

// Invite
const inviteOpen = ref(false);
const inviteEmail = ref('');
const inviteRole = ref(props.roles.find((r) => r.value === 'member')?.value ?? props.roles[0]?.value);
const invitableRoles = computed(() => props.roles.filter((r) => r.value !== 'owner'));

// Role change, removal, deletion
const roleTarget = ref(null);
const newRole = ref(null);
const removeTarget = ref(null);
const revokeTarget = ref(null);
const deleteOpen = ref(false);

const options = {
    preserveScroll: true,
    onError: (e) => { errors.value = e || {}; },
    onSuccess: () => { errors.value = {}; },
};

function saveDetails() {
    router.patch(props.urls.update, { name: name.value, join_method: joinMethod.value, read_only: readOnly.value }, options);
}

function saveBilling() {
    router.patch(props.urls.update, { billing: billing.value }, options);
}

function invite() {
    router.post(props.urls.invite, { email: inviteEmail.value, role: inviteRole.value }, {
        ...options,
        onSuccess: () => { errors.value = {}; inviteOpen.value = false; inviteEmail.value = ''; },
    });
}

function openRoleChange(row) {
    roleTarget.value = row;
    newRole.value = row.role;
}

function changeRole() {
    router.patch(roleTarget.value.update_url, { role: newRole.value }, { ...options, onFinish: () => { roleTarget.value = null; } });
}

function removeMember() {
    router.delete(removeTarget.value.delete_url, { ...options, onFinish: () => { removeTarget.value = null; } });
}

function resend(row) {
    router.post(row.resend_url, {}, options);
}

function revoke() {
    router.delete(revokeTarget.value.delete_url, { ...options, onFinish: () => { revokeTarget.value = null; } });
}

function regenerateCode() {
    router.post(props.urls.joinCode, {}, options);
}

function destroy() {
    router.delete(props.urls.destroy, options);
}

const statusColor = { pending: 'blue', accepted: 'green', revoked: 'default', expired: 'amber' };
const statusLabel = computed(() => ({
    pending: __('Open'),
    accepted: __('Accepted'),
    revoked: __('Withdrawn'),
    expired: __('Expired'),
}));

function reload() {
    router.reload({ only: ['members', 'invitations'] });
}
</script>

<template>
    <Head :title="[team.name, __('Teams')]" />

    <div class="max-w-page mx-auto">
        <Header :title="team.name" icon="users">
            <Badge pill :text="team.type_label" />
            <Badge v-if="readOnly" pill color="amber" :text="__('Read-only')" />
            <Dropdown v-if="canManage">
                <DropdownMenu>
                    <DropdownItem
                        v-if="team.join_method === 'join_code'"
                        :text="__('New join code')"
                        icon="key"
                        @click="regenerateCode"
                    />
                    <DropdownItem :text="__('Delete team')" icon="trash" variant="destructive" @click="deleteOpen = true" />
                </DropdownMenu>
            </Dropdown>
            <CommandPaletteItem v-if="canManage && !team.is_personal" :text="__('Invite')" category="actions" :action="() => (inviteOpen = true)" />
            <Button v-if="canManage && !team.is_personal" :text="__('Invite')" variant="primary" @click="inviteOpen = true" />
        </Header>

        <Alert v-for="(message, index) in generalErrors" :key="index" variant="error" :text="message" class="mb-4" />

        <Panel :heading="__('Details')">
            <template v-if="canManage" #header-actions>
                <Button :text="__('Save')" size="sm" @click="saveDetails" />
            </template>
            <Card>
                <div class="grid gap-5 sm:grid-cols-2">
                    <Field :label="__('Name')" :error="errors.name">
                        <Input v-model="name" :read-only="!canManage" />
                    </Field>
                    <Field :label="__('Joining')" :instructions="__('With a join code, anyone who has the code can join as a member.')">
                        <Select v-model="joinMethod" :options="joinMethods" :read-only="!canManage || team.is_personal" />
                    </Field>
                    <Field :label="__('Join code')" :instructions="__('Read aloud or written on paper. Case and spaces do not matter.')">
                        <Input :model-value="team.join_code || '–'" read-only copyable />
                    </Field>
                    <Field :label="__('Read-only')" :instructions="__('Members can still read, but every change is refused (423).')">
                        <Switch v-model="readOnly" :disabled="!canManage" />
                    </Field>
                    <Field :label="__('Owner')">
                        <Input :model-value="team.owner ? `${team.owner.name} (${team.owner.email})` : '–'" read-only />
                    </Field>
                    <Field :label="__('Entitlement subject')" :instructions="__('Access granted to this subject holds for every member.')">
                        <Input :model-value="entitlementSubject" read-only copyable />
                    </Field>
                    <Field :label="__('UUID')">
                        <Input :model-value="team.uuid" read-only copyable />
                    </Field>
                    <Field :label="__('Created')">
                        <Input :model-value="team.created_at" read-only />
                    </Field>
                </div>
            </Card>
        </Panel>

        <Panel :heading="`${__('Members')} (${members.length})`">
            <Listing
                :items="members"
                :columns="memberColumns"
                preferences-prefix="teams.members"
                :allow-presets="false"
                :allow-customizing-columns="false"
                :allow-search="members.length > 10"
                @refreshing="reload"
            >
                <template #cell-name="{ row }">
                    <span class="font-medium">{{ row.name || row.id }}</span>
                    <Badge v-for="(value, key) in row.meta" :key="key" pill :text="`${metaLabels[key] ?? key}: ${metaValueLabels?.[key]?.[value] ?? value}`" class="ms-2" />
                </template>
                <template #cell-role_label="{ row }">
                    <Badge pill :color="row.role === 'owner' ? 'green' : 'default'" :text="row.role_label" />
                </template>
                <template v-if="canManage" #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('Change role')" icon="users" @click="openRoleChange(row)" />
                    <DropdownItem :text="__('Remove from team')" icon="trash" variant="destructive" @click="removeTarget = row" />
                </template>
            </Listing>
        </Panel>

        <Panel :heading="__('Roles')" :subheading="ownRoles.length === 0 ? __('This team uses the global roles only. Add a role of its own, or adjust a global role for this team.') : null">
            <template v-if="canManageRoles" #header-actions>
                <Button v-if="rolesPageUrl" :href="rolesPageUrl" :text="__('Global roles')" size="sm" variant="ghost" />
                <Button :text="__('Create role')" size="sm" @click="openRoleCreate" />
            </template>
            <Listing
                :items="teamRoles"
                :columns="roleColumns"
                preferences-prefix="teams.team-roles"
                :allow-presets="false"
                :allow-customizing-columns="false"
                :allow-search="false"
                @refreshing="reload"
            >
                <template #cell-label="{ row }">
                    <span class="font-medium">{{ row.label }}</span>
                </template>
                <template #cell-scope="{ row }">
                    <Badge v-if="row.scope === 'team'" pill color="blue" :text="row.overrides_global ? __('This team, replaces global') : __('This team')" />
                    <Badge v-else pill :text="__('Global')" />
                </template>
                <template #cell-permission_count="{ row }">
                    <span class="text-sm text-gray-600 dark:text-gray-400" :title="row.permission_labels.join(', ')">
                        {{ row.all ? __('All permissions') : (row.permission_labels.join(', ') || '–') }}
                    </span>
                </template>
                <template #cell-members="{ row }">
                    <span class="tabular-nums">{{ row.members }}</span>
                </template>
                <template v-if="canManageRoles" #prepended-row-actions="{ row }">
                    <DropdownItem v-if="row.scope === 'team'" :text="__('Edit')" icon="edit" @click="openRoleEdit(row)" />
                    <DropdownItem v-else-if="! row.owner" :text="__('Adjust for this team')" icon="edit" @click="openRoleEdit(row)" />
                    <DropdownItem
                        v-if="row.delete_url"
                        :text="row.overrides_global ? __('Back to the global role') : __('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="openRoleDelete(row)"
                    />
                </template>
            </Listing>
        </Panel>

        <Panel :heading="__('Invitations')">
            <Card v-if="invitations.length === 0">
                <Description :text="team.is_personal ? __('Nobody can be invited into a personal team.') : __('No invitations yet. Whoever is invited gets a mail with a link; they join once they accept it.')" />
            </Card>
            <Listing
                v-else
                :items="invitations"
                :columns="invitationColumns"
                preferences-prefix="teams.invitations"
                :allow-presets="false"
                :allow-customizing-columns="false"
                :allow-search="false"
                @refreshing="reload"
            >
                <template #cell-expires_on="{ row }">
                    <span v-if="row.expires_on">{{ row.expires_on }}</span>
                    <span v-else class="text-gray-400">–</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge pill :color="statusColor[row.status]" :text="statusLabel[row.status]" />
                </template>
                <template v-if="canManage" #prepended-row-actions="{ row }">
                    <DropdownItem v-if="row.status !== 'accepted'" :text="__('Send again')" icon="mail" @click="resend(row)" />
                    <DropdownItem v-if="row.status === 'pending'" :text="__('Withdraw')" icon="trash" variant="destructive" @click="revokeTarget = row" />
                </template>
            </Listing>
        </Panel>

        <Panel :heading="__('Billing address')" :subheading="__('Used when the team buys: the invoice goes to the team, not to the person who pays.')">
            <template v-if="canManage" #header-actions>
                <Button :text="__('Save')" size="sm" @click="saveBilling" />
            </template>
            <Card>
                <div class="grid gap-5 sm:grid-cols-2">
                    <Field :label="__('Organisation')" :error="errors['billing.company']"><Input v-model="billing.company" :read-only="!canManage" /></Field>
                    <Field :label="__('Contact person')" :error="errors['billing.name']"><Input v-model="billing.name" :read-only="!canManage" /></Field>
                    <Field :label="__('Invoice email')" :error="errors['billing.email']"><Input v-model="billing.email" type="email" :read-only="!canManage" /></Field>
                    <Field
                        :label="__('VAT ID')"
                        :badge="__('Not verified')"
                        :instructions="vatIdChecked
                            ? __('Not verified here. Checked at each purchase (VIES, via Invoices), and the result is printed on the invoice.')
                            : __('Not verified. Without the Invoices addon the number goes onto the purchase as entered.')"
                        :error="errors['billing.vat_id']"
                    ><Input v-model="billing.vat_id" :read-only="!canManage" /></Field>
                    <Field :label="__('Street')" :error="errors['billing.line1']"><Input v-model="billing.line1" :read-only="!canManage" /></Field>
                    <Field :label="__('Address line 2')" :error="errors['billing.line2']"><Input v-model="billing.line2" :read-only="!canManage" /></Field>
                    <Field :label="__('Postal code')" :error="errors['billing.postal_code']"><Input v-model="billing.postal_code" :read-only="!canManage" /></Field>
                    <Field :label="__('City')" :error="errors['billing.city']"><Input v-model="billing.city" :read-only="!canManage" /></Field>
                    <Field :label="__('Country')" :instructions="__('Two letters, e.g. DE.')" :error="errors['billing.country']"><Input v-model="billing.country" :read-only="!canManage" /></Field>
                </div>
            </Card>
        </Panel>

        <Modal v-model:open="inviteOpen" :title="__('Invite to :team', { team: team.name })" icon="mail">
            <div class="space-y-5">
                <Field :label="__('Email')" :error="errors.email" required>
                    <Input v-model="inviteEmail" type="email" />
                </Field>
                <Field :label="__('Role')" :error="errors.role">
                    <Select v-model="inviteRole" :options="invitableRoles" />
                </Field>
                <Description :text="__('The invitation is only valid for this address. Nobody joins without accepting it.')" />
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button :text="__('Cancel')" variant="ghost" @click="inviteOpen = false" />
                    <Button :text="__('Send invitation')" variant="primary" :disabled="!inviteEmail" @click="invite" />
                </div>
            </template>
        </Modal>

        <Modal :open="roleTarget !== null" :title="__('Change role')" icon="users" @update:open="(v) => { if (!v) roleTarget = null; }">
            <Field v-if="roleTarget" :label="roleTarget.name || roleTarget.email">
                <Select v-model="newRole" :options="roles" />
            </Field>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button :text="__('Cancel')" variant="ghost" @click="roleTarget = null" />
                    <Button :text="__('Save')" variant="primary" @click="changeRole" />
                </div>
            </template>
        </Modal>

        <Stack :open="roleEditor !== null" size="narrow" @update:open="(v) => { if (!v) roleEditor = null; }">
            <div v-if="roleEditor" class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading
                        :text="roleEditor.mode === 'create' ? __('Create role') : (roleEditor.mode === 'adjust' ? __('Adjust :role for this team', { role: roleEditor.label }) : roleEditor.label)"
                        size="lg"
                    />
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <Description v-if="roleEditor.mode === 'adjust'" :text="__('The team gets its own version of this role. Members holding it here follow the new permissions; other teams keep the global role.')" />
                    <Field :label="__('Name')" :error="errors.label" required>
                        <Input v-model="roleEditor.label" />
                    </Field>
                    <Field
                        :label="__('Handle')"
                        :error="errors.handle"
                        :instructions="roleEditor.mode === 'create' ? __('Lowercase letters, digits, _ and -.') : __('Members and invitations refer to the role by its handle, so it stays.')"
                        required
                    >
                        <Input v-model="roleEditor.handle" :read-only="roleEditor.mode !== 'create'" />
                    </Field>
                    <Field
                        :label="__('Role permissions')"
                        :error="errors.permissions"
                        :instructions="__('What a member holding this role may do in this team.')"
                    >
                        <CheckboxGroup v-model="roleEditor.permissions">
                            <Checkbox v-for="permission in permissions" :key="permission.value" :value="permission.value" :label="permission.label" />
                        </CheckboxGroup>
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Cancel')" variant="ghost" @click="roleEditor = null" />
                        <Button variant="primary" :text="__('Save')" :disabled="!roleEditor.label || !roleEditor.handle" @click="saveRole" />
                    </div>
                </div>
            </div>
        </Stack>

        <ConfirmationModal
            :open="roleDelete !== null && roleHeld(roleDelete) > 0"
            :title="__('Move members first')"
            danger
            :disabled="!roleReassign"
            :button-text="__('Move and delete')"
            @cancel="roleDelete = null"
            @confirm="deleteRole"
        >
            <div v-if="roleDelete" class="space-y-5">
                <Description :text="__('The role :role is still held. Members: :members, open invitations: :invitations. Choose the role they get instead, then the role is deleted.', { members: roleDelete.usage.members, invitations: roleDelete.usage.invitations, role: roleDelete.label })" />
                <Field :label="__('Move to')" :error="errors.role">
                    <Select v-model="roleReassign" :options="roleTargets" :placeholder="__('Choose a role')" />
                </Field>
            </div>
        </ConfirmationModal>

        <ConfirmationModal
            :open="roleDelete !== null && roleHeld(roleDelete) === 0"
            :title="roleDelete?.overrides_global ? __('Back to the global role') : __('Delete role')"
            :body-text="roleDelete?.overrides_global ? __('The team version is deleted. Members holding this role get the global role again.') : __('Nobody holds this role. Delete it?')"
            danger
            :button-text="__('Delete')"
            @cancel="roleDelete = null"
            @confirm="deleteRole"
        />

        <ConfirmationModal
            :open="removeTarget !== null"
            :title="__('Remove from team')"
            :body-text="__('This person loses the team and everything they could use through it. They get a mail about it.')"
            danger
            :button-text="__('Remove')"
            @cancel="removeTarget = null"
            @confirm="removeMember"
        />

        <ConfirmationModal
            :open="revokeTarget !== null"
            :title="__('Withdraw invitation')"
            :body-text="__('The link in the mail stops working.')"
            danger
            :button-text="__('Withdraw')"
            @cancel="revokeTarget = null"
            @confirm="revoke"
        />

        <ConfirmationModal
            :open="deleteOpen"
            :title="__('Delete team')"
            :body-text="__('Delete this team with its members and invitations? Access granted to the team ends for everyone. This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="deleteOpen = false"
            @confirm="destroy"
        />
    </div>
</template>
