<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this app are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.1.0 - 2026-11-16

### Added

- Read-only "Archive" tab in the Files sidebar with classificatie, bewaartermijn, zorgdrager and identificatie, each with source and read time.
- S3 source: Object Lock retention and legal hold, user metadata and tags (`mdto-*`), for primary object storage and external S3 mounts.
- Administrator source through `occ archiefmeta:set`, and a Nextcloud file ID fallback for identificatie.
- Storage as core file metadata with `EDIT_FORBIDDEN`, behind `IMetadataStore` for the planned schema API switch.
- Commands `archiefmeta:refresh`, `archiefmeta:show` and `archiefmeta:set`.
- Read-only OCS endpoint `GET /api/v1/files/{fileId}`.
- English and Dutch translations.
