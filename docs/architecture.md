<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Architecture

The app has three parts. Sources read values. A refresher picks one value per field. A store keeps the result and tells Nextcloud nobody may edit it.

```text
 file written / copied / moved          occ archiefmeta:refresh | :set
              │                                      │
              ▼                                      ▼
      RefreshOnWrite listener  ──────────►  MetadataRefresher
                                                 │  asks sources in order,
                                                 │  first value per field wins
              ┌──────────────────────────────────┼──────────────────────────┐
              ▼                                  ▼                          ▼
     S3MetadataSource                   AdministratorSource            FileIdSource
     (Object Lock, legal hold,          (values set with occ,          (Nextcloud file ID,
      object metadata, tags)             own table)                     not durable)
                                                 │
                                                 ▼
                                    IMetadataStore  ◄── one class to replace
                                    FilesMetadataStore (OCP\FilesMetadata, EDIT_FORBIDDEN)
                                                 │
                     ┌───────────────────────────┴───────────────┐
                     ▼                                           ▼
         MetadataController (GET only)                WebDAV nc:metadata-archiefmeta-*
                     │                                (PROPFIND reads, PROPPATCH refused)
                     ▼
         Files sidebar tab "Archive"
```

## Sources: `IMetadataSource`

```php
interface IMetadataSource {
	/** @return list<FieldValue> at most one value per field */
	public function read(Node $node, DateTimeImmutable $readAt): array;
}
```

A source only reads. It returns an empty list for a file it cannot handle, and it should not throw. The refresher still catches and logs errors, so one broken source never blocks the others or a file upload.

Each `FieldValue` holds the field, one or more values, the `Source` it came from, the read time and source details (Object Lock mode, legal hold, issuer of an identifier). The source is part of the value, so the tab and the API never show a value without its origin.

To add a source, such as a records register, write a class that implements the interface. Register it in `Application::register()` at the place in the order that matches its authority. Add a case to the `Source` enum for its label.

`S3ObjectLocator` finds the S3 object behind a file. It unwraps storage wrappers, jails and shares. Primary object storage uses the key `<objectPrefix><fileid>`. An external mount uses the path inside the mount. Core's S3 classes are private API, so the locator recognises them by their public methods (`getConnection`, `getBucket`, `getObjectStore`, `getURN`) and never by class name.

## Store: `IMetadataStore`

```php
interface IMetadataStore {
	public function registerFields(): void;              // declare the fields as read-only
	public function save(Node $node, array $values): void; // replace all values of a file
	public function load(int $fileId): array;            // list<FieldValue>
}
```

This interface is the only place that knows the platform's metadata API.

`FilesMetadataStore` is the current implementation. It uses `OCP\FilesMetadata\IFilesMetadataManager` (Nextcloud 28 and later):

- one key per field, `archiefmeta-<field>`, of type array, holding `{values, source, readAt, details}`;
- `initMetadata(…, EDIT_FORBIDDEN)` in a repair step on install and upgrade;
- `setEditPermission(…, EDIT_FORBIDDEN)` again on every save, because core rebuilds its list of known keys from saved metadata.

Core's WebDAV plugin compares the user's access level to the key's edit permission. `EDIT_FORBIDDEN` is 0 and the lowest access level is 1, so the check fails for every user. The values stay readable through PROPFIND as `nc:metadata-archiefmeta-<field>`.

## Planned switch to the schema API (nextcloud/server#66355)

Nextcloud 36 adds `OCP\FilesMetadata\Schema\IMetadataSchemaProvider`. An app declares typed fields there with `canRead` and `canEdit`. The pull request has no storage, user interface or WebDAV part of its own yet.

The switch is one new class, `SchemaMetadataStore implements IMetadataStore`:

1. `registerFields()` becomes a schema provider that declares the four fields, with `canEdit` returning false for every user.
2. `save()` and `load()` use whatever storage the schema API settles on.
3. `Application::register()` points the `IMetadataStore` alias at the new class when the schema API exists, and at `FilesMetadataStore` on older versions.

Sources, refresher, commands, API and sidebar tab stay as they are. The app does not depend on the unmerged pull request.

Two points from this app are worth raising on #66355:

- A value needs a source and a read time next to it. A typed field alone cannot say where a value came from.
- Values from a storage layer are read, not written. The schema API should allow a field whose value an app provides on read.

## Sidebar tab

`src/sidebar.js` registers the tab with `registerSidebarTab()` from `@nextcloud/files` 4. That is the sidebar API of Nextcloud 33 and later. The tab is a Vue 3 custom element, `archiefmeta-sidebar-tab`, loaded on first use. It reads `GET /ocs/v2.php/apps/archiefmeta/api/v1/files/{fileId}` and renders a description list. Every value has a text label for its source and read time, so no meaning depends on colour.

## Security

- The only route is a GET. It checks access through the user's own folder (`getUserFolder($uid)->getFirstNodeById()`) before reading anything. A file the user cannot reach answers 404.
- No source accepts input from a user. Values come from the storage layer or from `occ`.
- The S3 client is the one Nextcloud already uses for that storage, with the same credentials. The app adds no credentials of its own.
