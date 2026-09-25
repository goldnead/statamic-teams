<script setup>
import { computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Badge, Button, Description, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';

/**
 * What is wired to this addon's events: the mail each one sends, and how
 * many automations and webhooks listen. The catalogue of triggers itself
 * lives in automations and the webhook manager; this page links there
 * instead of copying it.
 */
const props = defineProps([
    'events',        // [{ handle, label, description, mail: { key, slug, enabled, customised, edit_url, variables } | null, automations, webhooks }]
    'integrations',  // { email_templates: { installed, url }, automations: {…}, webhook_manager: {…}, activity: {…} }
    'installTemplatesUrl',
    'canManage',
]);

const siblings = computed(() => [
    { key: 'email_templates', label: __('Email templates'), text: __('Every mail of this addon is a template there.') },
    { key: 'automations', label: __('teams::cp.automations'), text: __('Every event below is a trigger in the flow builder, group "Teams".') },
    { key: 'webhook_manager', label: __('teams::cp.webhook_manager'), text: __('Every event below is a webhook trigger.') },
    { key: 'activity', label: __('teams::cp.activity'), text:__('Every event is written to the activity log, subject team.') },
]);

const missingTemplates = computed(() => props.events.some((e) => e.mail && ! e.mail.customised));

function installTemplates() {
    router.post(props.installTemplatesUrl, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Wiring'), __('Teams')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Wiring')" icon="node-connect">
            <Button
                v-if="canManage && integrations.email_templates.installed && missingTemplates"
                :text="__('Write mail templates')"
                variant="primary"
                @click="installTemplates"
            />
        </Header>

        <Panel :heading="__('Connected addons')">
            <Card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="sibling in siblings" :key="sibling.key" class="flex items-start gap-3" :data-integration="sibling.key">
                        <Badge
                            pill
                            :color="integrations[sibling.key].installed ? 'green' : 'default'"
                            :text="integrations[sibling.key].installed ? __('Installed') : __('Not installed')"
                        />
                        <div>
                            <a v-if="integrations[sibling.key].installed && integrations[sibling.key].url" :href="integrations[sibling.key].url" class="font-medium text-ui-accent-text underline">{{ sibling.label }}</a>
                            <span v-else class="font-medium">{{ sibling.label }}</span>
                            <Description :text="sibling.text" />
                        </div>
                    </div>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('teams::cp.events')" :subheading="__('Handle, mail, and who listens. Counts include enabled flows and webhooks only.')">
            <Card>
            <Table>
                <TableColumns>
                    <TableColumn>{{ __('Event') }}</TableColumn>
                    <TableColumn>{{ __('Mail') }}</TableColumn>
                    <TableColumn class="text-end">{{ __('teams::cp.automations') }}</TableColumn>
                    <TableColumn class="text-end">{{ __('Webhooks') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="event in events" :key="event.handle" :data-event="event.handle">
                        <TableCell>
                            <div class="font-medium">{{ event.label }}</div>
                            <div class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ event.handle }}</div>
                            <Description :text="event.description" class="mt-1" />
                        </TableCell>
                        <TableCell>
                            <template v-if="event.mail">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge pill :color="event.mail.enabled ? 'green' : 'default'" :text="event.mail.enabled ? __('Mail is sent') : __('Off')" />
                                    <a v-if="event.mail.edit_url" :href="event.mail.edit_url" class="font-mono text-xs text-ui-accent-text underline">{{ event.mail.slug }}</a>
                                    <span v-else class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ event.mail.slug }}</span>
                                    <Badge v-if="!event.mail.customised" pill color="amber" :text="__('Default text')" />
                                </div>
                                <div class="mt-1 font-mono text-2xs text-gray-500 dark:text-gray-400">
                                    <span v-for="variable in event.mail.variables" :key="variable" class="me-2" v-text="'{{ ' + variable + ' }}'"></span>
                                </div>
                            </template>
                            <span v-else class="text-gray-400">–</span>
                        </TableCell>
                        <TableCell class="text-end tabular-nums">
                            <a v-if="integrations.automations.installed && integrations.automations.url" :href="integrations.automations.url" class="text-ui-accent-text underline">{{ event.automations }}</a>
                            <span v-else class="text-gray-400">–</span>
                        </TableCell>
                        <TableCell class="text-end tabular-nums">
                            <a v-if="integrations.webhook_manager.installed && integrations.webhook_manager.url" :href="integrations.webhook_manager.url" class="text-ui-accent-text underline">{{ event.webhooks }}</a>
                            <span v-else class="text-gray-400">–</span>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
            </Card>
        </Panel>
    </div>
</template>
