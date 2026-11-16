/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		ignores: ['js/', 'vendor/', 'node_modules/', 'l10n/'],
	},
]
