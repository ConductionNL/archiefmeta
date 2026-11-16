<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup>
import ArchiveLockSvg from '@mdi/svg/svg/archive-lock-outline.svg?raw'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { fieldLabel, formatDate, formatDetails, formatValue } from '../utils/format.js'

const props = defineProps({
	/** The file shown in the sidebar (INode from @nextcloud/files). */
	node: {
		type: Object,
		default: null,
	},

	/** Whether this tab is the active one. */
	active: {
		type: Boolean,
		default: false,
	},
})

const loading = ref(false)
const error = ref('')
const fields = ref([])

const fileId = computed(() => props.node?.fileid ?? null)
const hasValues = computed(() => fields.value.some((field) => field.value !== null))

/**
 * Loads the stored metadata of the current file.
 */
async function load() {
	if (fileId.value === null || !props.active) {
		return
	}
	const requested = fileId.value
	loading.value = true
	error.value = ''
	try {
		const { data } = await axios.get(generateOcsUrl('apps/archiefmeta/api/v1/files/{fileId}', { fileId: requested }))
		if (requested === fileId.value) {
			fields.value = data.ocs.data.fields
		}
	} catch {
		if (requested === fileId.value) {
			fields.value = []
			error.value = t('archiefmeta', 'The archival metadata could not be loaded.')
		}
	} finally {
		if (requested === fileId.value) {
			loading.value = false
		}
	}
}

watch([fileId, () => props.active], load, { immediate: true })
</script>

<template>
	<div class="archiefmeta-tab">
		<div v-if="loading" class="archiefmeta-tab__loading">
			<NcLoadingIcon :name="t('archiefmeta', 'Loading archival metadata')" :size="32" />
		</div>

		<NcNoteCard v-else-if="error" type="error" :text="error" />

		<NcEmptyContent
			v-else-if="!hasValues"
			:name="t('archiefmeta', 'No archival metadata yet')"
			:description="t('archiefmeta', 'Nothing has been read for this file yet. An administrator can read it with the command occ archiefmeta:refresh.')">
			<template #icon>
				<NcIconSvgWrapper :svg="ArchiveLockSvg" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard type="info">
				<p>
					<strong>{{ t('archiefmeta', 'Read only.') }}</strong>
					{{ t('archiefmeta', 'These values come from the storage layer or from an administrator. You cannot change them here.') }}
				</p>
			</NcNoteCard>

			<dl class="archiefmeta-tab__fields">
				<div v-for="item in fields" :key="item.field" class="archiefmeta-tab__field">
					<dt class="archiefmeta-tab__label">
						{{ fieldLabel(item.field) }}
						<span class="archiefmeta-tab__element" lang="nl">MDTO: {{ item.field }}</span>
					</dt>
					<dd v-if="item.value === null" class="archiefmeta-tab__value archiefmeta-tab__value--empty">
						{{ t('archiefmeta', 'No value found') }}
					</dd>
					<dd v-else class="archiefmeta-tab__value">
						<ul v-if="item.value.values.length > 1" class="archiefmeta-tab__list">
							<li v-for="value in item.value.values" :key="value">
								{{ formatValue(item.field, value) }}
							</li>
						</ul>
						<span v-else class="archiefmeta-tab__single">{{ formatValue(item.field, item.value.values[0]) }}</span>

						<p v-if="item.value.source === 'nextcloud-fileid'" class="archiefmeta-tab__warning">
							{{ t('archiefmeta', 'This is the Nextcloud file ID. It is not a durable identifier yet.') }}
						</p>
						<ul class="archiefmeta-tab__meta">
							<li>{{ t('archiefmeta', 'Source: {source}', { source: item.value.sourceLabel }) }}</li>
							<li>
								{{ t('archiefmeta', 'Read on:') }}
								<time :datetime="item.value.readAt">{{ formatDate(item.value.readAt, true) }}</time>
							</li>
							<li v-for="line in formatDetails(item.value.details)" :key="line">
								{{ line }}
							</li>
						</ul>
					</dd>
				</div>
			</dl>
		</template>
	</div>
</template>

<style scoped>
.archiefmeta-tab {
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.archiefmeta-tab__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
}

.archiefmeta-tab__fields {
	margin: 0;
}

.archiefmeta-tab__field {
	padding: calc(var(--default-grid-baseline) * 3) 0;
	border-bottom: 1px solid var(--color-border);
}

.archiefmeta-tab__field:last-child {
	border-bottom: none;
}

.archiefmeta-tab .archiefmeta-tab__fields .archiefmeta-tab__label {
	margin: 0;
	padding: 0;
	font-weight: bold;
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--default-grid-baseline);
}

.archiefmeta-tab__element {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.9em);
}

.archiefmeta-tab .archiefmeta-tab__fields .archiefmeta-tab__value {
	display: block;
	margin: var(--default-grid-baseline) 0 0;
	padding: 0;
}

.archiefmeta-tab__value p,
.archiefmeta-tab__value ul {
	margin-bottom: 0;
}

.archiefmeta-tab__value--empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.archiefmeta-tab__list {
	list-style: disc;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.archiefmeta-tab__warning {
	margin-top: var(--default-grid-baseline);
	font-style: italic;
}

.archiefmeta-tab__meta {
	margin-top: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.9em);
}
</style>
