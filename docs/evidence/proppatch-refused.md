---
title: PROPPATCH refused
sidebar_position: 2
description: Live test of archiefmeta, 25 of 25 checks passed. Values from S3, every change refused.
---

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Read-only metadata: live test run

Output of `tests/Integration/live-test.sh` on 16 November 2026, run 1794822239, against Nextcloud 34.0.0 with S3 primary storage and an S3 external mount on MinIO with Object Lock. It shows that values come from the storage layer with their source, and that WebDAV PROPPATCH and the app API refuse every change, for the owner and for a share recipient with edit rights.

Nextcloud: http://127.0.0.1:8310 (34.0.0)

## 1. Primary object storage: Object Lock + object tags
PASS: upload to primary storage (HTTP 201)
fileid: 122, S3 key: vng-ol-primary/urn:oid:122
PASS: Object Lock retention read on upload, source s3-object-lock
PASS: identificatie falls back to the Nextcloud file ID
122 /admin/files/archiefmeta-it-1794822239/besluit.txt: 4 field(s) from s3-object-tags, s3-object-lock
Refreshed 1 file(s).
```json
{
    "fileId": 122,
    "fields": {
        "classificatie": {
            "values": [
                "Openbaar"
            ],
            "source": "s3-object-tags",
            "readAt": "2026-11-16T09:44:06+00:00",
            "details": []
        },
        "bewaartermijn": {
            "values": [
                "2026-11-17T09:44:02+00:00"
            ],
            "source": "s3-object-lock",
            "readAt": "2026-11-16T09:44:06+00:00",
            "details": {
                "legalHold": "ON",
                "mode": "GOVERNANCE"
            }
        },
        "zorgdrager": {
            "values": [
                "Gemeente Zuiddrecht",
                "Regionaal Archief Zuiddrecht"
            ],
            "source": "s3-object-tags",
            "readAt": "2026-11-16T09:44:06+00:00",
            "details": []
        },
        "identificatie": {
            "values": [
                "ZD-BESLUIT-1794822239"
            ],
            "source": "s3-object-tags",
            "readAt": "2026-11-16T09:44:06+00:00",
            "details": {
                "kenmerkSysteem": "Zaaksysteem"
            }
        }
    }
}
```
PASS: classificatie from S3 object tags
PASS: two zorgdragers
PASS: identificatie from the storage layer replaces the file ID
PASS: legal hold ON reported with retention
PASS: every value has a readAt time

## 2. External S3 mount: object written to the bucket with user metadata
124 /admin/files/ext-external/archiefmeta-it-1794822239/zaakdossier.txt: 4 field(s) from s3-object-metadata, s3-object-lock
Refreshed 1 file(s).
```json
{
    "fileId": 124,
    "fields": {
        "classificatie": {
            "values": [
                "Intern"
            ],
            "source": "s3-object-metadata",
            "readAt": "2026-11-16T09:44:10+00:00",
            "details": []
        },
        "bewaartermijn": {
            "values": [
                "2026-11-17T09:44:08+00:00"
            ],
            "source": "s3-object-lock",
            "readAt": "2026-11-16T09:44:10+00:00",
            "details": {
                "legalHold": "OFF",
                "mode": "GOVERNANCE"
            }
        },
        "zorgdrager": {
            "values": [
                "Gemeente Zuiddrecht"
            ],
            "source": "s3-object-metadata",
            "readAt": "2026-11-16T09:44:10+00:00",
            "details": []
        },
        "identificatie": {
            "values": [
                "ZD-DOSSIER-1794822239"
            ],
            "source": "s3-object-metadata",
            "readAt": "2026-11-16T09:44:10+00:00",
            "details": []
        }
    }
}
```
PASS: external mount: classificatie from S3 object metadata
PASS: external mount: retention from Object Lock

## 3. Administrator values on a file the storage layer has no values for
classificatie: Intern (administrator)
bewaartermijn: 2026-11-17T09:44:11+00:00 (s3-object-lock)
zorgdrager: Gemeente Zuiddrecht; Provincie Zuid (administrator)
identificatie: 125 (nextcloud-fileid)
PASS: administrator zorgdrager stored with source administrator
PASS: storage layer still wins for bewaartermijn

## 4. WebDAV PROPPATCH is refused
owner (admin) PROPPATCH set classificatie -> HTTP 500
```xml
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>OCP\FilesMetadata\Exceptions\FilesMetadataException</s:exception>
  <s:message>you do not have enough rights to update 'archiefmeta-classificatie' on this node</s:message>
</d:error>
```
PASS: owner PROPPATCH set refused
owner (admin) PROPPATCH remove bewaartermijn -> HTTP 500
```xml
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>OCP\FilesMetadata\Exceptions\FilesMetadataException</s:exception>
  <s:message>you do not have enough rights to update 'archiefmeta-bewaartermijn' on this node</s:message>
</d:error>
```
PASS: owner PROPPATCH remove refused
PASS: file shared with archieftest with edit rights (HTTP 200)
share recipient PROPPATCH set classificatie -> HTTP 500
```xml
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>OCP\FilesMetadata\Exceptions\FilesMetadataException</s:exception>
  <s:message>you do not have enough rights to update 'archiefmeta-classificatie' on this node</s:message>
</d:error>
```
PASS: share recipient PROPPATCH refused
share recipient PROPPATCH on a new archiefmeta key -> HTTP 500
PASS: PROPPATCH cannot add a new archiefmeta key
PASS: stored values unchanged after all PROPPATCH attempts

## 5. App API: read for users with access, no write endpoint
share recipient GET -> HTTP 200
```json
{"ocs":{"meta":{"status":"ok","statuscode":200,"message":"OK"},"data":{"fileId":122,"fields":[{"field":"classificatie","multiple":true,"value":{"values":["Openbaar"],"source":"s3-object-tags","readAt":"2026-11-16T09:44:06+00:00","details":[],"sourceLabel":"Storage layer (S3 object tags)","storageLayer":true}},{"field":"bewaartermijn","multiple":false,"value":{"values":["2026-11-17T09:44:02+00:00"],"source":"s3-object-lock","readAt":"2026-11-16T09:44:06+00:00","details":{"legalHold":"ON","mode":"GOVERNANCE"},"sourceLabel":"Storage layer (S3 Object Lock)","storageLayer":true}},{"field":"zorgdrager","multiple":true,"value":{"values":["Gemeente Zuiddrecht","Regionaal Archief Zuiddrecht"],"source":"s3-object-tags","readAt":"2026-11-16T09:44:06+00:00","details":[],"sourceLabel":"Storage layer (S3 object tags)","storageLayer":true}},{"field":"identificatie","multiple":false,"value":{"values":["ZD-BESLUIT-1794822239"],"source":"s3-object-tags","readAt":"2026-11-16T09:44:06+00:00","details":{"kenmerkSysteem":"Zaaksysteem"},"sourceLabel":"Storage layer (S3 object tags)","storageLayer":true}}]}}}
```
PASS: share recipient can read the values with source labels
GET on a file that is not shared with the user -> HTTP 404
PASS: file the user cannot access is not found
share recipient POST -> HTTP 405
PASS: POST on the app API is not possible
share recipient PUT -> HTTP 405
PASS: PUT on the app API is not possible
share recipient PATCH -> HTTP 405
PASS: PATCH on the app API is not possible
share recipient DELETE -> HTTP 405
PASS: DELETE on the app API is not possible
PASS: stored values unchanged after the API attempts

Demo files: admin/files/archiefmeta-it-1794822239 (fileids 122, 125) and admin/files/ext-external/archiefmeta-it-1794822239 (fileid 124).
Note: objects in the locked buckets stay until their retention ends; that is the point of the lock.

Result: 25 passed, 0 failed
