/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, t } from '@nextcloud/l10n'

/** Retention value the server uses for a legal hold without an end date. */
export const RETENTION_INDEFINITE = 'indefinite'

/**
 * The visible label of a field.
 *
 * @param {string} field MDTO element name
 * @return {string}
 */
export function fieldLabel(field) {
	switch (field) {
		case 'classificatie':
			return t('archiefmeta', 'Classification')
		case 'bewaartermijn':
			return t('archiefmeta', 'Retention period')
		case 'waardering':
			return t('archiefmeta', 'Appraisal')
		case 'informatiecategorie':
			return t('archiefmeta', 'Selection list category')
		case 'zorgdrager':
			return t('archiefmeta', 'Custodian')
		case 'identificatie':
			return t('archiefmeta', 'Identifier')
		default:
			return field
	}
}

/**
 * Formats a date and time string for display, or returns it unchanged when it is not a date.
 *
 * @param {string} value ISO 8601 string
 * @param {boolean} withTime include the time of day
 * @return {string}
 */
export function formatDate(value, withTime = false) {
	const date = new Date(value)
	if (!/^\d{4}-\d{2}-\d{2}/.test(value) || Number.isNaN(date.getTime())) {
		return value
	}
	const options = withTime
		? { dateStyle: 'long', timeStyle: 'short' }
		: { dateStyle: 'long', timeZone: 'UTC' }
	return new Intl.DateTimeFormat(getCanonicalLocale(), options).format(date)
}

/**
 * The displayed text of one value of a field. A begrip label is followed by its code.
 *
 * @param {string} field MDTO element name
 * @param {string} value raw value
 * @param {Record<string, string>} details details from the server
 * @return {string}
 */
export function formatValue(field, value, details = {}) {
	if (details.begripCode && details.begripCode !== value) {
		return `${value} (${details.begripCode})`
	}
	if (field !== 'bewaartermijn') {
		return value
	}
	if (value === RETENTION_INDEFINITE) {
		return t('archiefmeta', 'No end date (legal hold)')
	}
	return /^\d{4}-\d{2}-\d{2}/.test(value)
		? t('archiefmeta', 'Retain until {date}', { date: formatDate(value) })
		: value
}

/**
 * Readable lines for the source specific details of a value.
 *
 * @param {Record<string, string>} details details from the server
 * @return {string[]}
 */
export function formatDetails(details) {
	const lines = []
	if (details.mode) {
		const mode = details.mode === 'COMPLIANCE'
			? t('archiefmeta', 'compliance')
			: (details.mode === 'GOVERNANCE' ? t('archiefmeta', 'governance') : details.mode)
		lines.push(t('archiefmeta', 'Object Lock mode: {mode}', { mode }))
	}
	if (details.legalHold) {
		lines.push(details.legalHold === 'ON'
			? t('archiefmeta', 'Legal hold: on')
			: t('archiefmeta', 'Legal hold: off'))
	}
	if (details.begripBegrippenlijst) {
		lines.push(t('archiefmeta', 'List: {list}', { list: details.begripBegrippenlijst }))
	}
	if (details.kenmerkSysteem) {
		lines.push(t('archiefmeta', 'Issued by: {system}', { system: details.kenmerkSysteem }))
	}
	if (details.instanceId) {
		lines.push(t('archiefmeta', 'Nextcloud instance: {instance}', { instance: details.instanceId }))
	}
	if (details.setBy) {
		lines.push(t('archiefmeta', 'Set by: {who}', { who: details.setBy }))
	}
	return lines
}
