---
title: S3 Object Lock on Ceph RGW and in COMPLIANCE mode
sidebar_position: 3
description: The S3 Object Lock delete tests repeated on Ceph RGW and in COMPLIANCE mode, on Nextcloud 35.0.3. Evidence for nextcloud/server#66498.
---

# S3 Object Lock on Ceph RGW and in COMPLIANCE mode

This page repeats the tests from [S3 Object Lock deletes](./s3-object-lock-deletes.md) in three new ways:

- in **COMPLIANCE** mode, next to GOVERNANCE;
- on **Ceph RGW** as a second reference storage, next to MinIO;
- on **Nextcloud 35.0.3**, the latest patch release of a maintained major (released 12 November 2026).

Tested on 18 November 2026. Scenario letters match the first page.

## Summary

All runs below are on Nextcloud 35.0.3 unless the column says otherwise. "Same" means the same WebDAV answer, the same Nextcloud state, the same bucket state and the same log as the column to its left.

| Scenario | MinIO GOVERNANCE (34.0.0, 16 Nov; 35.0.3, 18 Nov) | MinIO COMPLIANCE | Ceph RGW GOVERNANCE | Ceph RGW COMPLIANCE |
|---|---|---|---|---|
| A.3 primary storage, trash bin off | 204, then 404 on GET. Delete marker, locked version stays. Nothing logged. Same on 35.0.3. | Not run (primary bucket is GOVERNANCE) | Same | Not run |
| B external mount, single file | 204, file in the trash bin. Delete marker on the external bucket, a second copy in primary storage. Nothing logged. Same on 35.0.3. | Same | Same | Same |
| C external mount, folder of three files | 204. Delete marker per file, every version stays. Nothing logged. Same on 35.0.3. | Same | Same, and the folder marker object also gets a delete marker (see below) | Same as Ceph GOVERNANCE |
| D external mount, legal hold on | 204. Delete marker, the version under legal hold stays. Nothing logged. Same on 35.0.3. | Same | Same | Same |
| E control, plain bucket | 204, objects really deleted. Nothing logged. (34.0.0 only) | n/a | Same | n/a |
| F policy denies `s3:DeleteObject` | 403 with an empty message, file and folder stay. Error per attempt. Same on 35.0.3 for F.1 and F.2. | n/a | Same; the logged S3 error text is empty | n/a |
| G policy denies deletes inside the folder only | 204, nothing deleted, folder and files reappear with new file IDs. Only "trash move failed" with the trash bin on. Same on 35.0.3. | n/a | Same | n/a |
| Version-specific `DeleteObject`, no bypass | Refused: 400 `InvalidRequest`, "Object is WORM protected and cannot be overwritten" | Refused, same error | Refused: 403 `AccessDenied`, "forbidden by object lock" | Refused, same error |
| Version-specific `DeleteObject` with `x-amz-bypass-governance-retention`, as bucket owner | **Version deleted** (204) | Refused, same error as without bypass | **Version deleted** (204) | Refused, same error as without bypass |
| Same, version under legal hold | Refused, same error | not run | Refused, same error | not run |
| Version-specific `DeleteObjects` (`Quiet: true`) | HTTP 200, the refusal only in `Errors[]` (`InvalidRequest`) | Same | HTTP 200, the refusal only in `Errors[]` (`AccessDenied`) | Same |

In short: COMPLIANCE mode and Ceph RGW change nothing about what Nextcloud does. Every delete without a version ID creates a delete marker and succeeds. Nextcloud reports success and drops the file from its index, while the locked bytes stay readable by version ID. Nextcloud 35.0.3 behaves exactly as 34.0.0, and its delete paths are unchanged.

The mode matters only once a delete names a version. Then GOVERNANCE gives way to a bypass request, and COMPLIANCE does not. Both storages return the same error with or without bypass, so Nextcloud cannot tell from the error which mode refused it.

## What behaved differently from MinIO GOVERNANCE

Nothing in Nextcloud's behaviour differed. The storage-side differences:

1. **Refusal code.** For a locked version MinIO answers `400 InvalidRequest` ("Object is WORM protected and cannot be overwritten"). Ceph RGW answers `403 AccessDenied` ("forbidden by object lock"). A fix that maps refusals to a WebDAV status must handle both.
2. **Error text.** Ceph RGW sends an empty `<Message>` for a policy denial and for `NoSuchKey`. Nextcloud's log then reads `AccessDenied (client): ` with nothing after it. The aws CLI 2.22.35 crashes on that body (`argument of type 'NoneType' is not iterable`); the PHP SDK in Nextcloud handles it. Inside a `DeleteObjects` response Ceph puts `AccessDenied` in both `Code` and `Message`.
3. **Folder marker objects.** Nextcloud writes a zero-byte `folder/` object for each folder. On Ceph RGW it gets a real version ID, and deleting the folder adds a delete marker on it too. On MinIO it has version `null` and is gone from `list-object-versions` after the folder delete. The files inside behave the same on both.
4. **Policy mechanism.** MinIO uses IAM user policies (scenarios F and G). Ceph RGW in this setup uses bucket policies with the user as principal. The effect on Nextcloud is the same.
5. **COMPLIANCE vs GOVERNANCE in the error.** Neither storage says which mode refused. Only `GetObjectRetention` tells you.

## Environment

| Component | Value |
|---|---|
| Nextcloud | image `nextcloud:35.0.3-apache`, digest `sha256:8a7a65b8bae82a79568fec2ed9cf3e374a1944eda2a22ebe130d79463336fee3`. `occ -V` = Nextcloud 35.0.3, version 35.0.3.2, build 2026-11-12 `7764ef4927e5f85071e495ce7602ccd247bb6b30`. PHP 8.5.12, SQLite, bundled AWS SDK 3.398.3, files_external 2.0.0, files_trashbin 2.0.0 |
| Ceph RGW | image `quay.io/ceph/demo:main-30dc8b9a-squid-centos-stream9-x86_64`, digest `sha256:522483cf07cfce6386b8e18a3edfa88a1b32c688dee231d0a6962b1513557723` (same digest as the `latest-squid` tag). `ceph version 19.2.0 (16063ff2022298c9300e49a547a16ffda59baf13) squid (stable)`. One mon, mgr, OSD (directory backed) and RGW in one container. Health `HEALTH_WARN` only for the single OSD (undersized placement groups), which does not affect S3 behaviour |
| MinIO | the server from the first page, `bitnamilegacy/minio:2025.4.22`, digest `sha256:50cec18ac4184af4671a78aedd5554942c8ae105d51a465fa82037949046da01`, RELEASE 2025-04-22T22-12-26Z. Only new `cmp-` buckets and users were added |
| aws CLI | `amazon/aws-cli:2.22.35`, digest `sha256:6977c83ae3dc99f28fcf8276b9ea5eec33833cd5be40574b34112e98113ec7a2` |
| Networks | Ceph: `cmp-ceph-net` 10.232.41.0/24, RGW at 10.232.41.10:8080. MinIO: the existing `vng-ol-net` |
| Ports (127.0.0.1 only) | Ceph RGW 9320, Nextcloud on Ceph 8320, Nextcloud on MinIO 8321 |
| Credentials | `<redacted>` (Ceph RGW users, MinIO root and users, Nextcloud admin) |
| Source compared | nextcloud/server tags `v34.0.0` (`f65ed43a`) and `v35.0.3` (`490f0929`) |

Why this Ceph image: `quay.io/ceph/demo` is the single-container Ceph build from the Ceph project, still pullable and published per release line. It was pinned to the squid build tag and its digest. `quay.io/ceph/daemon` was not used: its newest builds are from May 2024. The Ceph image ran on the first attempt.

## Setup commands

`aws` is `docker run --rm --network <net> -e AWS_ACCESS_KEY_ID=<redacted> -e AWS_SECRET_ACCESS_KEY=<redacted> amazon/aws-cli:2.22.35 --endpoint-url <endpoint>` with the bucket owner credentials, unless `--as` names a user.

```
# Ceph RGW
docker network create --subnet 10.232.41.0/24 cmp-ceph-net
docker volume create cmp-ceph-etc ; docker volume create cmp-ceph-var
docker run -d --name cmp-ceph-rgw --network cmp-ceph-net --ip 10.232.41.10 -p 127.0.0.1:9320:8080 \
  -v cmp-ceph-etc:/etc/ceph -v cmp-ceph-var:/var/lib/ceph \
  -e MON_IP=10.232.41.10 -e CEPH_PUBLIC_NETWORK=10.232.41.0/24 -e CEPH_DEMO_UID=cmpadmin \
  -e CEPH_DEMO_ACCESS_KEY=<redacted> -e CEPH_DEMO_SECRET_KEY=<redacted> \
  -e DEMO_DAEMONS=osd,rgw -e OSD_TYPE=directory -e RGW_FRONTEND_PORT=8080 \
  quay.io/ceph/demo:main-30dc8b9a-squid-centos-stream9-x86_64

# Locked buckets (Ceph: cmp-ceph-primary, cmp-ceph-external GOVERNANCE, cmp-ceph-compliance COMPLIANCE;
#                MinIO: cmp-primary, cmp-governance GOVERNANCE, cmp-compliance COMPLIANCE)
aws s3api create-bucket --bucket <bucket> --object-lock-enabled-for-bucket
aws s3api put-object-lock-configuration --bucket <bucket> --object-lock-configuration \
  '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"<GOVERNANCE|COMPLIANCE>","Days":1}}}'
# Plain buckets: cmp-ceph-plain, cmp-ceph-nodelete, cmp-ceph-partial (Ceph), cmp-nodelete, cmp-partial (MinIO)
aws s3api create-bucket --bucket <bucket>

# Ceph users and bucket policies (F, G)
radosgw-admin user create --uid=cmp-nodelete --display-name=cmp-nodelete --access-key=<redacted> --secret=<redacted>
radosgw-admin user create --uid=cmp-partial  --display-name=cmp-partial  --access-key=<redacted> --secret=<redacted>
aws s3api put-bucket-policy --bucket cmp-ceph-nodelete --policy file://c-nodelete.json
aws s3api put-bucket-policy --bucket cmp-ceph-partial  --policy file://c-partial.json

# MinIO users and IAM policies (F, G)
mc admin policy create vng cmp-nodelete m-nodelete.json ; mc admin policy create vng cmp-partial m-partial.json
mc admin user add vng cmp-nodelete <redacted> ; mc admin user add vng cmp-partial <redacted>
mc admin policy attach vng cmp-nodelete --user cmp-nodelete ; mc admin policy attach vng cmp-partial --user cmp-partial

# Two Nextcloud 35.0.3 instances, primary object store on a locked bucket
docker run -d --name cmp-ceph-nextcloud --network cmp-ceph-net -p 127.0.0.1:8320:80 -v cmp-ceph-nextcloud-html:/var/www/html \
  -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD='<redacted>' \
  -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1 cmp-ceph-nextcloud" \
  -e OBJECTSTORE_S3_HOST=10.232.41.10 -e OBJECTSTORE_S3_PORT=8080 -e OBJECTSTORE_S3_BUCKET=cmp-ceph-primary \
  -e OBJECTSTORE_S3_KEY=<redacted> -e OBJECTSTORE_S3_SECRET=<redacted> -e OBJECTSTORE_S3_SSL=false \
  -e OBJECTSTORE_S3_USEPATH_STYLE=true -e OBJECTSTORE_S3_AUTOCREATE=false -e OBJECTSTORE_S3_REGION=us-east-1 \
  nextcloud:35.0.3-apache
docker run -d --name cmp-minio-nextcloud --network vng-ol-net -p 127.0.0.1:8321:80 -v cmp-minio-nextcloud-html:/var/www/html \
  ... (same, with OBJECTSTORE_S3_HOST=vng-ol-minio, OBJECTSTORE_S3_PORT=9000, OBJECTSTORE_S3_BUCKET=cmp-primary) nextcloud:35.0.3-apache
occ config:system:set loglevel --value=0 --type=integer ; occ app:enable files_external
```

Policies:

```
c-nodelete.json (Ceph bucket policy)
{"Version":"2012-10-17","Statement":[
 {"Effect":"Allow","Principal":{"AWS":["arn:aws:iam:::user/cmp-nodelete"]},"Action":["s3:*"],"Resource":["arn:aws:s3:::cmp-ceph-nodelete","arn:aws:s3:::cmp-ceph-nodelete/*"]},
 {"Effect":"Deny","Principal":{"AWS":["arn:aws:iam:::user/cmp-nodelete"]},"Action":["s3:DeleteObject"],"Resource":["arn:aws:s3:::cmp-ceph-nodelete/*"]}]}
c-partial.json: the same for user cmp-partial on cmp-ceph-partial, Deny only on "arn:aws:s3:::cmp-ceph-partial/folderG/*"

m-nodelete.json (MinIO IAM policy)
{"Version":"2012-10-17","Statement":[
 {"Effect":"Allow","Action":["s3:*"],"Resource":["arn:aws:s3:::cmp-nodelete","arn:aws:s3:::cmp-nodelete/*"]},
 {"Effect":"Deny","Action":["s3:DeleteObject"],"Resource":["arn:aws:s3:::cmp-nodelete/*"]}]}
m-partial.json: the same on cmp-partial, Deny only on "arn:aws:s3:::cmp-partial/folderG/*"
```

Bucket state after setup (`get-object-lock-configuration`, `get-bucket-versioning`):

```
cmp-ceph-primary     {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"GOVERNANCE","Days":1}}}  {"Status":"Enabled","MFADelete":"Disabled"}
cmp-ceph-external    {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"GOVERNANCE","Days":1}}}  {"Status":"Enabled","MFADelete":"Disabled"}
cmp-ceph-compliance  {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":1}}}  {"Status":"Enabled","MFADelete":"Disabled"}
cmp-primary          {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"GOVERNANCE","Days":1}}}  {"Status":"Enabled"}
cmp-governance       {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"GOVERNANCE","Days":1}}}  {"Status":"Enabled"}
cmp-compliance       {"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":1}}}  {"Status":"Enabled"}
cmp-ceph-plain, cmp-ceph-nodelete, cmp-ceph-partial, cmp-nodelete, cmp-partial: no lock, versioning never enabled
```

Mounts (`occ files_external:verify` returned `status: ok` for each):

```
$ occ files_external:create /<mount> amazons3 amazons3::accesskey -c bucket=<bucket> -c hostname=<host> -c port=<port> \
    -c region=us-east-1 -c use_ssl=false -c use_path_style=true -c key=<redacted> -c secret=<redacted>

cmp-ceph-nextcloud:  /ext-governance -> cmp-ceph-external, /ext-compliance -> cmp-ceph-compliance, /ext-plain -> cmp-ceph-plain,
                     /ext-nodelete -> cmp-ceph-nodelete (user cmp-nodelete), /ext-partial -> cmp-ceph-partial (user cmp-partial)
cmp-minio-nextcloud: /ext-governance -> cmp-governance, /ext-compliance -> cmp-compliance,
                     /ext-nodelete -> cmp-nodelete (user cmp-nodelete), /ext-partial -> cmp-partial (user cmp-partial)
```

## How to read the raw log

Raw commands and outputs follow, edited only to redact credentials and to split the log per scenario. `SQL>` queries the Nextcloud SQLite database directly. The log filter for "nextcloud.log new entries" is the same as on the first page: every new line with level 2 or higher, plus any line whose message matches delet, lock, S3 or unlink. The log level was 0 (debug), so an empty section means nothing was written. `list-object-versions` is printed one line per version or delete marker: time, type, `IsLatest`, size, version ID, key.

On Ceph, the first run of C (COMPLIANCE), D and E lost its bucket listings: the aws CLI image was removed from the host by an unrelated cleanup during the run. Those three scenarios were run again on new file names with the suffix `-r2`, and that second run is shown. The Nextcloud side of the first attempt (WebDAV status, cache rows, trash bin, log) matched the second run.

## Ceph RGW run (Nextcloud 35.0.3, cmp-ceph-nextcloud)

Run 2026-11-18, 12:01-12:10 UTC.

### A.3 primary storage, files_trashbin disabled

```text
$ occ app:disable files_trashbin
files_trashbin 2.0.0 disabled

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/scenarioA3.txt --data-binary 'A3 content 2026-11-18T12:01:11Z'
HTTP 201
fileid=81 -> object key urn:oid:81

$ aws s3api list-object-versions --bucket cmp-ceph-primary --prefix urn:oid:81
2026-11-18T12:01:12.722000+00:00  VERSION        latest=true  31B  Q6kcu8EAHGnJ51g9sVqW.i39Gd-pjMP  urn:oid:81

$ aws s3api get-object-retention --bucket cmp-ceph-primary --key urn:oid:81
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:01:12.722482+00:00"}}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8320/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /scenarioA3.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioA3.txt%'

$ aws s3api list-object-versions --bucket cmp-ceph-primary --prefix urn:oid:81
2026-11-18T12:01:12.722000+00:00  VERSION        latest=false  31B  Q6kcu8EAHGnJ51g9sVqW.i39Gd-pjMP  urn:oid:81
2026-11-18T12:01:19.551000+00:00  DELETE-MARKER  latest=true  0B  q2KcIM8xzSiiicaNnNld0Z-LLxzchBk  urn:oid:81

$ aws s3api head-object --bucket cmp-ceph-primary --key urn:oid:81

An error occurred (404) when calling the HeadObject operation: Not Found

--- nextcloud.log new entries (level>=2 or delete-related) ---

$ occ app:enable files_trashbin
files_trashbin 2.0.0 enabled
```

### B external mount /ext-governance (bucket cmp-ceph-external)

```text
$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt --data-binary 'B ext-governance 2026-11-18T12:01:30Z locked content'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-governance.txt%'
{"fileid":82,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"scenarioB-ext-governance.txt","size":52}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix scenarioB-ext-governance.txt
2026-11-18T12:01:30.918000+00:00  VERSION        latest=true  52B  cWelrr6BjFJitQ3JSDT5ligQVrJqz3Y  scenarioB-ext-governance.txt

$ aws s3api get-object-retention --bucket cmp-ceph-external --key scenarioB-ext-governance.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:01:30.918091+00:00"}}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/scenarioB-ext-governance.txt could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-governance/
<d:href>/remote.php/dav/files/admin/ext-governance/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-governance.txt%'
{"fileid":82,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioB-ext-governance.txt.d1795003299","size":52}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795003299","location":"ext-governance","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795003299</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix scenarioB-ext-governance.txt
2026-11-18T12:01:30.918000+00:00  VERSION        latest=false  52B  cWelrr6BjFJitQ3JSDT5ligQVrJqz3Y  scenarioB-ext-governance.txt
2026-11-18T12:01:39.206000+00:00  DELETE-MARKER  latest=true  0B  iPkSfJ0kC-9JwT7dEz2KEv-8wCAUshs  scenarioB-ext-governance.txt

(trash copy in primary storage: urn:oid:82)

$ aws s3api list-object-versions --bucket cmp-ceph-primary --prefix urn:oid:82
2026-11-18T12:01:39.131000+00:00  VERSION        latest=true  52B  r8uVzLL-9wmADcgJI05XNCjmgQGfSVJ  urn:oid:82

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### B external mount /ext-compliance (bucket cmp-ceph-compliance)

```text
$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt --data-binary 'B ext-compliance 2026-11-18T12:01:47Z locked content'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-compliance.txt%'
{"fileid":87,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"scenarioB-ext-compliance.txt","size":52}

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix scenarioB-ext-compliance.txt
2026-11-18T12:01:48.049000+00:00  VERSION        latest=true  52B  cNr6zbVXShV6Tecg9zOnYoaJyLIZYge  scenarioB-ext-compliance.txt

$ aws s3api get-object-retention --bucket cmp-ceph-compliance --key scenarioB-ext-compliance.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:01:48.049167+00:00"}}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/scenarioB-ext-compliance.txt could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-compliance/
<d:href>/remote.php/dav/files/admin/ext-compliance/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-compliance.txt%'
{"fileid":87,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioB-ext-compliance.txt.d1795003316","size":52}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795003299","location":"ext-governance","user":"admin"}
{"id":"scenarioB-ext-compliance.txt","timestamp":"1795003316","location":"ext-compliance","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-compliance.txt.d1795003316</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795003299</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix scenarioB-ext-compliance.txt
2026-11-18T12:01:48.049000+00:00  VERSION        latest=false  52B  cNr6zbVXShV6Tecg9zOnYoaJyLIZYge  scenarioB-ext-compliance.txt
2026-11-18T12:01:57.072000+00:00  DELETE-MARKER  latest=true  0B  Mf9w.tTc33w3a.JFtQqLjxNjqbHomDY  scenarioB-ext-compliance.txt

(trash copy in primary storage: urn:oid:87)

$ aws s3api list-object-versions --bucket cmp-ceph-primary --prefix urn:oid:87
2026-11-18T12:01:57.006000+00:00  VERSION        latest=true  52B  Qzug219LjrxWnxAf.tbG9KuZTP0iXqN  urn:oid:87

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### C external mount /ext-governance (bucket cmp-ceph-external), folder of three files

```text
$ curl -X MKCOL http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance 
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c1.txt --data-binary 'C1 ext-governance 2026-11-18T12:02:05Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c2.txt --data-binary 'C2 ext-governance 2026-11-18T12:02:06Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c3.txt --data-binary 'C3 ext-governance 2026-11-18T12:02:07Z'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-governance%'
{"fileid":88,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"folderC-ext-governance","size":114}
{"fileid":89,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"folderC-ext-governance\/c1.txt","size":38}
{"fileid":90,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"folderC-ext-governance\/c2.txt","size":38}
{"fileid":91,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"folderC-ext-governance\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix folderC-ext-governance/
2026-11-18T12:02:04.983000+00:00  VERSION        latest=true  0B  PTIS.dQTnGX8vBJwVJQyAzD-rJndJN5  folderC-ext-governance/
2026-11-18T12:02:05.873000+00:00  VERSION        latest=true  38B  S1ajDYIiqPwM30FqLtKHXt0-frG2r8G  folderC-ext-governance/c1.txt
2026-11-18T12:02:06.791000+00:00  VERSION        latest=true  38B  BAVqBS9nZff8-qJQ8Bw4Ue4OnAgmCwS  folderC-ext-governance/c2.txt
2026-11-18T12:02:07.858000+00:00  VERSION        latest=true  38B  Ki1y3uDz25Iqfqc0ObNhhdhRIBk6VMc  folderC-ext-governance/c3.txt

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance 
HTTP 204

$ curl -X PROPFIND http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/folderC-ext-governance -H Depth: 0
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/folderC-ext-governance could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-governance/
<d:href>/remote.php/dav/files/admin/ext-governance/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-governance%'
{"fileid":88,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795003331","size":114}
{"fileid":89,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795003331\/c1.txt","size":38}
{"fileid":90,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795003331\/c2.txt","size":38}
{"fileid":91,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795003331\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix folderC-ext-governance/
2026-11-18T12:02:04.983000+00:00  VERSION        latest=false  0B  PTIS.dQTnGX8vBJwVJQyAzD-rJndJN5  folderC-ext-governance/
2026-11-18T12:02:12.256000+00:00  DELETE-MARKER  latest=true  0B  n1MRSfcpaTLEUHjWU2iC8nTzc9PIL4c  folderC-ext-governance/
2026-11-18T12:02:05.873000+00:00  VERSION        latest=false  38B  S1ajDYIiqPwM30FqLtKHXt0-frG2r8G  folderC-ext-governance/c1.txt
2026-11-18T12:02:12.257000+00:00  DELETE-MARKER  latest=true  0B  73.ZhzCBjprN9TYWyeNopyBMs14uED0  folderC-ext-governance/c1.txt
2026-11-18T12:02:06.791000+00:00  VERSION        latest=false  38B  BAVqBS9nZff8-qJQ8Bw4Ue4OnAgmCwS  folderC-ext-governance/c2.txt
2026-11-18T12:02:12.257000+00:00  DELETE-MARKER  latest=true  0B  7E0tS4jWcfcho11zXCWL734aV-QLbsY  folderC-ext-governance/c2.txt
2026-11-18T12:02:07.858000+00:00  VERSION        latest=false  38B  Ki1y3uDz25Iqfqc0ObNhhdhRIBk6VMc  folderC-ext-governance/c3.txt
2026-11-18T12:02:12.257000+00:00  DELETE-MARKER  latest=true  0B  .6o0CtRFZHy7rQFbjrzTon4NHHxraZm  folderC-ext-governance/c3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### C external mount /ext-compliance (bucket cmp-ceph-compliance), folder of three files

```text
$ curl -X MKCOL http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2 
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2/c1.txt --data-binary 'C1 ext-compliance 2026-11-18T12:08:32Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2/c2.txt --data-binary 'C2 ext-compliance 2026-11-18T12:08:34Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2/c3.txt --data-binary 'C3 ext-compliance 2026-11-18T12:08:36Z'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-compliance-r2%'
{"fileid":120,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"folderC-ext-compliance-r2","size":114}
{"fileid":121,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"folderC-ext-compliance-r2\/c1.txt","size":38}
{"fileid":122,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"folderC-ext-compliance-r2\/c2.txt","size":38}
{"fileid":123,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"folderC-ext-compliance-r2\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix folderC-ext-compliance-r2/
2026-11-18T12:08:28.277000+00:00  VERSION        latest=true  0B  e6N-4USp.p4ObNMMTcmepyFp9B44H1i  folderC-ext-compliance-r2/
2026-11-18T12:08:33.255000+00:00  VERSION        latest=true  38B  c6ZHVVPvN5J-KCzNUtWfiuxJwWLu9Kf  folderC-ext-compliance-r2/c1.txt
2026-11-18T12:08:35.393000+00:00  VERSION        latest=true  38B  Wl2t.lq6KEC-Vs1o.SXn38iQJn5zcpC  folderC-ext-compliance-r2/c2.txt
2026-11-18T12:08:36.878000+00:00  VERSION        latest=true  38B  m3HeSXyog5QBAPyx6FUev3emvk3u7rP  folderC-ext-compliance-r2/c3.txt

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2 
HTTP 204

$ curl -X PROPFIND http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance-r2 -H Depth: 0
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/folderC-ext-compliance-r2 could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-compliance/
<d:href>/remote.php/dav/files/admin/ext-compliance/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-compliance-r2%'
{"fileid":120,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance-r2.d1795003724","size":114}
{"fileid":121,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance-r2.d1795003724\/c1.txt","size":38}
{"fileid":122,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance-r2.d1795003724\/c2.txt","size":38}
{"fileid":123,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance-r2.d1795003724\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix folderC-ext-compliance-r2/
2026-11-18T12:08:28.277000+00:00  VERSION        latest=false  0B  e6N-4USp.p4ObNMMTcmepyFp9B44H1i  folderC-ext-compliance-r2/
2026-11-18T12:08:45.318000+00:00  DELETE-MARKER  latest=true  0B  dlSU0eNRkt-02rGa2VkB.h5q5CXa3rK  folderC-ext-compliance-r2/
2026-11-18T12:08:33.255000+00:00  VERSION        latest=false  38B  c6ZHVVPvN5J-KCzNUtWfiuxJwWLu9Kf  folderC-ext-compliance-r2/c1.txt
2026-11-18T12:08:45.318000+00:00  DELETE-MARKER  latest=true  0B  Nebw.Gz-bkFkAoRlexgSdBB2Et26q7N  folderC-ext-compliance-r2/c1.txt
2026-11-18T12:08:35.393000+00:00  VERSION        latest=false  38B  Wl2t.lq6KEC-Vs1o.SXn38iQJn5zcpC  folderC-ext-compliance-r2/c2.txt
2026-11-18T12:08:45.319000+00:00  DELETE-MARKER  latest=true  0B  -GVcje4MfZT15WbJFW1TEUJQD4ujh6C  folderC-ext-compliance-r2/c2.txt
2026-11-18T12:08:36.878000+00:00  VERSION        latest=false  38B  m3HeSXyog5QBAPyx6FUev3emvk3u7rP  folderC-ext-compliance-r2/c3.txt
2026-11-18T12:08:45.320000+00:00  DELETE-MARKER  latest=true  0B  LTkFEOxOb0lLXTKc-bLmp1p2EIQcq8R  folderC-ext-compliance-r2/c3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### D external mount /ext-governance (bucket cmp-ceph-external), legal hold on

```text
$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance-r2.txt --data-binary 'D ext-governance 2026-11-18T12:08:51Z legal hold content'
HTTP 201

$ aws s3api put-object-legal-hold --bucket cmp-ceph-external --key scenarioD-ext-governance-r2.txt --legal-hold Status=ON
$ aws s3api get-object-legal-hold --bucket cmp-ceph-external --key scenarioD-ext-governance-r2.txt
{"LegalHold":{"Status":"ON"}}

$ aws s3api get-object-retention --bucket cmp-ceph-external --key scenarioD-ext-governance-r2.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:08:51.714688+00:00"}}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix scenarioD-ext-governance-r2.txt
2026-11-18T12:08:51.714000+00:00  VERSION        latest=true  56B  TamlCC.LOppsgyAzo9nDaaerunXTFNx  scenarioD-ext-governance-r2.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-governance-r2.txt%'
{"fileid":124,"storage":"amazon::external::ea60d372f28cf4307b575582d6d3984f","path":"scenarioD-ext-governance-r2.txt","size":56}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance-r2.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8320/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance-r2.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/scenarioD-ext-governance-r2.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-governance-r2.txt%'
{"fileid":124,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioD-ext-governance-r2.txt.d1795003748","size":56}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix scenarioD-ext-governance-r2.txt
2026-11-18T12:08:51.714000+00:00  VERSION        latest=false  56B  TamlCC.LOppsgyAzo9nDaaerunXTFNx  scenarioD-ext-governance-r2.txt
2026-11-18T12:09:09.232000+00:00  DELETE-MARKER  latest=true  0B  GW5i0ol-DdVwG33d2ZbmpUWipFDWh0H  scenarioD-ext-governance-r2.txt
$ aws s3api get-object-legal-hold --bucket cmp-ceph-external --key scenarioD-ext-governance-r2.txt --version-id TamlCC.LOppsgyAzo9nDaaerunXTFNx
{"LegalHold":{"Status":"ON"}}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### D external mount /ext-compliance (bucket cmp-ceph-compliance), legal hold on

```text
$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance-r2.txt --data-binary 'D ext-compliance 2026-11-18T12:09:20Z legal hold content'
HTTP 201

$ aws s3api put-object-legal-hold --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt --legal-hold Status=ON
$ aws s3api get-object-legal-hold --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt
{"LegalHold":{"Status":"ON"}}

$ aws s3api get-object-retention --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:09:21.288952+00:00"}}

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix scenarioD-ext-compliance-r2.txt
2026-11-18T12:09:21.288000+00:00  VERSION        latest=true  56B  X8AwtgIch-D3bBvgFvseyOL0ZME..Z7  scenarioD-ext-compliance-r2.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-compliance-r2.txt%'
{"fileid":125,"storage":"amazon::external::38b8eb6600419540748c5e5220e68e04","path":"scenarioD-ext-compliance-r2.txt","size":56}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance-r2.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8320/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance-r2.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/scenarioD-ext-compliance-r2.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-compliance-r2.txt%'
{"fileid":125,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioD-ext-compliance-r2.txt.d1795003773","size":56}

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix scenarioD-ext-compliance-r2.txt
2026-11-18T12:09:21.288000+00:00  VERSION        latest=false  56B  X8AwtgIch-D3bBvgFvseyOL0ZME..Z7  scenarioD-ext-compliance-r2.txt
2026-11-18T12:09:33.272000+00:00  DELETE-MARKER  latest=true  0B  J.n1RDx2FZ6niqHRjc7IsPKVBF92IYV  scenarioD-ext-compliance-r2.txt
$ aws s3api get-object-legal-hold --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt --version-id X8AwtgIch-D3bBvgFvseyOL0ZME..Z7
{"LegalHold":{"Status":"ON"}}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### E control: /ext-plain (bucket cmp-ceph-plain, no versioning, no lock)

```text
$ aws s3api get-bucket-versioning --bucket cmp-ceph-plain


$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/scenarioE-r2.txt --data-binary 'E plain 2026-11-18T12:09:44Z'
HTTP 201

$ curl -X MKCOL http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/folderE-r2 
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/folderE-r2/e1.txt --data-binary 'E1'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/folderE-r2/e2.txt --data-binary 'E2'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/folderE-r2/e3.txt --data-binary 'E3'
HTTP 201

$ aws s3api list-object-versions --bucket cmp-ceph-plain
2026-11-18T12:09:46.144000+00:00  VERSION        latest=true  0B  null  folderE-r2/
2026-11-18T12:09:47.016000+00:00  VERSION        latest=true  2B  null  folderE-r2/e1.txt
2026-11-18T12:09:47.892000+00:00  VERSION        latest=true  2B  null  folderE-r2/e2.txt
2026-11-18T12:09:48.866000+00:00  VERSION        latest=true  2B  null  folderE-r2/e3.txt
2026-11-18T12:09:45.223000+00:00  VERSION        latest=true  28B  null  scenarioE-r2.txt

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/scenarioE-r2.txt 
HTTP 204

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-plain/folderE-r2 
HTTP 204

$ PROPFIND depth 1 ext-plain/
<d:href>/remote.php/dav/files/admin/ext-plain/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioE-r2.txt%'
{"fileid":126,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioE-r2.txt.d1795003792","size":28}

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderE-r2%'
{"fileid":127,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE-r2.d1795003793","size":6}
{"fileid":128,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE-r2.d1795003793\/e1.txt","size":2}
{"fileid":129,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE-r2.d1795003793\/e2.txt","size":2}
{"fileid":130,"storage":"object::user:admin","path":"files_trashbin\/files\/folderE-r2.d1795003793\/e3.txt","size":2}

$ aws s3api list-object-versions --bucket cmp-ceph-plain
$ aws s3api head-object --bucket cmp-ceph-plain --key scenarioE-r2.txt
An error occurred (404) when calling the HeadObject operation: Not Found

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### F /ext-nodelete (bucket cmp-ceph-nodelete, s3:DeleteObject denied)

```text
$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt --data-binary 'F 2026-11-18T12:06:18Z'
HTTP 201

$ curl -X MKCOL http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/folderF 
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/folderF/f1.txt --data-binary 'F1'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/folderF/f2.txt --data-binary 'F2'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/folderF/f3.txt --data-binary 'F3'
HTTP 201

$ aws s3api list-object-versions --bucket cmp-ceph-nodelete
Unable to find image 'amazon/aws-cli:2.22.35' locally
2.22.35: Pulling from amazon/aws-cli
9fe0de22bd8b: Pulling fs layer
ac36e229d589: Pulling fs layer
8d181278624a: Pulling fs layer
49cbc54e0893: Pulling fs layer
7dfa1862cffa: Pulling fs layer
49cbc54e0893: Waiting
7dfa1862cffa: Waiting
ac36e229d589: Verifying Checksum
ac36e229d589: Download complete
49cbc54e0893: Download complete
7dfa1862cffa: Download complete
9fe0de22bd8b: Verifying Checksum
9fe0de22bd8b: Download complete
8d181278624a: Verifying Checksum
8d181278624a: Download complete
9fe0de22bd8b: Pull complete
ac36e229d589: Pull complete
8d181278624a: Pull complete
49cbc54e0893: Pull complete
7dfa1862cffa: Pull complete
Digest: sha256:6977c83ae3dc99f28fcf8276b9ea5eec33833cd5be40574b34112e98113ec7a2
Status: Downloaded newer image for amazon/aws-cli:2.22.35
2026-11-18T12:06:20.459000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:06:21.276000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:06:22.318000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:06:23.301000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:06:19.633000+00:00  VERSION        latest=true  22B  null  scenarioF.txt
```

#### F.1 single file delete (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt 
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
{"fileid":103,"storage":"amazon::external::c7366236a1f05d885e49d1997878c41f","path":"scenarioF.txt","size":22}

$ aws s3api list-object-versions --bucket cmp-ceph-nodelete
2026-11-18T12:06:20.459000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:06:21.276000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:06:22.318000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:06:23.301000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:06:19.633000+00:00  VERSION        latest=true  22B  null  scenarioF.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): "}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): "}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): "}
```

#### F.2 folder delete (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-nodelete/folderF 
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
{"fileid":104,"storage":"amazon::external::c7366236a1f05d885e49d1997878c41f","path":"folderF","size":6}
{"fileid":105,"storage":"amazon::external::c7366236a1f05d885e49d1997878c41f","path":"folderF\/f1.txt","size":2}
{"fileid":106,"storage":"amazon::external::c7366236a1f05d885e49d1997878c41f","path":"folderF\/f2.txt","size":2}
{"fileid":107,"storage":"amazon::external::c7366236a1f05d885e49d1997878c41f","path":"folderF\/f3.txt","size":2}

$ aws s3api list-object-versions --bucket cmp-ceph-nodelete
2026-11-18T12:06:20.459000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:06:21.276000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:06:22.318000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:06:23.301000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:06:19.633000+00:00  VERSION        latest=true  22B  null  scenarioF.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): "}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): "}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): "}
```

### G /ext-partial (bucket cmp-ceph-partial, s3:DeleteObject denied under folderG/ only)

```text
$ curl -X MKCOL http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG/g1.txt --data-binary 'G1'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG/g2.txt --data-binary 'G2'
HTTP 201

$ curl -X PUT http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG/g3.txt --data-binary 'G3'
HTTP 201

$ aws s3api list-object-versions --bucket cmp-ceph-partial
2026-11-18T12:07:12.498000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:07:13.436000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:07:14.300000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:07:15.229000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":108,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG","size":6}
{"fileid":109,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g1.txt","size":2}
{"fileid":110,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g2.txt","size":2}
{"fileid":111,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g3.txt","size":2}
```

#### G.1 delete folderG (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204

$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":112,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG","size":6}
{"fileid":113,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g1.txt","size":2}
{"fileid":114,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g2.txt","size":2}
{"fileid":115,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g3.txt","size":2}

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderG.d%'

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795003299","location":"ext-governance","user":"admin"}
{"id":"scenarioB-ext-compliance.txt","timestamp":"1795003316","location":"ext-compliance","user":"admin"}
{"id":"folderC-ext-governance","timestamp":"1795003331","location":"ext-governance","user":"admin"}
{"id":"folderC-ext-compliance","timestamp":"1795003360","location":"ext-compliance","user":"admin"}
{"id":"scenarioD-ext-governance.txt","timestamp":"1795003415","location":"ext-governance","user":"admin"}
{"id":"scenarioD-ext-compliance.txt","timestamp":"1795003489","location":"ext-compliance","user":"admin"}
{"id":"scenarioE.txt","timestamp":"1795003555","location":"ext-plain","user":"admin"}
{"id":"folderE","timestamp":"1795003556","location":"ext-plain","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderE.d1795003556/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioE.txt.d1795003555</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD-ext-compliance.txt.d1795003489</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD-ext-governance.txt.d1795003415</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC-ext-compliance.d1795003360/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC-ext-governance.d1795003331/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-compliance.txt.d1795003316</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795003299</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-ceph-partial
2026-11-18T12:07:12.498000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:07:13.436000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:07:14.300000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:07:15.229000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
```

#### G.2 delete folderG (trash bin off)

```text
$ occ app:disable files_trashbin
files_trashbin 2.0.0 disabled

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":112,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG","size":6}
{"fileid":113,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g1.txt","size":2}
{"fileid":114,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g2.txt","size":2}
{"fileid":115,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g3.txt","size":2}

$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204
(cache immediately after DELETE, before any listing)

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":116,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG","size":6}
{"fileid":117,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g1.txt","size":2}
{"fileid":118,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g2.txt","size":2}
{"fileid":119,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g3.txt","size":2}

$ aws s3api list-object-versions --bucket cmp-ceph-partial
2026-11-18T12:07:12.498000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:07:13.436000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:07:14.300000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:07:15.229000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

$ curl -X PROPFIND http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG -H Depth: 0
HTTP 207
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:response><d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href><d:propstat><d:prop><d:getlastmodified>Wed, 18 Nov 2026 12:07:26 GMT</d:getlastmodified><d:resourcetype><d:collection/></d:resourcetype><d:quota-used-bytes>6</d:quota-used-bytes><d:quota-available-bytes>-3</d:quota-available-bytes><d:getetag>&quot;6afd94fe5ba3a&quot;</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>


$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":116,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG","size":6}
{"fileid":117,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g1.txt","size":2}
{"fileid":118,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g2.txt","size":2}
{"fileid":119,"storage":"amazon::external::79baf873e30db08dba8e41f84539b262","path":"folderG\/g3.txt","size":2}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

#### G.3 control: delete one file inside folderG (trash bin off)

```text
$ curl -X DELETE http://127.0.0.1:8320/remote.php/dav/files/admin/ext-partial/folderG/g1.txt 
HTTP 403
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message/>
</d:error>


$ aws s3api list-object-versions --bucket cmp-ceph-partial
2026-11-18T12:07:12.498000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:07:13.436000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:07:14.300000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:07:15.229000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG/g1.txt","message":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): ","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://10.232.41.10:8080/cmp-ceph-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): "}

$ occ app:enable files_trashbin
files_trashbin 2.0.0 enabled
```

## MinIO run (Nextcloud 35.0.3, cmp-minio-nextcloud)

Run 2026-11-18, 12:14-12:17 UTC. Same MinIO server as the earlier report, new `cmp-` buckets.

### A.3 primary storage, files_trashbin disabled

```text
$ occ app:disable files_trashbin
files_trashbin 2.0.0 disabled

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/scenarioA3.txt --data-binary 'A3 content 2026-11-18T12:14:32Z'
HTTP 201
fileid=80 -> object key urn:oid:80

$ aws s3api list-object-versions --bucket cmp-primary --prefix urn:oid:80
2026-11-18T12:14:34.238000+00:00  VERSION        latest=true  31B  a9a51f79-b52f-4d54-8893-de4fab1e1295  urn:oid:80

$ aws s3api get-object-retention --bucket cmp-primary --key urn:oid:80
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:14:34.238000+00:00"}}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8321/remote.php/dav/files/admin/scenarioA3.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /scenarioA3.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioA3.txt%'

$ aws s3api list-object-versions --bucket cmp-primary --prefix urn:oid:80
2026-11-18T12:14:34.238000+00:00  VERSION        latest=false  31B  a9a51f79-b52f-4d54-8893-de4fab1e1295  urn:oid:80
2026-11-18T12:14:41.325000+00:00  DELETE-MARKER  latest=true  0B  b6f8109c-42ea-44e7-b1fa-cd76c53c9cf5  urn:oid:80

$ aws s3api head-object --bucket cmp-primary --key urn:oid:80

An error occurred (404) when calling the HeadObject operation: Not Found

--- nextcloud.log new entries (level>=2 or delete-related) ---

$ occ app:enable files_trashbin
files_trashbin 2.0.0 enabled
```

### B external mount /ext-governance (bucket cmp-governance)

```text
$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt --data-binary 'B ext-governance 2026-11-18T12:14:51Z locked content'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-governance.txt%'
{"fileid":82,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"scenarioB-ext-governance.txt","size":52}

$ aws s3api list-object-versions --bucket cmp-governance --prefix scenarioB-ext-governance.txt
2026-11-18T12:14:52.463000+00:00  VERSION        latest=true  52B  dabd66fb-003f-4bbd-b73a-c4ebe09d5aa9  scenarioB-ext-governance.txt

$ aws s3api get-object-retention --bucket cmp-governance --key scenarioB-ext-governance.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:14:52.462000+00:00"}}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioB-ext-governance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/scenarioB-ext-governance.txt could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-governance/
<d:href>/remote.php/dav/files/admin/ext-governance/</d:href>
<d:href>/remote.php/dav/files/admin/ext-governance/vprobe/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-governance.txt%'
{"fileid":82,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioB-ext-governance.txt.d1795004100","size":52}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795004100","location":"ext-governance","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795004100</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-governance --prefix scenarioB-ext-governance.txt
2026-11-18T12:14:52.463000+00:00  VERSION        latest=false  52B  dabd66fb-003f-4bbd-b73a-c4ebe09d5aa9  scenarioB-ext-governance.txt
2026-11-18T12:15:00.539000+00:00  DELETE-MARKER  latest=true  0B  d1a8a089-7aae-4bd3-a536-7d99a520b2c5  scenarioB-ext-governance.txt

(trash copy in primary storage: urn:oid:82)

$ aws s3api list-object-versions --bucket cmp-primary --prefix urn:oid:82
2026-11-18T12:15:00.524000+00:00  VERSION        latest=true  52B  796a5d4c-5676-49f0-8329-1457bdd5771a  urn:oid:82

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### B external mount /ext-compliance (bucket cmp-compliance)

```text
$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt --data-binary 'B ext-compliance 2026-11-18T12:15:09Z locked content'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-compliance.txt%'
{"fileid":88,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"scenarioB-ext-compliance.txt","size":52}

$ aws s3api list-object-versions --bucket cmp-compliance --prefix scenarioB-ext-compliance.txt
2026-11-18T12:15:09.707000+00:00  VERSION        latest=true  52B  2ce899db-3668-4338-88dd-7323fbf0c0a5  scenarioB-ext-compliance.txt

$ aws s3api get-object-retention --bucket cmp-compliance --key scenarioB-ext-compliance.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:15:09.706000+00:00"}}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioB-ext-compliance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/scenarioB-ext-compliance.txt could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-compliance/
<d:href>/remote.php/dav/files/admin/ext-compliance/</d:href>
<d:href>/remote.php/dav/files/admin/ext-compliance/vprobe/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioB-ext-compliance.txt%'
{"fileid":88,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioB-ext-compliance.txt.d1795004117","size":52}

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795004100","location":"ext-governance","user":"admin"}
{"id":"scenarioB-ext-compliance.txt","timestamp":"1795004117","location":"ext-compliance","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-compliance.txt.d1795004117</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795004100</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-compliance --prefix scenarioB-ext-compliance.txt
2026-11-18T12:15:09.707000+00:00  VERSION        latest=false  52B  2ce899db-3668-4338-88dd-7323fbf0c0a5  scenarioB-ext-compliance.txt
2026-11-18T12:15:17.547000+00:00  DELETE-MARKER  latest=true  0B  d8d077df-5063-425d-ba45-f6a0a0652725  scenarioB-ext-compliance.txt

(trash copy in primary storage: urn:oid:88)

$ aws s3api list-object-versions --bucket cmp-primary --prefix urn:oid:88
2026-11-18T12:15:17.529000+00:00  VERSION        latest=true  52B  1885f08c-f9a6-46a9-89a9-b8e79a532c07  urn:oid:88

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### C external mount /ext-governance (bucket cmp-governance), folder of three files

```text
$ curl -X MKCOL http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance 
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c1.txt --data-binary 'C1 ext-governance 2026-11-18T12:15:29Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c2.txt --data-binary 'C2 ext-governance 2026-11-18T12:15:30Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance/c3.txt --data-binary 'C3 ext-governance 2026-11-18T12:15:31Z'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-governance%'
{"fileid":89,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"folderC-ext-governance","size":114}
{"fileid":90,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"folderC-ext-governance\/c1.txt","size":38}
{"fileid":91,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"folderC-ext-governance\/c2.txt","size":38}
{"fileid":92,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"folderC-ext-governance\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-governance --prefix folderC-ext-governance/
2026-11-18T12:15:29.168000+00:00  VERSION        latest=true  0B  null  folderC-ext-governance/
2026-11-18T12:15:30.341000+00:00  VERSION        latest=true  38B  f6ab10fb-9db9-42d0-9315-d81254420300  folderC-ext-governance/c1.txt
2026-11-18T12:15:31.404000+00:00  VERSION        latest=true  38B  7f6da2ee-4815-4564-b9d9-39a2a00942bd  folderC-ext-governance/c2.txt
2026-11-18T12:15:32.503000+00:00  VERSION        latest=true  38B  eee8ded6-6f75-4573-92cb-0b3d3fe8f55a  folderC-ext-governance/c3.txt

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance 
HTTP 204

$ curl -X PROPFIND http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/folderC-ext-governance -H Depth: 0
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/folderC-ext-governance could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-governance/
<d:href>/remote.php/dav/files/admin/ext-governance/</d:href>
<d:href>/remote.php/dav/files/admin/ext-governance/vprobe/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-governance%'
{"fileid":89,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795004136","size":114}
{"fileid":90,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795004136\/c1.txt","size":38}
{"fileid":91,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795004136\/c2.txt","size":38}
{"fileid":92,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-governance.d1795004136\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-governance --prefix folderC-ext-governance/
2026-11-18T12:15:30.341000+00:00  VERSION        latest=false  38B  f6ab10fb-9db9-42d0-9315-d81254420300  folderC-ext-governance/c1.txt
2026-11-18T12:15:37.031000+00:00  DELETE-MARKER  latest=true  0B  e42d7f86-882d-4d07-a094-bb8e8b2fc5df  folderC-ext-governance/c1.txt
2026-11-18T12:15:31.404000+00:00  VERSION        latest=false  38B  7f6da2ee-4815-4564-b9d9-39a2a00942bd  folderC-ext-governance/c2.txt
2026-11-18T12:15:37.031000+00:00  DELETE-MARKER  latest=true  0B  3cd136da-aaaf-4824-b341-762d7500d933  folderC-ext-governance/c2.txt
2026-11-18T12:15:32.503000+00:00  VERSION        latest=false  38B  eee8ded6-6f75-4573-92cb-0b3d3fe8f55a  folderC-ext-governance/c3.txt
2026-11-18T12:15:37.031000+00:00  DELETE-MARKER  latest=true  0B  8156e9ea-51f1-4aea-ba41-5b36b38dd182  folderC-ext-governance/c3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### C external mount /ext-compliance (bucket cmp-compliance), folder of three files

```text
$ curl -X MKCOL http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance 
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance/c1.txt --data-binary 'C1 ext-compliance 2026-11-18T12:15:42Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance/c2.txt --data-binary 'C2 ext-compliance 2026-11-18T12:15:44Z'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance/c3.txt --data-binary 'C3 ext-compliance 2026-11-18T12:15:45Z'
HTTP 201

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-compliance%'
{"fileid":93,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"folderC-ext-compliance","size":114}
{"fileid":94,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"folderC-ext-compliance\/c1.txt","size":38}
{"fileid":95,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"folderC-ext-compliance\/c2.txt","size":38}
{"fileid":96,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"folderC-ext-compliance\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-compliance --prefix folderC-ext-compliance/
2026-11-18T12:15:42.511000+00:00  VERSION        latest=true  0B  null  folderC-ext-compliance/
2026-11-18T12:15:43.580000+00:00  VERSION        latest=true  38B  3d37401d-b2f6-43dc-95f3-a37bd230e18c  folderC-ext-compliance/c1.txt
2026-11-18T12:15:44.697000+00:00  VERSION        latest=true  38B  faa1f88a-f41e-448b-806e-199d88b9e68d  folderC-ext-compliance/c2.txt
2026-11-18T12:15:45.586000+00:00  VERSION        latest=true  38B  2301f229-87f3-4ba1-9074-06d982eba4e5  folderC-ext-compliance/c3.txt

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance 
HTTP 204

$ curl -X PROPFIND http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/folderC-ext-compliance -H Depth: 0
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/folderC-ext-compliance could not be located</s:message>
</d:error>


$ PROPFIND depth 1 ext-compliance/
<d:href>/remote.php/dav/files/admin/ext-compliance/</d:href>
<d:href>/remote.php/dav/files/admin/ext-compliance/vprobe/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderC-ext-compliance%'
{"fileid":93,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance.d1795004149","size":114}
{"fileid":94,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance.d1795004149\/c1.txt","size":38}
{"fileid":95,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance.d1795004149\/c2.txt","size":38}
{"fileid":96,"storage":"object::user:admin","path":"files_trashbin\/files\/folderC-ext-compliance.d1795004149\/c3.txt","size":38}

$ aws s3api list-object-versions --bucket cmp-compliance --prefix folderC-ext-compliance/
2026-11-18T12:15:43.580000+00:00  VERSION        latest=false  38B  3d37401d-b2f6-43dc-95f3-a37bd230e18c  folderC-ext-compliance/c1.txt
2026-11-18T12:15:49.750000+00:00  DELETE-MARKER  latest=true  0B  0354f035-5ad1-4fb4-8003-e22a6abb78cb  folderC-ext-compliance/c1.txt
2026-11-18T12:15:44.697000+00:00  VERSION        latest=false  38B  faa1f88a-f41e-448b-806e-199d88b9e68d  folderC-ext-compliance/c2.txt
2026-11-18T12:15:49.750000+00:00  DELETE-MARKER  latest=true  0B  63276e1c-4672-4f3b-b80d-b4b39a2ed1fe  folderC-ext-compliance/c2.txt
2026-11-18T12:15:45.586000+00:00  VERSION        latest=false  38B  2301f229-87f3-4ba1-9074-06d982eba4e5  folderC-ext-compliance/c3.txt
2026-11-18T12:15:49.750000+00:00  DELETE-MARKER  latest=true  0B  98c92929-b55f-4b01-992b-e004924e4bb5  folderC-ext-compliance/c3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### D external mount /ext-governance (bucket cmp-governance), legal hold on

```text
$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance.txt --data-binary 'D ext-governance 2026-11-18T12:15:54Z legal hold content'
HTTP 201

$ aws s3api put-object-legal-hold --bucket cmp-governance --key scenarioD-ext-governance.txt --legal-hold Status=ON
$ aws s3api get-object-legal-hold --bucket cmp-governance --key scenarioD-ext-governance.txt
{"LegalHold":{"Status":"ON"}}

$ aws s3api get-object-retention --bucket cmp-governance --key scenarioD-ext-governance.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:15:55+00:00"}}

$ aws s3api list-object-versions --bucket cmp-governance --prefix scenarioD-ext-governance.txt
2026-11-18T12:15:55+00:00  VERSION        latest=true  56B  feb98743-8232-45ce-bf24-31ad2c0b0fec  scenarioD-ext-governance.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-governance.txt%'
{"fileid":97,"storage":"amazon::external::1db81e7bf2a4c8903caf0a96747bc9dc","path":"scenarioD-ext-governance.txt","size":56}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8321/remote.php/dav/files/admin/ext-governance/scenarioD-ext-governance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-governance/scenarioD-ext-governance.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-governance.txt%'
{"fileid":97,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioD-ext-governance.txt.d1795004168","size":56}

$ aws s3api list-object-versions --bucket cmp-governance --prefix scenarioD-ext-governance.txt
2026-11-18T12:15:55+00:00  VERSION        latest=false  56B  feb98743-8232-45ce-bf24-31ad2c0b0fec  scenarioD-ext-governance.txt
2026-11-18T12:16:08.061000+00:00  DELETE-MARKER  latest=true  0B  736288df-0f07-4df8-b195-ccc6f40e4014  scenarioD-ext-governance.txt
$ aws s3api get-object-legal-hold --bucket cmp-governance --key scenarioD-ext-governance.txt --version-id feb98743-8232-45ce-bf24-31ad2c0b0fec
{"LegalHold":{"Status":"ON"}}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### D external mount /ext-compliance (bucket cmp-compliance), legal hold on

```text
$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance.txt --data-binary 'D ext-compliance 2026-11-18T12:16:17Z legal hold content'
HTTP 201

$ aws s3api put-object-legal-hold --bucket cmp-compliance --key scenarioD-ext-compliance.txt --legal-hold Status=ON
$ aws s3api get-object-legal-hold --bucket cmp-compliance --key scenarioD-ext-compliance.txt
{"LegalHold":{"Status":"ON"}}

$ aws s3api get-object-retention --bucket cmp-compliance --key scenarioD-ext-compliance.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:16:18.127000+00:00"}}

$ aws s3api list-object-versions --bucket cmp-compliance --prefix scenarioD-ext-compliance.txt
2026-11-18T12:16:18.127000+00:00  VERSION        latest=true  56B  25abe4bd-5124-451e-b32f-4a64db567e44  scenarioD-ext-compliance.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-compliance.txt%'
{"fileid":98,"storage":"amazon::external::7ec237fef7bb425e2ca47b236a4f4713","path":"scenarioD-ext-compliance.txt","size":56}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance.txt 
HTTP 204

$ curl -X GET http://127.0.0.1:8321/remote.php/dav/files/admin/ext-compliance/scenarioD-ext-compliance.txt 
HTTP 404
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\NotFound</s:exception>
  <s:message>File with name /ext-compliance/scenarioD-ext-compliance.txt could not be located</s:message>
</d:error>


SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%scenarioD-ext-compliance.txt%'
{"fileid":98,"storage":"object::user:admin","path":"files_trashbin\/files\/scenarioD-ext-compliance.txt.d1795004192","size":56}

$ aws s3api list-object-versions --bucket cmp-compliance --prefix scenarioD-ext-compliance.txt
2026-11-18T12:16:18.127000+00:00  VERSION        latest=false  56B  25abe4bd-5124-451e-b32f-4a64db567e44  scenarioD-ext-compliance.txt
2026-11-18T12:16:32.622000+00:00  DELETE-MARKER  latest=true  0B  aa30cb93-934f-44d3-b965-ce0eeab9a6b0  scenarioD-ext-compliance.txt
$ aws s3api get-object-legal-hold --bucket cmp-compliance --key scenarioD-ext-compliance.txt --version-id 25abe4bd-5124-451e-b32f-4a64db567e44
{"LegalHold":{"Status":"ON"}}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

### F /ext-nodelete (bucket cmp-nodelete, s3:DeleteObject denied)

```text
$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt --data-binary 'F 2026-11-18T12:16:44Z'
HTTP 201

$ curl -X MKCOL http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/folderF 
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/folderF/f1.txt --data-binary 'F1'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/folderF/f2.txt --data-binary 'F2'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/folderF/f3.txt --data-binary 'F3'
HTTP 201

$ aws s3api list-object-versions --bucket cmp-nodelete
2026-11-18T12:16:46.412000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:16:47.226000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:16:48.450000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:16:49.602000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:16:45.652000+00:00  VERSION        latest=true  22B  null  scenarioF.txt
```

#### F.1 single file delete (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt 
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
{"fileid":99,"storage":"amazon::external::3eaad97d8661f9d29a64377c827564c7","path":"scenarioF.txt","size":22}

$ aws s3api list-object-versions --bucket cmp-nodelete
2026-11-18T12:16:46.412000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:16:47.226000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:16:48.450000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:16:49.602000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:16:45.652000+00:00  VERSION        latest=true  22B  null  scenarioF.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/scenarioF.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/scenarioF.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
```

#### F.2 folder delete (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-nodelete/folderF 
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
{"fileid":100,"storage":"amazon::external::3eaad97d8661f9d29a64377c827564c7","path":"folderF","size":6}
{"fileid":101,"storage":"amazon::external::3eaad97d8661f9d29a64377c827564c7","path":"folderF\/f1.txt","size":2}
{"fileid":102,"storage":"amazon::external::3eaad97d8661f9d29a64377c827564c7","path":"folderF\/f2.txt","size":2}
{"fileid":103,"storage":"amazon::external::3eaad97d8661f9d29a64377c827564c7","path":"folderF\/f3.txt","size":2}

$ aws s3api list-object-versions --bucket cmp-nodelete
2026-11-18T12:16:46.412000+00:00  VERSION        latest=true  0B  null  folderF/
2026-11-18T12:16:47.226000+00:00  VERSION        latest=true  2B  null  folderF/f1.txt
2026-11-18T12:16:48.450000+00:00  VERSION        latest=true  2B  null  folderF/f2.txt
2026-11-18T12:16:49.602000+00:00  VERSION        latest=true  2B  null  folderF/f3.txt
2026-11-18T12:16:45.652000+00:00  VERSION        latest=true  22B  null  scenarioF.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-nodelete/folderF","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-nodelete/folderF\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}
```

### G /ext-partial (bucket cmp-partial, s3:DeleteObject denied under folderG/ only)

```text
$ curl -X MKCOL http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG/g1.txt --data-binary 'G1'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG/g2.txt --data-binary 'G2'
HTTP 201

$ curl -X PUT http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG/g3.txt --data-binary 'G3'
HTTP 201

$ aws s3api list-object-versions --bucket cmp-partial
2026-11-18T12:17:04.603000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:17:05.324000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:17:06.166000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:17:06.979000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":104,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG","size":6}
{"fileid":105,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g1.txt","size":2}
{"fileid":106,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g2.txt","size":2}
{"fileid":107,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g3.txt","size":2}
```

#### G.1 delete folderG (trash bin on)

```text
$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204

$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":108,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG","size":6}
{"fileid":109,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g1.txt","size":2}
{"fileid":110,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g2.txt","size":2}
{"fileid":111,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g3.txt","size":2}

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like '%folderG.d%'

SQL> select id,timestamp,location,user from oc_files_trash
{"id":"scenarioB-ext-governance.txt","timestamp":"1795004100","location":"ext-governance","user":"admin"}
{"id":"scenarioB-ext-compliance.txt","timestamp":"1795004117","location":"ext-compliance","user":"admin"}
{"id":"folderC-ext-governance","timestamp":"1795004136","location":"ext-governance","user":"admin"}
{"id":"folderC-ext-compliance","timestamp":"1795004149","location":"ext-compliance","user":"admin"}
{"id":"scenarioD-ext-governance.txt","timestamp":"1795004168","location":"ext-governance","user":"admin"}
{"id":"scenarioD-ext-compliance.txt","timestamp":"1795004192","location":"ext-compliance","user":"admin"}
$ PROPFIND trashbin
<d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD-ext-compliance.txt.d1795004192</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioD-ext-governance.txt.d1795004168</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC-ext-compliance.d1795004149/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/folderC-ext-governance.d1795004136/</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-compliance.txt.d1795004117</d:href>
<d:href>/remote.php/dav/trashbin/admin/trash/scenarioB-ext-governance.txt.d1795004100</d:href>
HTTP 207

$ aws s3api list-object-versions --bucket cmp-partial
2026-11-18T12:17:04.603000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:17:05.324000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:17:06.166000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:17:06.979000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_trashbin","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG","message":"trash move failed, removing trash metadata and payload","exc":null,"excmsg":""}
```

#### G.2 delete folderG (trash bin off)

```text
$ occ app:disable files_trashbin
files_trashbin 2.0.0 disabled

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":108,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG","size":6}
{"fileid":109,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g1.txt","size":2}
{"fileid":110,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g2.txt","size":2}
{"fileid":111,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g3.txt","size":2}

$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG 
HTTP 204
(cache immediately after DELETE, before any listing)

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":112,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG","size":6}
{"fileid":113,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g1.txt","size":2}
{"fileid":114,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g2.txt","size":2}
{"fileid":115,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g3.txt","size":2}

$ aws s3api list-object-versions --bucket cmp-partial
2026-11-18T12:17:04.603000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:17:05.324000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:17:06.166000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:17:06.979000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

$ curl -X PROPFIND http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG -H Depth: 0
HTTP 207
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:response><d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href><d:propstat><d:prop><d:getlastmodified>Wed, 18 Nov 2026 12:17:17 GMT</d:getlastmodified><d:resourcetype><d:collection/></d:resourcetype><d:quota-used-bytes>6</d:quota-used-bytes><d:quota-available-bytes>-3</d:quota-available-bytes><d:getetag>&quot;6afd974d8b7b6&quot;</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>


$ PROPFIND depth 1 ext-partial/
<d:href>/remote.php/dav/files/admin/ext-partial/</d:href>
<d:href>/remote.php/dav/files/admin/ext-partial/folderG/</d:href>
HTTP 207

SQL> select f.fileid,s.id storage,f.path,f.size from oc_filecache f join oc_storages s on s.numeric_id=f.storage where f.path like 'folderG%'
{"fileid":112,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG","size":6}
{"fileid":113,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g1.txt","size":2}
{"fileid":114,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g2.txt","size":2}
{"fileid":115,"storage":"amazon::external::2ee33053102027a3bd5d7edd5907c3a9","path":"folderG\/g3.txt","size":2}

--- nextcloud.log new entries (level>=2 or delete-related) ---
```

#### G.3 control: delete one file inside folderG (trash bin off)

```text
$ curl -X DELETE http://127.0.0.1:8321/remote.php/dav/files/admin/ext-partial/folderG/g1.txt 
HTTP 403
<?xml version="1.0" encoding="utf-8"?>
<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>
  <s:message/>
</d:error>


$ aws s3api list-object-versions --bucket cmp-partial
2026-11-18T12:17:04.603000+00:00  VERSION        latest=true  0B  null  folderG/
2026-11-18T12:17:05.324000+00:00  VERSION        latest=true  2B  null  folderG/g1.txt
2026-11-18T12:17:06.166000+00:00  VERSION        latest=true  2B  null  folderG/g2.txt
2026-11-18T12:17:06.979000+00:00  VERSION        latest=true  2B  null  folderG/g3.txt

--- nextcloud.log new entries (level>=2 or delete-related) ---
{"level":3,"app":"files_external","method":"DELETE","url":"/remote.php/dav/files/admin/ext-partial/folderG/g1.txt","message":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied.","exc":"Aws\\S3\\Exception\\S3Exception","excmsg":"Error executing \"DeleteObject\" on \"http://vng-ol-minio:9000/cmp-partial/folderG/g1.txt\"; AWS HTTP error:\nAccessDenied (client): Access Denied."}

$ occ app:enable files_trashbin
files_trashbin 2.0.0 enabled
```

## Version-specific deletes

What a version-aware Nextcloud would hit. Each object is uploaded directly with the bucket owner credentials (root on MinIO). The `aws` calls use the CLI; the `php` calls use the AWS SDK bundled with Nextcloud 35.0.3 (3.398.3), run inside the Nextcloud container. `batch` is `DeleteObjects` with `Quiet: true` and a `VersionId`, the call a version-aware `batchDelete()` would make. Refusal attempts run first, the bypass attempt last.

### Ceph RGW

```text
Ceph RGW, 2026-11-18T12:10:45Z

== cmp-ceph-external / vprobe/gov-aws.txt via aws
$ aws s3api put-object --bucket cmp-ceph-external --key vprobe/gov-aws.txt --body vprobe.txt
VersionId: NrzTtYYQ.GoX2yNEOVK4M320AaubbbD

$ aws s3api get-object-retention --bucket cmp-ceph-external --key vprobe/gov-aws.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:10:48.357338+00:00"}}
$ aws s3api delete-object --bucket cmp-ceph-external --key vprobe/gov-aws.txt --version-id NrzTtYYQ.GoX2yNEOVK4M320AaubbbD

An error occurred (AccessDenied) when calling the DeleteObject operation: forbidden by object lock
$ aws s3api delete-object --bucket cmp-ceph-external --key vprobe/gov-aws.txt --version-id NrzTtYYQ.GoX2yNEOVK4M320AaubbbD --bypass-governance-retention
    "VersionId": "NrzTtYYQ.GoX2yNEOVK4M320AaubbbD"
}

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix vprobe/gov-aws.txt

== cmp-ceph-compliance / vprobe/comp-aws.txt via aws
$ aws s3api put-object --bucket cmp-ceph-compliance --key vprobe/comp-aws.txt --body vprobe.txt
VersionId: VOeQKMgkYBop2oLJNOJa-Tzi7mssUX0

$ aws s3api get-object-retention --bucket cmp-ceph-compliance --key vprobe/comp-aws.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:11:01.662978+00:00"}}
$ aws s3api delete-object --bucket cmp-ceph-compliance --key vprobe/comp-aws.txt --version-id VOeQKMgkYBop2oLJNOJa-Tzi7mssUX0

An error occurred (AccessDenied) when calling the DeleteObject operation: forbidden by object lock
$ aws s3api delete-object --bucket cmp-ceph-compliance --key vprobe/comp-aws.txt --version-id VOeQKMgkYBop2oLJNOJa-Tzi7mssUX0 --bypass-governance-retention

An error occurred (AccessDenied) when calling the DeleteObject operation: forbidden by object lock

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix vprobe/comp-aws.txt
2026-11-18T12:11:01.662000+00:00  VERSION        latest=true  47B  VOeQKMgkYBop2oLJNOJa-Tzi7mssUX0  vprobe/comp-aws.txt

== cmp-ceph-external / vprobe/gov-hold-aws.txt via aws (legal hold ON)
$ aws s3api put-object --bucket cmp-ceph-external --key vprobe/gov-hold-aws.txt --body vprobe.txt
VersionId: 4QnBcmqgwGdOJfZPgxypfkoxgv0tFkY
$ aws s3api put-object-legal-hold --bucket cmp-ceph-external --key vprobe/gov-hold-aws.txt --legal-hold Status=ON

$ aws s3api get-object-retention --bucket cmp-ceph-external --key vprobe/gov-hold-aws.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:11:17.043164+00:00"}}
$ aws s3api delete-object --bucket cmp-ceph-external --key vprobe/gov-hold-aws.txt --version-id 4QnBcmqgwGdOJfZPgxypfkoxgv0tFkY

An error occurred (AccessDenied) when calling the DeleteObject operation: forbidden by object lock
$ aws s3api delete-object --bucket cmp-ceph-external --key vprobe/gov-hold-aws.txt --version-id 4QnBcmqgwGdOJfZPgxypfkoxgv0tFkY --bypass-governance-retention

An error occurred (AccessDenied) when calling the DeleteObject operation: forbidden by object lock

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix vprobe/gov-hold-aws.txt
2026-11-18T12:11:17.043000+00:00  VERSION        latest=true  51B  4QnBcmqgwGdOJfZPgxypfkoxgv0tFkY  vprobe/gov-hold-aws.txt

== cmp-ceph-external / vprobe/gov-php.txt via php
$ aws s3api put-object --bucket cmp-ceph-external --key vprobe/gov-php.txt --body vprobe.txt
VersionId: Iohxp3owrgMueHqMYRlsQQrDRmtn5pg

$ aws s3api get-object-retention --bucket cmp-ceph-external --key vprobe/gov-php.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:11:38.570093+00:00"}}
$ php version-probe.php plain cmp-ceph-external vprobe/gov-php.txt Iohxp3owrgMueHqMYRlsQQrDRmtn5pg
HTTP 403 AccessDenied: "forbidden by object lock"
$ php version-probe.php batch cmp-ceph-external vprobe/gov-php.txt Iohxp3owrgMueHqMYRlsQQrDRmtn5pg
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/gov-php.txt","VersionId":"Iohxp3owrgMueHqMYRlsQQrDRmtn5pg","Code":"AccessDenied","Message":"AccessDenied"}]
$ php version-probe.php bypass cmp-ceph-external vprobe/gov-php.txt Iohxp3owrgMueHqMYRlsQQrDRmtn5pg
HTTP 204 (version deleted)

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix vprobe/gov-php.txt

== cmp-ceph-compliance / vprobe/comp-php.txt via php
$ aws s3api put-object --bucket cmp-ceph-compliance --key vprobe/comp-php.txt --body vprobe.txt
VersionId: bGUU0EhgnueawEX2evuc3UceaDUwdBS

$ aws s3api get-object-retention --bucket cmp-ceph-compliance --key vprobe/comp-php.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:11:49.333057+00:00"}}
$ php version-probe.php plain cmp-ceph-compliance vprobe/comp-php.txt bGUU0EhgnueawEX2evuc3UceaDUwdBS
HTTP 403 AccessDenied: "forbidden by object lock"
$ php version-probe.php batch cmp-ceph-compliance vprobe/comp-php.txt bGUU0EhgnueawEX2evuc3UceaDUwdBS
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/comp-php.txt","VersionId":"bGUU0EhgnueawEX2evuc3UceaDUwdBS","Code":"AccessDenied","Message":"AccessDenied"}]
$ php version-probe.php bypass cmp-ceph-compliance vprobe/comp-php.txt bGUU0EhgnueawEX2evuc3UceaDUwdBS
HTTP 403 AccessDenied: "forbidden by object lock"

$ aws s3api list-object-versions --bucket cmp-ceph-compliance --prefix vprobe/comp-php.txt
2026-11-18T12:11:49.333000+00:00  VERSION        latest=true  47B  bGUU0EhgnueawEX2evuc3UceaDUwdBS  vprobe/comp-php.txt

== cmp-ceph-external / vprobe/gov-hold-php.txt via php (legal hold ON)
$ aws s3api put-object --bucket cmp-ceph-external --key vprobe/gov-hold-php.txt --body vprobe.txt
VersionId: G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-
$ aws s3api put-object-legal-hold --bucket cmp-ceph-external --key vprobe/gov-hold-php.txt --legal-hold Status=ON

$ aws s3api get-object-retention --bucket cmp-ceph-external --key vprobe/gov-hold-php.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:11:59.021371+00:00"}}
$ php version-probe.php plain cmp-ceph-external vprobe/gov-hold-php.txt G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-
HTTP 403 AccessDenied: "forbidden by object lock"
$ php version-probe.php batch cmp-ceph-external vprobe/gov-hold-php.txt G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/gov-hold-php.txt","VersionId":"G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-","Code":"AccessDenied","Message":"AccessDenied"}]
$ php version-probe.php bypass cmp-ceph-external vprobe/gov-hold-php.txt G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-
HTTP 403 AccessDenied: "forbidden by object lock"

$ aws s3api list-object-versions --bucket cmp-ceph-external --prefix vprobe/gov-hold-php.txt
2026-11-18T12:11:59.021000+00:00  VERSION        latest=true  51B  G2IdWiv85p3yOQcLGEn6doU2Rr5-ym-  vprobe/gov-hold-php.txt
```

### MinIO

```text
MinIO, 2026-11-18T12:12:09Z

== cmp-governance / vprobe/gov-aws.txt via aws
$ aws s3api put-object --bucket cmp-governance --key vprobe/gov-aws.txt --body vprobe.txt
VersionId: d5337018-347e-43e7-95f4-b9ea92bcab32

$ aws s3api get-object-retention --bucket cmp-governance --key vprobe/gov-aws.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:12:11.370000+00:00"}}
$ aws s3api delete-object --bucket cmp-governance --key vprobe/gov-aws.txt --version-id d5337018-347e-43e7-95f4-b9ea92bcab32

An error occurred (InvalidRequest) when calling the DeleteObject operation: Object is WORM protected and cannot be overwritten
$ aws s3api delete-object --bucket cmp-governance --key vprobe/gov-aws.txt --version-id d5337018-347e-43e7-95f4-b9ea92bcab32 --bypass-governance-retention
    "VersionId": "d5337018-347e-43e7-95f4-b9ea92bcab32"
}

$ aws s3api list-object-versions --bucket cmp-governance --prefix vprobe/gov-aws.txt

== cmp-compliance / vprobe/comp-aws.txt via aws
$ aws s3api put-object --bucket cmp-compliance --key vprobe/comp-aws.txt --body vprobe.txt
VersionId: da5b28fc-e8f9-4d90-9723-3432d8f0541e

$ aws s3api get-object-retention --bucket cmp-compliance --key vprobe/comp-aws.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:12:43.710000+00:00"}}
$ aws s3api delete-object --bucket cmp-compliance --key vprobe/comp-aws.txt --version-id da5b28fc-e8f9-4d90-9723-3432d8f0541e

An error occurred (InvalidRequest) when calling the DeleteObject operation: Object is WORM protected and cannot be overwritten
$ aws s3api delete-object --bucket cmp-compliance --key vprobe/comp-aws.txt --version-id da5b28fc-e8f9-4d90-9723-3432d8f0541e --bypass-governance-retention

An error occurred (InvalidRequest) when calling the DeleteObject operation: Object is WORM protected and cannot be overwritten

$ aws s3api list-object-versions --bucket cmp-compliance --prefix vprobe/comp-aws.txt
2026-11-18T12:12:43.710000+00:00  VERSION        latest=true  47B  da5b28fc-e8f9-4d90-9723-3432d8f0541e  vprobe/comp-aws.txt

== cmp-governance / vprobe/gov-hold-aws.txt via aws (legal hold ON)
$ aws s3api put-object --bucket cmp-governance --key vprobe/gov-hold-aws.txt --body vprobe.txt
VersionId: 8f50c4ad-9b50-4b90-bd06-3f00a52de8f2
$ aws s3api put-object-legal-hold --bucket cmp-governance --key vprobe/gov-hold-aws.txt --legal-hold Status=ON

$ aws s3api get-object-retention --bucket cmp-governance --key vprobe/gov-hold-aws.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:13:16.307000+00:00"}}
$ aws s3api delete-object --bucket cmp-governance --key vprobe/gov-hold-aws.txt --version-id 8f50c4ad-9b50-4b90-bd06-3f00a52de8f2

An error occurred (InvalidRequest) when calling the DeleteObject operation: Object is WORM protected and cannot be overwritten
$ aws s3api delete-object --bucket cmp-governance --key vprobe/gov-hold-aws.txt --version-id 8f50c4ad-9b50-4b90-bd06-3f00a52de8f2 --bypass-governance-retention

An error occurred (InvalidRequest) when calling the DeleteObject operation: Object is WORM protected and cannot be overwritten

$ aws s3api list-object-versions --bucket cmp-governance --prefix vprobe/gov-hold-aws.txt
2026-11-18T12:13:16.308000+00:00  VERSION        latest=true  51B  8f50c4ad-9b50-4b90-bd06-3f00a52de8f2  vprobe/gov-hold-aws.txt

== cmp-governance / vprobe/gov-php.txt via php
$ aws s3api put-object --bucket cmp-governance --key vprobe/gov-php.txt --body vprobe.txt
VersionId: 7bf1db04-8592-41c7-8a9f-b3120cf7ad44

$ aws s3api get-object-retention --bucket cmp-governance --key vprobe/gov-php.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:13:44.674000+00:00"}}
$ php version-probe.php plain cmp-governance vprobe/gov-php.txt 7bf1db04-8592-41c7-8a9f-b3120cf7ad44
HTTP 400 InvalidRequest: "Object is WORM protected and cannot be overwritten"
$ php version-probe.php batch cmp-governance vprobe/gov-php.txt 7bf1db04-8592-41c7-8a9f-b3120cf7ad44
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/gov-php.txt","VersionId":"7bf1db04-8592-41c7-8a9f-b3120cf7ad44","Code":"InvalidRequest","Message":"Object is WORM protected and cannot be overwritten"}]
$ php version-probe.php bypass cmp-governance vprobe/gov-php.txt 7bf1db04-8592-41c7-8a9f-b3120cf7ad44
HTTP 204 (version deleted)

$ aws s3api list-object-versions --bucket cmp-governance --prefix vprobe/gov-php.txt

== cmp-compliance / vprobe/comp-php.txt via php
$ aws s3api put-object --bucket cmp-compliance --key vprobe/comp-php.txt --body vprobe.txt
VersionId: a8fa0bcc-9a36-4d12-957e-b87ee2e507f9

$ aws s3api get-object-retention --bucket cmp-compliance --key vprobe/comp-php.txt
{"Retention":{"Mode":"COMPLIANCE","RetainUntilDate":"2026-11-19T12:13:55.138000+00:00"}}
$ php version-probe.php plain cmp-compliance vprobe/comp-php.txt a8fa0bcc-9a36-4d12-957e-b87ee2e507f9
HTTP 400 InvalidRequest: "Object is WORM protected and cannot be overwritten"
$ php version-probe.php batch cmp-compliance vprobe/comp-php.txt a8fa0bcc-9a36-4d12-957e-b87ee2e507f9
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/comp-php.txt","VersionId":"a8fa0bcc-9a36-4d12-957e-b87ee2e507f9","Code":"InvalidRequest","Message":"Object is WORM protected and cannot be overwritten"}]
$ php version-probe.php bypass cmp-compliance vprobe/comp-php.txt a8fa0bcc-9a36-4d12-957e-b87ee2e507f9
HTTP 400 InvalidRequest: "Object is WORM protected and cannot be overwritten"

$ aws s3api list-object-versions --bucket cmp-compliance --prefix vprobe/comp-php.txt
2026-11-18T12:13:55.139000+00:00  VERSION        latest=true  47B  a8fa0bcc-9a36-4d12-957e-b87ee2e507f9  vprobe/comp-php.txt

== cmp-governance / vprobe/gov-hold-php.txt via php (legal hold ON)
$ aws s3api put-object --bucket cmp-governance --key vprobe/gov-hold-php.txt --body vprobe.txt
VersionId: a39babba-0ce6-4095-8e0a-65418401beb3
$ aws s3api put-object-legal-hold --bucket cmp-governance --key vprobe/gov-hold-php.txt --legal-hold Status=ON

$ aws s3api get-object-retention --bucket cmp-governance --key vprobe/gov-hold-php.txt
{"Retention":{"Mode":"GOVERNANCE","RetainUntilDate":"2026-11-19T12:14:04.896000+00:00"}}
$ php version-probe.php plain cmp-governance vprobe/gov-hold-php.txt a39babba-0ce6-4095-8e0a-65418401beb3
HTTP 400 InvalidRequest: "Object is WORM protected and cannot be overwritten"
$ php version-probe.php batch cmp-governance vprobe/gov-hold-php.txt a39babba-0ce6-4095-8e0a-65418401beb3
HTTP 200
Deleted: null
Errors: [{"Key":"vprobe\/gov-hold-php.txt","VersionId":"a39babba-0ce6-4095-8e0a-65418401beb3","Code":"InvalidRequest","Message":"Object is WORM protected and cannot be overwritten"}]
$ php version-probe.php bypass cmp-governance vprobe/gov-hold-php.txt a39babba-0ce6-4095-8e0a-65418401beb3
HTTP 400 InvalidRequest: "Object is WORM protected and cannot be overwritten"

$ aws s3api list-object-versions --bucket cmp-governance --prefix vprobe/gov-hold-php.txt
2026-11-18T12:14:04.897000+00:00  VERSION        latest=true  51B  a39babba-0ce6-4095-8e0a-65418401beb3  vprobe/gov-hold-php.txt
```

## Recovery: the deleted bytes read back by version ID

```text
Ceph RGW
$ aws s3api get-object --bucket cmp-ceph-primary --key urn:oid:81 out (no version id)
argument of type 'NoneType' is not iterable
$ aws s3api get-object --bucket cmp-ceph-primary --key urn:oid:81 --version-id Q6kcu8EAHGnJ51g9sVqW.i39Gd-pjMP out && cat out
A3 content 2026-11-18T12:01:11Z
$ aws s3api get-object --bucket cmp-ceph-compliance --key scenarioB-ext-compliance.txt out (no version id)
argument of type 'NoneType' is not iterable
$ aws s3api get-object --bucket cmp-ceph-compliance --key scenarioB-ext-compliance.txt --version-id cNr6zbVXShV6Tecg9zOnYoaJyLIZYge out && cat out
B ext-compliance 2026-11-18T12:01:47Z locked content
$ aws s3api get-object --bucket cmp-ceph-compliance --key folderC-ext-compliance-r2/c1.txt out (no version id)
argument of type 'NoneType' is not iterable
$ aws s3api get-object --bucket cmp-ceph-compliance --key folderC-ext-compliance-r2/c1.txt --version-id c6ZHVVPvN5J-KCzNUtWfiuxJwWLu9Kf out && cat out
C1 ext-compliance 2026-11-18T12:08:32Z
$ aws s3api get-object --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt out (no version id)
argument of type 'NoneType' is not iterable
$ aws s3api get-object --bucket cmp-ceph-compliance --key scenarioD-ext-compliance-r2.txt --version-id X8AwtgIch-D3bBvgFvseyOL0ZME..Z7 out && cat out
D ext-compliance 2026-11-18T12:09:20Z legal hold content
MinIO
$ aws s3api get-object --bucket cmp-primary --key urn:oid:80 out (no version id)
An error occurred (NoSuchKey) when calling the GetObject operation: The specified key does not exist.
$ aws s3api get-object --bucket cmp-primary --key urn:oid:80 --version-id a9a51f79-b52f-4d54-8893-de4fab1e1295 out && cat out
A3 content 2026-11-18T12:14:32Z
$ aws s3api get-object --bucket cmp-compliance --key scenarioB-ext-compliance.txt out (no version id)
An error occurred (NoSuchKey) when calling the GetObject operation: The specified key does not exist.
$ aws s3api get-object --bucket cmp-compliance --key scenarioB-ext-compliance.txt --version-id 2ce899db-3668-4338-88dd-7323fbf0c0a5 out && cat out
B ext-compliance 2026-11-18T12:15:09Z locked content
$ aws s3api get-object --bucket cmp-compliance --key folderC-ext-compliance/c1.txt out (no version id)
An error occurred (NoSuchKey) when calling the GetObject operation: The specified key does not exist.
$ aws s3api get-object --bucket cmp-compliance --key folderC-ext-compliance/c1.txt --version-id 3d37401d-b2f6-43dc-95f3-a37bd230e18c out && cat out
C1 ext-compliance 2026-11-18T12:15:42Z
```

The `argument of type 'NoneType' is not iterable` lines are the aws CLI crashing on the Ceph error body, which carries an empty `<Message>`. The same plain GET through the PHP SDK returns `HTTP 404 NoSuchKey` with an empty message.

## Delete paths: v34.0.0 compared with v35.0.3

Function bodies extracted with the PHP tokenizer from the tagged sources on GitHub and compared.

```text
v34.0.0 = f65ed43abc790b6038d52d37848fd99d20baf407, v35.0.3 = 490f0929fe1353e5bf8281d96879e7ee7b35be80
AmazonS3.php::unlink -> IDENTICAL
AmazonS3.php::rmdir -> IDENTICAL
AmazonS3.php::batchDelete -> IDENTICAL
AmazonS3.php::clearBucket -> IDENTICAL
S3ObjectTrait.php::deleteObject -> IDENTICAL
ObjectStoreStorage.php::unlink -> IDENTICAL
ObjectStoreStorage.php::rmdir -> IDENTICAL
ObjectStoreStorage.php::rmObjects -> IDENTICAL
ObjectStoreStorage.php::rmObject -> IDENTICAL
grep Quiet|VersionId|BypassGovernance in the three v35.0.3 files:
AmazonS3.php:281:							'Quiet' => true,
Whole-file diff stat (v34.0.0 -> v35.0.3):
AmazonS3.php: 58 changed lines
ObjectStoreStorage.php: 88 changed lines
S3ObjectTrait.php: 5 changed lines
```

The files differ elsewhere: `getMetaData` for folders, copy failure propagation, mtime and etag defaults in `AmazonS3`, and the encryption handling in `ObjectStoreStorage::copyFile`. None of it is in the delete paths.

## Teardown (not run)

The environment is left running. The COMPLIANCE objects cannot be removed before their retention ends, one day after upload.

```
docker rm -f cmp-ceph-nextcloud cmp-minio-nextcloud cmp-ceph-rgw \
  && docker volume rm cmp-ceph-nextcloud-html cmp-minio-nextcloud-html cmp-ceph-etc cmp-ceph-var \
  && docker network rm cmp-ceph-net
# On MinIO, after the retention date has passed:
mc rm --recursive --force --versions --bypass vng/cmp-primary vng/cmp-governance vng/cmp-compliance vng/cmp-nodelete vng/cmp-partial
mc rb vng/cmp-primary vng/cmp-governance vng/cmp-compliance vng/cmp-nodelete vng/cmp-partial
mc admin user rm vng cmp-nodelete ; mc admin user rm vng cmp-partial
mc admin policy rm vng cmp-nodelete ; mc admin policy rm vng cmp-partial
```
