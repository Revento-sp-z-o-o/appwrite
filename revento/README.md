# Revento Appwrite maintenance

This fork tracks upstream Appwrite and carries a small, explicit patch set for
Revento's self-hosted qualification. The current branch starts at Appwrite 2.0.0
(`033c6303e352b9307f0e53fff15fb631882674d6`). It is a **candidate**, not a qualified
production release.

## Patch inventory

| Patch | Reason | Status |
| --- | --- | --- |
| `http-native-curl` | Let executor HTTP waits yield to function callbacks in the API process. | Existing qualification environment correction; retained in this image. |
| `relationship-lookups` | Avoid loading relationship collection metadata before empty/delegating branches. | Local PostgreSQL relationship regression comparison passed; API and publication qualification pending. |

The database patch changes Utopia's dependency code, not Revento functions or SDKs.
It keeps relationship traversal, write-side inverse maintenance, authorization,
transaction boundaries and response population enabled. No schema migration is
introduced. Other proposed fixes are not implicitly included.

`manifest.json` pins the upstream image, source commits, patch bytes and before/after
source hashes. Image builds fail if these disagree. The manifest is retained in the
image at `/usr/local/share/revento/manifest.json`.

Upstream automation is preserved under `.github/upstream-workflows/` on this
maintenance branch. It is inactive here: it assumes upstream registries, secrets
and release processes. Our active workflow runs only the focused candidate checks
and has read-only repository permissions. Re-enable upstream checks selectively
when their environment assumptions have been adapted.

## Build

From the repository root, with an available Docker builder:

```sh
docker build --platform linux/amd64 -f revento/Dockerfile -t revento/appwrite:2.0.0-candidate .
```

Building an image does not deploy it. No workflow in this patch automatically
deploys or changes an environment. Use immutable image digests for deployments;
record the source commit, manifest and qualification report alongside that digest.
Keep the prior qualified digest available for rollback.

Every build first runs installer positive/negative controls, applies the verified
patches, then syntax-checks both changed files. The pinned patch utility is removed
after the build. Pull requests run the same relationship fixture on upstream and
candidate images against PostgreSQL 18.3 with both memory and Redis caches. These
CI services are ephemeral and contain only synthetic test data.

For a native local test, provide an isolated loopback PostgreSQL database and PHP
8.5 with PDO PostgreSQL. Set `RELATION_PG_DSN`, `RELATION_PG_USER` and
`RELATION_PG_PASSWORD` for that disposable database. Set
`REVENTO_DATABASE_AUTOLOAD` to the locked Utopia 7.3.4 dependency autoloader and
`REVENTO_DATABASE_SOURCE` to the original or patched `Database.php`, then run
`php revento/tests/relationships.php original` or `candidate`. The fixture checks
the source hash and requires loopback access; it creates distinct schemas and cache
namespaces for each variant. `RELATION_CACHE=redis` additionally requires a dedicated
local Redis service and the PHP Redis extension. The default uses memory.

## Qualification

The targeted library fixture runs the actual Utopia PostgreSQL adapter. Compare
upstream and candidate using identical dependencies and data, then test the built
image through Appwrite's public APIs. The local fixture is not an API security test
or proof that application publication meets its deadline.

Required candidate acceptance:

1. Hash-verified build and library regressions: null and populated relationships,
   both directions, selections, inaccessible related rows, denied writes,
   reassignment, deletion behaviour and transaction rollback.
2. API transaction and relationship tests with server, owner, outsider and
   unauthenticated clients; preserve response shapes and failure behaviour.
3. Full application publication fixture with unchanged workload: record staging,
   commit and caller timings, verify durable published state and side effects,
   then run the pending affected UI scenarios. A late commit after caller timeout
   is a failure, even if rows eventually appear.
4. Independent security/code review and required PR checks before acceptance.
   Fresh-install and upgrade qualification are required for a release.

## Upgrading and retiring patches

Keep `upstream` pointing to `https://github.com/appwrite/appwrite.git`; preserve its
tags and history. Maintain Revento branches separately from upstream `main`.
For each proposed upgrade:

1. Select an upstream stable release and review its release notes, migrations and
   security fixes. Start an upgrade branch from that release.
2. Inspect each patch against the new source. Remove patches already fixed
   upstream; port only the remaining ones. Update source/image pins deliberately.
   Do not weaken hash checks to make an old patch apply.
3. Run the patch-specific regressions on both versions. Qualify fresh install and
   upgrade from the currently deployed version using synthetic representative
   data, including permissions and relationships.
4. Publish a ready-for-review PR with exact test evidence and unresolved gaps.
   Build the accepted commit, record its immutable image digest, then deploy only
   after explicit authorization for the named environment.
5. Preserve the prior image and compatible backup. An image rollback is sufficient
   only when the database state/schema remains compatible; upgrades with migrations
   need an independently tested recovery procedure.

Do not add credentials, customer data, private environment addresses or diagnostic
dumps to this public fork. Keep deployment configuration and private qualification
evidence in Revento's private repositories/storage.
