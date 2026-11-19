---
title: S3 Object Lock deletes
sidebar_position: 1
description: What Nextcloud 34 does when it deletes a file on an S3 bucket with Object Lock or a delete-denying policy. Evidence for nextcloud/server#66498.
---

# S3 Object Lock deletes

Evidence for [nextcloud/server#66498](https://github.com/nextcloud/server/issues/66498): what Nextcloud does when the storage layer should refuse a delete. Tested on 16 November 2026 against Nextcloud 34.0.0.12 with MinIO. The delete paths are identical on Nextcloud `master` of the same day (see the last section).

The same tests in COMPLIANCE mode, on Ceph RGW and on Nextcloud 35.0.3 are on [S3 Object Lock on Ceph RGW and in COMPLIANCE mode](./s3-object-lock-ceph-compliance.md).

## Summary

| Scenario | Storage | What Nextcloud answers | What happens to the bytes | Logged |
|---|---|---|---|---|
| A.1 | Primary storage, locked bucket, trash bin on | 204, file in the trash bin | Object untouched | Nothing |
| A.2 | Same file, purged from the trash bin | 204, file gone from Nextcloud | Delete marker; locked version stays readable by version ID | Nothing |
| A.3 | Primary storage, locked bucket, trash bin off | 204, then 404 on GET | Delete marker; locked version stays | Nothing |
| B | External S3 mount, locked bucket | 204, file in the trash bin | Delete marker on the external bucket; a second copy is written to primary storage for the trash bin | Nothing |
| C | External locked bucket, folder of three files | 204 | Delete marker per file; all versions stay | Nothing |
| D | External locked bucket, legal hold on | 204 | Delete marker; the version under legal hold stays | Nothing |
| E (control) | External bucket without versioning or lock | 204 | Objects really deleted | Nothing |
| F | External bucket, policy denies `s3:DeleteObject` | 403 with an empty message, file and folder stay | Untouched | Error per attempt |
| G | External bucket, policy denies deletes inside the folder only | 204 | Untouched; the folder and files get new file IDs within the same DELETE request, no rescan needed (re-checked 2026-11-19 on 35.0.3 and Ceph RGW) | Only "trash move failed" with the trash bin on, nothing with it off |

In short: Nextcloud sends deletes without a version ID. On a bucket with Object Lock that creates a delete marker. Nextcloud reports success and removes the file from its index, while the locked bytes stay in the bucket. Deleting the locked version itself is refused by the storage (last sections). A refusal that does reach Nextcloud shows as a bare 403 without a reason.

The raw log follows. Credentials are replaced by `<redacted>`.

Run: 2026-11-16, ~09:03-09:10 UTC. Raw commands and outputs. Edited only to redact credentials, shorten the `mc` wrapper path and split the log per scenario.
`mc` is `docker run --rm --network vng-ol-net bitnamilegacy/minio-client:2025.4.16 mc` with the MinIO root credentials.

## Environment

| Component | Value |
|---|---|
| Nextcloud | image `conduction/nextcloud:34.0.0-apache-soap`, status.php `34.0.0.12` / `34.0.0`, build `4815f6755d7dd129cee22fe606362dba709d8649` (2026-06-08), PHP 8.4.22, SQLite |
| MinIO server | `bitnamilegacy/minio:2025.4.22` (digest sha256:50cec18a...), `minio --version` = RELEASE 2025-04-22T22-12-26Z, commit 0d7408fc9969caf07de6a8c3a84f9fbb10a6739e. Upstream `minio/minio` and `quay.io/minio/minio` images are no longer pullable (denied / unauthorized) and dl.min.io returns HTTP 410, so the Bitnami legacy build of the same upstream release was used. |
| mc | `bitnamilegacy/minio-client:2025.4.16` (RELEASE 2025-04-16T18-13-26Z, commit b00526b1) |
| Network | `vng-ol-net` 10.231.31.0/24 |
| Ports | MinIO API 127.0.0.1:9310, console 127.0.0.1:9311, Nextcloud 127.0.0.1:8310 |
| Credentials | `<redacted>` (MinIO root, Nextcloud admin and the two restricted MinIO users) |
| Master compared | nextcloud/server master `30676972c3557daeeb7133b49300401b7e284686` (2026-11-16, 36.0.0 dev) |

## Setup commands

```
docker network create --subnet 10.231.31.0/24 vng-ol-net
docker volume create vng-ol-minio-data
docker run -d --name vng-ol-minio --network vng-ol-net -p 127.0.0.1:9310:9000 -p 127.0.0.1:9311:9001 \
  -v vng-ol-minio-data:/bitnami/minio/data -e MINIO_ROOT_USER=<redacted> -e MINIO_ROOT_PASSWORD=<redacted> bitnamilegacy/minio:2025.4.22
mc mb --with-lock vng/vng-ol-primary ; mc mb --with-lock vng/vng-ol-external ; mc mb vng/vng-ol-plain
mc retention set --default GOVERNANCE 1d vng/vng-ol-primary ; mc retention set --default GOVERNANCE 1d vng/vng-ol-external
docker volume create vng-ol-nextcloud-html
docker run -d --name vng-ol-nextcloud --network vng-ol-net -p 127.0.0.1:8310:80 -v vng-ol-nextcloud-html:/var/www/html \
  -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD='<redacted>' \
  -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1 vng-ol-nextcloud" \
  -e OBJECTSTORE_S3_HOST=vng-ol-minio -e OBJECTSTORE_S3_PORT=9000 -e OBJECTSTORE_S3_BUCKET=vng-ol-primary \
  -e OBJECTSTORE_S3_KEY=<redacted> -e OBJECTSTORE_S3_SECRET=<redacted> -e OBJECTSTORE_S3_SSL=false \
  -e OBJECTSTORE_S3_USEPATH_STYLE=true -e OBJECTSTORE_S3_AUTOCREATE=false -e OBJECTSTORE_S3_REGION=us-east-1 \
  conduction/nextcloud:34.0.0-apache-soap
occ config:system:set loglevel --value=0 --type=integer ; occ app:enable files_external
occ files_external:create /ext-external amazons3 amazons3::accesskey -c bucket=vng-ol-external -c hostname=vng-ol-minio -c port=9000 -c region=us-east-1 -c use_ssl=false -c use_path_style=true -c key=<redacted> -c secret=<redacted>
occ files_external:create /ext-plain    amazons3 amazons3::accesskey -c bucket=vng-ol-plain    ... (same options)
```

Resulting config (`occ config:system:get objectstore`): class `\OC\Files\ObjectStore\S3`, bucket vng-ol-primary, hostname vng-ol-minio, port 9000, use_ssl false, use_path_style true, autocreate false, objectPrefix `urn:oid:`.

Bucket state after setup:
```
vng/vng-ol-primary:  Object locking 'GOVERNANCE' is configured for 1DAYS. / versioning is enabled
vng/vng-ol-external: Object locking 'GOVERNANCE' is configured for 1DAYS. / versioning is enabled
vng/vng-ol-plain:    does not support locking / un-versioned
```

Log filter used for "nextcloud.log new entries": all new lines with level >= 2, plus any line whose message matches delet|lock|S3|unlink (loglevel was 0 = debug, so an empty section means no warning or error was written at all).


## Scenario A: primary storage (bucket vng-ol-primary), delete to trash, then purge from trash

```text

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/scenarioA.txt --data-binary @/tmp/vngolA.txt
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'files/scenarioA.txt'
{"fileid":78,"storage":"object::user:admin","path":"files\/scenarioA.txt","size":53}
fileid=78 -> object key urn:oid:78

$ mc ls --versions vng/vng-ol-primary/urn:oid:78
[2026-11-16 09:04:28 UTC]    53B STANDARD 9631ec54-afa3-4124-9b3a-bbea9f00c23d v1 PUT urn:oid:78

$ mc retention info vng/vng-ol-primary/urn:oid:78
Name    : vng/vng-ol-primary/urn:oid:78
Mode    : GOVERNANCE, expiring in 23 hours 59 minutes

```

### A.1 delete via WebDAV (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/scenarioA.txt 
HTTP 204

$ PROPFIND depth 1 
<d:href>/remote.php/dav/files/admin/</d:href>
<d:href>/remote.php/dav/files/admin/Documents/</d:href>
<d:href>/remote.php/dav/files/admin/Reasons%20to%20use%20Nextcloud.pdf</d:href>
<d:href>/remote.php/dav/files/admin/Templates/</d:href>
<d:href>/remote.php/dav/files/admin/Photos/</d:href>
<d:href>/remote.php/dav/files/admin/Templates%20credits.md</d:href>
<d:href>/remote.php/dav/files/admin/Readme.md</d:href>
<d:href>/remote.php/dav/files/admin/Nextcloud.png</d:href>
<d:href>/remote.php/dav/files/admin/Nextcloud%20intro.mp4</d:href>
<d:href>/remote.php/dav/files/admin/Nextcloud%20Manual.pdf</d:href>
<d:href>/remote.php/dav/files/admin/ext-external/</d:href>
<d:href>/remote.php/dav/files/admin/ext-plain/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'files/scenarioA.txt'

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'files_trashbin/files/scenarioA.txt%'
{"fileid":78,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioA.txt.d1794819871","size":53}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioA.txt.d1794819871</d:href>
HTTP 207

$ mc ls --versions vng/vng-ol-primary/urn:oid:78
[2026-11-16 09:04:28 UTC]    53B STANDARD 9631ec54-afa3-4124-9b3a-bbea9f00c23d v1 PUT urn:oid:78

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

### A.2 purge from trash (WebDAV DELETE on trashbin item)

```text
trash item: trash/scenarioA.txt.d1794819871
$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/trashbin/admin/trash/scenarioA.txt.d1794819871
HTTP 204


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioA.txt%'

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
HTTP 207

$ mc ls --versions vng/vng-ol-primary/urn:oid:78
[2026-11-16 09:04:36 UTC]     0B STANDARD 29a40369-896a-4dbf-a31e-b036136c7a6b v2 DEL urn:oid:78
[2026-11-16 09:04:28 UTC]    53B STANDARD 9631ec54-afa3-4124-9b3a-bbea9f00c23d v1 PUT urn:oid:78

--- nextcloud.log new entries (level>=1 or delete-related) ---
OBJID_A=78

```

### A.3 primary storage, files_trashbin DISABLED (direct permanent delete)

```text

$ docker exec -u www-data vng-ol-nextcloud php occ app:disable files_trashbin
files_trashbin 1.24.0 disabled

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/scenarioA3.txt --data-binary @/tmp/vngolA3.txt
HTTP 201
fileid=83

$ mc ls --versions vng/vng-ol-primary/urn:oid:83
[2026-11-16 09:04:55 UTC]    32B STANDARD ecc825de-40e3-4c3d-a545-da5a8d319e69 v1 PUT urn:oid:83

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8310/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /scenarioA3.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioA3.txt%'

$ mc ls --versions vng/vng-ol-primary/urn:oid:83
[2026-11-16 09:04:56 UTC]     0B STANDARD 7c88c86b-c19f-485c-bbad-8602f619bfa7 v2 DEL urn:oid:83
[2026-11-16 09:04:55 UTC]    32B STANDARD ecc825de-40e3-4c3d-a545-da5a8d319e69 v1 PUT urn:oid:83

$ mc stat vng/vng-ol-primary/urn:oid:83
mc: <ERROR> Unable to stat `vng/vng-ol-primary/urn:oid:83`. Object does not exist.

--- nextcloud.log new entries (level>=1 or delete-related) ---

$ docker exec -u www-data vng-ol-nextcloud php occ app:enable files_trashbin
files_trashbin 1.24.0 enabled
OBJID_A3=83

```

## Scenario B: external S3 mount /ext-external (bucket vng-ol-external, object lock GOVERNANCE 1d)

```text

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioB.txt --data-binary @/tmp/vngolB.txt
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB.txt%'
{"fileid":84,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"scenarioB.txt","size":45}

$ mc ls --versions --recursive vng/vng-ol-external
[2026-11-16 09:05:07 UTC]    45B STANDARD 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 v1 PUT scenarioB.txt

$ mc retention info vng/vng-ol-external/scenarioB.txt
Name    : vng/vng-ol-external/scenarioB.txt
Mode    : GOVERNANCE, expiring in 23 hours 59 minutes

```

### B.1 delete via WebDAV

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioB.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioB.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-external/scenarioB.txt could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-external/
<d:href>/remote.php/dav/files/admin/ext-external/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB.txt%'
{"fileid":84,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioB.txt.d1794819910","size":45}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
{"id":"scenarioB.txt","timestamp":"1794819910","location":"ext-external","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB.txt.d1794819910</d:href>
HTTP 207

$ mc ls --versions --recursive vng/vng-ol-external
[2026-11-16 09:05:10 UTC]     0B STANDARD 0ad1fd69-3833-425a-847f-7d812e9a5098 v2 DEL scenarioB.txt
[2026-11-16 09:05:07 UTC]    45B STANDARD 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 v1 PUT scenarioB.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

### B.1b where did the trash copy go? (primary bucket)

```text

$ mc ls --versions vng/vng-ol-primary/urn:oid:84
[2026-11-16 09:05:10 UTC]    45B STANDARD 1f849df9-ad6f-4018-bfc5-cb34983eea66 v1 PUT urn:oid:84

```

### B.2 purge the trash item

```text
$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/trashbin/admin/trash/scenarioB.txt.d1794819910
HTTP 204

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB.txt%'

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
{"id":"scenarioB.txt","timestamp":"1794819910","location":"ext-external","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
HTTP 207

$ mc ls --versions vng/vng-ol-primary/urn:oid:84
[2026-11-16 09:05:22 UTC]     0B STANDARD e8a9049b-48fa-47b0-929b-9edf1019ad77 v2 DEL urn:oid:84
[2026-11-16 09:05:10 UTC]    45B STANDARD 1f849df9-ad6f-4018-bfc5-cb34983eea66 v1 PUT urn:oid:84

$ mc ls --versions --recursive vng/vng-ol-external
[2026-11-16 09:05:10 UTC]     0B STANDARD 0ad1fd69-3833-425a-847f-7d812e9a5098 v2 DEL scenarioB.txt
[2026-11-16 09:05:07 UTC]    45B STANDARD 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 v1 PUT scenarioB.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

## Scenario C: external locked bucket, folder with 3 files, delete the folder

```text

$ curl -X MKCOL http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC 
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC/c1.txt --data-binary @/tmp/vngolC1.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC/c2.txt --data-binary @/tmp/vngolC2.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC/c3.txt --data-binary @/tmp/vngolC3.txt
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC%'
{"fileid":85,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"folderC","size":72}
{"fileid":86,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"folderC\/c1.txt","size":24}
{"fileid":87,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"folderC\/c2.txt","size":24}
{"fileid":88,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"folderC\/c3.txt","size":24}

$ mc ls --versions --recursive vng/vng-ol-external/folderC
[2026-11-16 09:05:33 UTC]     0B STANDARD null v1 PUT /
[2026-11-16 09:05:34 UTC]    24B STANDARD 9ce27056-9a2f-40de-80a4-1ca52a57bb50 v1 PUT c1.txt
[2026-11-16 09:05:34 UTC]    24B STANDARD b1844150-c506-4159-9ab2-dd8cfdc039cd v1 PUT c2.txt
[2026-11-16 09:05:35 UTC]    24B STANDARD c3040605-ab40-402c-a010-dd7b64ec87c6 v1 PUT c3.txt

```

### C.1 delete folder via WebDAV (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC 
HTTP 204

$ curl -X PROPFIND http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/folderC -H Depth: 0
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-external/folderC could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-external/
<d:href>/remote.php/dav/files/admin/ext-external/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC%'
{"fileid":85,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC.d1794819936","size":72}
{"fileid":86,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC.d1794819936\/c1.txt","size":24}
{"fileid":87,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC.d1794819936\/c2.txt","size":24}
{"fileid":88,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC.d1794819936\/c3.txt","size":24}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
{"id":"scenarioB.txt","timestamp":"1794819910","location":"ext-external","user":"admin"}
{"id":"folderC","timestamp":"1794819936","location":"ext-external","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC.d1794819936/</d:href>
HTTP 207

$ mc ls --versions --recursive vng/vng-ol-external/folderC
[2026-11-16 09:05:37 UTC]     0B STANDARD 8094835c-460e-41c9-97f3-85f0f5e5d816 v2 DEL c1.txt
[2026-11-16 09:05:34 UTC]    24B STANDARD 9ce27056-9a2f-40de-80a4-1ca52a57bb50 v1 PUT c1.txt
[2026-11-16 09:05:37 UTC]     0B STANDARD dc0b612b-a2e5-4b36-9cab-e550b9accfd7 v2 DEL c2.txt
[2026-11-16 09:05:34 UTC]    24B STANDARD b1844150-c506-4159-9ab2-dd8cfdc039cd v1 PUT c2.txt
[2026-11-16 09:05:37 UTC]     0B STANDARD ad301d32-e2b2-4987-825a-e25dad6fb24c v2 DEL c3.txt
[2026-11-16 09:05:35 UTC]    24B STANDARD c3040605-ab40-402c-a010-dd7b64ec87c6 v1 PUT c3.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

## Scenario D: external locked bucket, object with LEGAL HOLD ON, delete

```text

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioD.txt --data-binary @/tmp/vngolD.txt
HTTP 201

$ mc legalhold set vng/vng-ol-external/scenarioD.txt
Object legal hold successfully set for `scenarioD.txt`.

$ mc legalhold info vng/vng-ol-external/scenarioD.txt
[    ON    ]  scenarioD.txt

$ mc retention info vng/vng-ol-external/scenarioD.txt
Name    : vng/vng-ol-external/scenarioD.txt
Mode    : GOVERNANCE, expiring in 23 hours 59 minutes

$ mc ls --versions --recursive vng/vng-ol-external/scenarioD.txt
[2026-11-16 09:05:55 UTC]    49B STANDARD 7df8fca0-e037-47c9-bea3-57b1efbce85d v1 PUT scenarioD.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD.txt%'
{"fileid":89,"storage":"amazon::external::00a1cac1d6bf505a2db6d1622443bed3","path":"scenarioD.txt","size":49}

```

### D.1 delete via WebDAV (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioD.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8310/remote.php/dav/files/admin/ext-external/scenarioD.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-external/scenarioD.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD.txt%'
{"fileid":89,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioD.txt.d1794819960","size":49}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
{"id":"scenarioB.txt","timestamp":"1794819910","location":"ext-external","user":"admin"}
{"id":"folderC","timestamp":"1794819936","location":"ext-external","user":"admin"}
{"id":"scenarioD.txt","timestamp":"1794819960","location":"ext-external","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD.txt.d1794819960</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC.d1794819936/</d:href>
HTTP 207

$ mc ls --versions --recursive vng/vng-ol-external/scenarioD.txt
[2026-11-16 09:06:00 UTC]     0B STANDARD 571a4556-8db3-414b-8133-d687a78a0cb4 v2 DEL scenarioD.txt
[2026-11-16 09:05:55 UTC]    49B STANDARD 7df8fca0-e037-47c9-bea3-57b1efbce85d v1 PUT scenarioD.txt

$ mc legalhold info --versions vng/vng-ol-external/scenarioD.txt
[    ON    ]  7df8fca0-e037-47c9-bea3-57b1efbce85d  scenarioD.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

## Scenario E (control): external mount /ext-plain, bucket vng-ol-plain WITHOUT versioning/object lock

```text

$ mc version info vng/vng-ol-plain
vng/vng-ol-plain is un-versioned

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/scenarioE.txt --data-binary @/tmp/vngolE.txt
HTTP 201

$ curl -X MKCOL http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/folderE 
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/folderE/e1.txt --data-binary @/tmp/vngolE1.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/folderE/e2.txt --data-binary @/tmp/vngolE2.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/folderE/e3.txt --data-binary @/tmp/vngolE3.txt
HTTP 201

$ mc ls --versions --recursive vng/vng-ol-plain
[2026-11-16 09:06:13 UTC]     0B STANDARD null v1 PUT folderE/
[2026-11-16 09:06:13 UTC]     3B STANDARD null v1 PUT folderE/e1.txt
[2026-11-16 09:06:14 UTC]     3B STANDARD null v1 PUT folderE/e2.txt
[2026-11-16 09:06:14 UTC]     3B STANDARD null v1 PUT folderE/e3.txt
[2026-11-16 09:06:12 UTC]    44B STANDARD null v1 PUT scenarioE.txt

```

### E.1 delete file and folder via WebDAV (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/scenarioE.txt 
HTTP 204

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-plain/folderE 
HTTP 204

$ PROPFIND depth 1 ext-plain/
<d:href>/remote.php/dav/files/admin/ext-plain/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioE.txt%'
{"fileid":90,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioE.txt.d1794819976","size":44}

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderE%'
{"fileid":91,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE.d1794819976","size":9}
{"fileid":92,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE.d1794819976\/e1.txt","size":3}
{"fileid":93,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE.d1794819976\/e2.txt","size":3}
{"fileid":94,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE.d1794819976\/e3.txt","size":3}

$ mc ls --versions --recursive vng/vng-ol-plain

$ mc stat vng/vng-ol-plain/scenarioE.txt
mc: <ERROR> Unable to stat `vng/vng-ol-plain/scenarioE.txt`. Object does not exist.

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

## Setup F: bucket vng-ol-nodelete + MinIO user vngol-nodelete whose policy DENIES s3:DeleteObject

```text
{"Version":"2012-10-17","Statement":[
 {"Effect":"Allow","Action":["s3:*"],"Resource":["arn:aws:s3:::vng-ol-nodelete","arn:aws:s3:::vng-ol-nodelete/*"]},
 {"Effect":"Deny","Action":["s3:DeleteObject"],"Resource":["arn:aws:s3:::vng-ol-nodelete/*"]}]}

$ mc mb vng/vng-ol-nodelete
Bucket created successfully `vng/vng-ol-nodelete`.
Created policy `nodelete` successfully.
Added user `vngol-nodelete` successfully.
Attached Policies: [nodelete]
To User: vngol-nodelete

$ docker exec -u www-data vng-ol-nextcloud php occ files_external:create /ext-nodelete amazons3 amazons3::accesskey -c bucket=vng-ol-nodelete -c hostname=vng-ol-minio -c port=9000 -c region=us-east-1 -c use_ssl=false -c use_path_style=true -c key=vngol-nodelete -c secret=<redacted>
Storage created with id 3

$ docker exec -u www-data vng-ol-nextcloud php occ files_external:verify 3
  - status: ok
  - code: 0
  - message

```

## Scenario F: external mount /ext-nodelete, credentials that may NOT delete (s3:DeleteObject denied)

```text

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt --data-binary @/tmp/vngolF.txt
HTTP 201

$ curl -X MKCOL http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/folderF 
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/folderF/f1.txt --data-binary @/tmp/vngolF1.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/folderF/f2.txt --data-binary @/tmp/vngolF2.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/folderF/f3.txt --data-binary @/tmp/vngolF3.txt
HTTP 201

$ mc ls --versions --recursive vng/vng-ol-nodelete
[2026-11-16 09:06:41 UTC]     0B STANDARD null v1 PUT folderF/
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f1.txt
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f2.txt
[2026-11-16 09:06:43 UTC]     3B STANDARD null v1 PUT folderF/f3.txt
[2026-11-16 09:06:41 UTC]     7B STANDARD null v1 PUT scenarioF.txt

```

### F.1 single file delete (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt 
HTTP 403
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message/>
</d:error>


$ PROPFIND depth 1 ext-nodelete/
<d:href>/remote.php/dav/files/admin/ext-nodelete/</d:href>
<d:href>/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt</d:href>
<d:href>/remote.php/dav/files/admin/ext-nodelete/folderF/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioF.txt%'
{"fileid":96,"storage":"amazon::external::2774a5edc467e63ff0c549645b248b4b","path":"scenarioF.txt","size":7}

$ mc ls --versions --recursive vng/vng-ol-nodelete
[2026-11-16 09:06:41 UTC]     0B STANDARD null v1 PUT folderF/
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f1.txt
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f2.txt
[2026-11-16 09:06:43 UTC]     3B STANDARD null v1 PUT folderF/f3.txt
[2026-11-16 09:06:41 UTC]     7B STANDARD null v1 PUT scenarioF.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}

```

### F.2 folder delete (trashbin enabled) -> AmazonS3::rmdir -> batchDelete (deleteObjects Quiet:true)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-nodelete/folderF 
HTTP 403
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message/>
</d:error>


$ PROPFIND depth 1 ext-nodelete/
<d:href>/remote.php/dav/files/admin/ext-nodelete/</d:href>
<d:href>/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt</d:href>
<d:href>/remote.php/dav/files/admin/ext-nodelete/folderF/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderF%'
{"fileid":97,"storage":"amazon::external::2774a5edc467e63ff0c549645b248b4b","path":"folderF","size":9}
{"fileid":98,"storage":"amazon::external::2774a5edc467e63ff0c549645b248b4b","path":"folderF\/f1.txt","size":3}
{"fileid":99,"storage":"amazon::external::2774a5edc467e63ff0c549645b248b4b","path":"folderF\/f2.txt","size":3}
{"fileid":100,"storage":"amazon::external::2774a5edc467e63ff0c549645b248b4b","path":"folderF\/f3.txt","size":3}

$ mc ls --versions --recursive vng/vng-ol-nodelete
[2026-11-16 09:06:41 UTC]     0B STANDARD null v1 PUT folderF/
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f1.txt
[2026-11-16 09:06:42 UTC]     3B STANDARD null v1 PUT folderF/f2.txt
[2026-11-16 09:06:43 UTC]     3B STANDARD null v1 PUT folderF/f3.txt
[2026-11-16 09:06:41 UTC]     7B STANDARD null v1 PUT scenarioF.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}

```

## Setup G: bucket vng-ol-partial + user vngol-partial: s3:DeleteObject denied ONLY for keys under folderG/ (the key 'folderG' itself may be deleted)

```text
{"Version":"2012-10-17","Statement":[
 {"Effect":"Allow","Action":["s3:*"],"Resource":["arn:aws:s3:::vng-ol-partial","arn:aws:s3:::vng-ol-partial/*"]},
 {"Effect":"Deny","Action":["s3:DeleteObject"],"Resource":["arn:aws:s3:::vng-ol-partial/folderG/*"]}]}

$ mc mb vng/vng-ol-partial
Bucket created successfully `vng/vng-ol-partial`.
Created policy `partialdelete` successfully.
Added user `vngol-partial` successfully.
Attached Policies: [partialdelete]
To User: vngol-partial

$ docker exec -u www-data vng-ol-nextcloud php occ files_external:create /ext-partial amazons3 amazons3::accesskey -c bucket=vng-ol-partial -c hostname=vng-ol-minio -c port=9000 -c region=us-east-1 -c use_ssl=false -c use_path_style=true -c key=vngol-partial -c secret=<redacted>
Storage created with id 4

$ docker exec -u www-data vng-ol-nextcloud php occ files_external:verify 4
  - status: ok
  - code: 0
  - message

```

## Scenario G: folder delete where every per-key delete inside deleteObjects is refused

```text

$ curl -X MKCOL http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG/g1.txt --data-binary @/tmp/vngolG1.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG/g2.txt --data-binary @/tmp/vngolG2.txt
HTTP 201

$ curl -X PUT http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG/g3.txt --data-binary @/tmp/vngolG3.txt
HTTP 201

$ mc ls --versions --recursive vng/vng-ol-partial
[2026-11-16 09:07:09 UTC]     0B STANDARD null v1 PUT folderG/
[2026-11-16 09:07:09 UTC]     3B STANDARD null v1 PUT folderG/g1.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g2.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g3.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":102,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG","size":9}
{"fileid":103,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g1.txt","size":3}
{"fileid":104,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g2.txt","size":3}
{"fileid":105,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g3.txt","size":3}

```

### G.1 delete folderG via WebDAV (trashbin enabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204

$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":106,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG","size":9}
{"fileid":107,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g1.txt","size":3}
{"fileid":108,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g2.txt","size":3}
{"fileid":109,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g3.txt","size":3}

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderG.d%'

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioA.txt","timestamp":"1794819871","location":".","user":"admin"}
{"id":"scenarioB.txt","timestamp":"1794819910","location":"ext-external","user":"admin"}
{"id":"folderC","timestamp":"1794819936","location":"ext-external","user":"admin"}
{"id":"scenarioD.txt","timestamp":"1794819960","location":"ext-external","user":"admin"}
{"id":"scenarioE.txt","timestamp":"1794819976","location":"ext-plain","user":"admin"}
{"id":"folderE","timestamp":"1794819976","location":"ext-plain","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioE.txt.d1794819976</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderE.d1794819976/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD.txt.d1794819960</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC.d1794819936/</d:href>
HTTP 207

$ mc ls --versions --recursive vng/vng-ol-partial
[2026-11-16 09:07:09 UTC]     0B STANDARD null v1 PUT folderG/
[2026-11-16 09:07:09 UTC]     3B STANDARD null v1 PUT folderG/g1.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g2.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g3.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}

```

### G.2 same folder delete with files_trashbin DISABLED (direct AmazonS3::rmdir -> batchDelete)

```text

$ docker exec -u www-data vng-ol-nextcloud php occ app:disable files_trashbin
files_trashbin 1.24.0 disabled

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":106,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG","size":9}
{"fileid":107,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g1.txt","size":3}
{"fileid":108,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g2.txt","size":3}
{"fileid":109,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g3.txt","size":3}

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204
(cache immediately after DELETE, before any listing)

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":110,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG","size":9}
{"fileid":111,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g1.txt","size":3}
{"fileid":112,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g2.txt","size":3}
{"fileid":113,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g3.txt","size":3}

$ mc ls --versions --recursive vng/vng-ol-partial
[2026-11-16 09:07:09 UTC]     0B STANDARD null v1 PUT folderG/
[2026-11-16 09:07:09 UTC]     3B STANDARD null v1 PUT folderG/g1.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g2.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g3.txt

$ curl -X PROPFIND http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG -H Depth: 0
HTTP 207
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:response><d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 16 Nov 2026 09:07:27 GMT</d:getlastmodified><d:resourcetype><d:collection/></d:resourcetype><d:quota-used-bytes>9</d:quota-used-bytes><d:quota-available-bytes>-3</d:quota-available-bytes><d:getetag>&quot;6afac7cf50740&quot;</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>


$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":110,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG","size":9}
{"fileid":111,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g1.txt","size":3}
{"fileid":112,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g2.txt","size":3}
{"fileid":113,"storage":"amazon::external::2aa99a9bf4bc9b39ca5cc60e873523df","path":"folderG\/g3.txt","size":3}

--- nextcloud.log new entries (level>=1 or delete-related) ---

```

### G.3 control on the same mount: delete a single file inside folderG directly (trashbin disabled)

```text

$ curl -X DELETE http://127.0.0.1:8310/remote.php/dav/files/admin/ext-partial/folderG/g1.txt 
HTTP 403
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message/>
</d:error>


$ mc ls --versions --recursive vng/vng-ol-partial
[2026-11-16 09:07:09 UTC]     0B STANDARD null v1 PUT folderG/
[2026-11-16 09:07:09 UTC]     3B STANDARD null v1 PUT folderG/g1.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g2.txt
[2026-11-16 09:07:10 UTC]     3B STANDARD null v1 PUT folderG/g3.txt

--- nextcloud.log new entries (level>=1 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG/g1.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/vng-ol-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}

$ docker exec -u www-data vng-ol-nextcloud php occ app:enable files_trashbin
files_trashbin 1.24.0 enabled

```

## Probe: raw DeleteObjects response for the call batchDelete() makes (user vngol-partial, prefix folderG/)

```text
$ php /tmp/deleteobjects-probe.php vngol-partial *** vng-ol-partial folderG/
HTTP status: 200
Deleted: null
Errors: [
    {
        "Key": "folderG\/",
        "VersionId": "",
        "Code": "AccessDenied",
        "Message": "Access Denied."
    },
    {
        "Key": "folderG\/g1.txt",
        "VersionId": "",
        "Code": "AccessDenied",
        "Message": "Access Denied."
    },
    {
        "Key": "folderG\/g2.txt",
        "VersionId": "",
        "Code": "AccessDenied",
        "Message": "Access Denied."
    },
    {
        "Key": "folderG\/g3.txt",
        "VersionId": "",
        "Code": "AccessDenied",
        "Message": "Access Denied."
    }
]

```

## Probe on locked bucket with admin creds (DeleteObjects without VersionId on vng-ol-external, prefix probe/)

No output was recorded for this probe.

## Recovery: read the 'deleted' bytes back from MinIO by version id

```text
(plain GET of the key first, to show the delete marker hides it)

$ mc cat vng/vng-ol-external/scenarioB.txt
mc: <ERROR> Unable to read from `vng/vng-ol-external/scenarioB.txt`. Object does not exist.

$ mc cat --version-id 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 vng/vng-ol-external/scenarioB.txt
vng-ol-B 2026-11-16T09:05:06Z locked content
(original upload for comparison:)
vng-ol-B 2026-11-16T09:05:06Z locked content

$ mc cat --version-id 7df8fca0-e037-47c9-bea3-57b1efbce85d vng/vng-ol-external/scenarioD.txt
vng-ol-D 2026-11-16T09:05:55Z legal hold content
vng-ol-D 2026-11-16T09:05:55Z legal hold content

$ mc cat --version-id 9ce27056-9a2f-40de-80a4-1ca52a57bb50 vng/vng-ol-external/folderC/c1.txt
C1 2026-11-16T09:05:33Z

$ mc cat --version-id b1844150-c506-4159-9ab2-dd8cfdc039cd vng/vng-ol-external/folderC/c2.txt
C2 2026-11-16T09:05:34Z

$ mc cat --version-id c3040605-ab40-402c-a010-dd7b64ec87c6 vng/vng-ol-external/folderC/c3.txt
C3 2026-11-16T09:05:34Z

$ mc cat --version-id 9631ec54-afa3-4124-9b3a-bbea9f00c23d vng/vng-ol-primary/urn:oid:78
vng-ol-A-primary-2026-11-16T09:04:27Z locked content
vng-ol-A-primary-2026-11-16T09:04:27Z locked content

$ mc cat --version-id ecc825de-40e3-4c3d-a545-da5a8d319e69 vng/vng-ol-primary/urn:oid:83
A3 content 2026-11-16T09:04:54Z

$ mc cat --version-id 1f849df9-ad6f-4018-bfc5-cb34983eea66 vng/vng-ol-primary/urn:oid:84
vng-ol-B 2026-11-16T09:05:06Z locked content

```

## Lock enforcement: deleting the locked VERSION itself (what a version-aware delete would do)

```text

$ mc rm --version-id 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 vng/vng-ol-external/scenarioB.txt
mc: <ERROR> Failed to remove `vng/vng-ol-external/scenarioB.txt`. Object, 'scenarioB.txt (Version ID=8ace6ce9-c39e-4b60-bfc6-77cc6e157df4)' is WORM protected and cannot be overwritten

$ mc rm --version-id 7df8fca0-e037-47c9-bea3-57b1efbce85d --bypass vng/vng-ol-external/scenarioD.txt
mc: <ERROR> Failed to remove `vng/vng-ol-external/scenarioD.txt`. Object, 'scenarioD.txt (Version ID=7df8fca0-e037-47c9-bea3-57b1efbce85d)' is WORM protected and cannot be overwritten

$ mc ls --versions --recursive vng/vng-ol-external
[2026-11-16 09:05:37 UTC]     0B STANDARD 8094835c-460e-41c9-97f3-85f0f5e5d816 v2 DEL folderC/c1.txt
[2026-11-16 09:05:34 UTC]    24B STANDARD 9ce27056-9a2f-40de-80a4-1ca52a57bb50 v1 PUT folderC/c1.txt
[2026-11-16 09:05:37 UTC]     0B STANDARD dc0b612b-a2e5-4b36-9cab-e550b9accfd7 v2 DEL folderC/c2.txt
[2026-11-16 09:05:34 UTC]    24B STANDARD b1844150-c506-4159-9ab2-dd8cfdc039cd v1 PUT folderC/c2.txt
[2026-11-16 09:05:37 UTC]     0B STANDARD ad301d32-e2b2-4987-825a-e25dad6fb24c v2 DEL folderC/c3.txt
[2026-11-16 09:05:35 UTC]    24B STANDARD c3040605-ab40-402c-a010-dd7b64ec87c6 v1 PUT folderC/c3.txt
[2026-11-16 09:05:10 UTC]     0B STANDARD 0ad1fd69-3833-425a-847f-7d812e9a5098 v2 DEL scenarioB.txt
[2026-11-16 09:05:07 UTC]    45B STANDARD 8ace6ce9-c39e-4b60-bfc6-77cc6e157df4 v1 PUT scenarioB.txt
[2026-11-16 09:06:00 UTC]     0B STANDARD 571a4556-8db3-414b-8133-d687a78a0cb4 v2 DEL scenarioD.txt
[2026-11-16 09:05:55 UTC]    49B STANDARD 7df8fca0-e037-47c9-bea3-57b1efbce85d v1 PUT scenarioD.txt

$ mc retention info --versions vng/vng-ol-external/scenarioB.txt
[     GOVERNANCE     ]  8ace6ce9-c39e-4b60-bfc6-77cc6e157df4  vng/vng-ol-external/scenarioB.txt

```

## Master vs tested version (function bodies extracted and diffed)

```
AmazonS3.php::unlink -> IDENTICAL
AmazonS3.php::rmdir -> IDENTICAL
AmazonS3.php::batchDelete -> IDENTICAL
AmazonS3.php::clearBucket -> IDENTICAL
S3ObjectTrait.php::deleteObject -> IDENTICAL
ObjectStoreStorage.php::unlink -> IDENTICAL
ObjectStoreStorage.php::rmdir -> IDENTICAL
ObjectStoreStorage.php::rmObjects -> IDENTICAL
ObjectStoreStorage.php::rmObject -> IDENTICAL
grep Quiet|VersionId|BypassGovernance on master: only AmazonS3.php:281 'Quiet' => true
```
The files differ elsewhere (getMetaData, copy failure propagation, encryption handling in moveFromStorage, Trashbin owner row deletion), none in the delete paths.

## Teardown (not run)

```
docker rm -f vng-ol-nextcloud vng-ol-minio && docker volume rm vng-ol-nextcloud-html vng-ol-minio-data && docker network rm vng-ol-net
```
