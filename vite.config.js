/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig({
	sidebar: resolve(join('src', 'sidebar.js')),
}, {
	appName: 'archiefmeta',
	inlineCSS: true,
})
