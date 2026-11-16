---
sidebar_position: 2
description: A ten-minute demo of archiefmeta for a live audience.
---

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Demo script

About ten minutes. You need a Nextcloud with S3 primary storage and Object Lock, the MinIO client `mc` with an alias `s3`, and two users: an owner and a colleague.

## 1. A file gets its retention from the storage layer

1. Log in as the owner and upload `besluit.txt`.
2. Open the sidebar and choose the **Archive** tab.
3. Point at **Retention period**. The date comes from S3 Object Lock. The source line says so, and the read time is the upload time.
4. Point at **Identifier**. It shows the Nextcloud file ID and says it is not durable yet.

## 2. The storage layer adds classification and custodians

```bash
occ archiefmeta:show <owner>/files/besluit.txt --json      # note the fileid
mc tag set s3/<bucket>/urn:oid:<fileid> \
  "mdto-classificatie=Openbaar&mdto-zorgdrager-1=Gemeente Zuiddrecht&mdto-zorgdrager-2=Regionaal Archief&mdto-identificatie=ZD-2026-0001"
mc legalhold set s3/<bucket>/urn:oid:<fileid>
occ archiefmeta:refresh <fileid>
```

Reload the tab. Classification, two custodians, the identifier and "Legal hold: on" appear. Each line names the storage layer as source.

## 3. Nobody can change it

1. Share the file with the colleague, with edit rights.
2. As the colleague, open the Archive tab. The values are visible. There is nothing to edit.
3. Try WebDAV:

```bash
curl -u colleague:… -X PROPPATCH \
  --data '<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:set><d:prop><nc:metadata-archiefmeta-classificatie>Geheim</nc:metadata-archiefmeta-classificatie></d:prop></d:set></d:propertyupdate>' \
  https://<host>/remote.php/dav/files/colleague/besluit.txt
```

Nextcloud refuses: "you do not have enough rights to update 'archiefmeta-classificatie' on this node". The owner gets the same answer.

## 4. Language

Switch the colleague to Dutch. The tab is called **Archief** and the labels follow MDTO: Classificatie, Bewaartermijn, Zorgdrager, Identificatie.

Clean up: lift the legal hold with `mc legalhold clear`. The default retention keeps the object until it ends.
