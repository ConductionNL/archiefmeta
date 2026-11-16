/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import ArchiveLockSvg from '@mdi/svg/svg/archive-lock-outline.svg?raw'
import { FileType, registerSidebarTab } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { isPublicShare } from '@nextcloud/sharing/public'
import { defineAsyncComponent, defineCustomElement } from 'vue'

const tagName = 'archiefmeta-sidebar-tab'

registerSidebarTab({
	id: 'archiefmeta',
	tagName,
	order: 95,
	displayName: t('archiefmeta', 'Archive'),
	iconSvgInline: ArchiveLockSvg,
	enabled({ node }) {
		return !isPublicShare() && node.type === FileType.File
	},

	async onInit() {
		const ArchiveTab = defineAsyncComponent(() => import('./views/ArchiveTab.vue'))
		window.customElements.define(tagName, defineCustomElement(ArchiveTab, {
			shadowRoot: false,
		}))
	},
})
