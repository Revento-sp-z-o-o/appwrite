# Revento Appwrite maintenance

This fork tracks upstream Appwrite and carries a small, explicit patch set for
Revento's self-hosted qualification. The current branch starts at Appwrite 2.0.0
(`033c6303e352b9307f0e53fff15fb631882674d6`). It is a **candidate**, not a qualified
production release.

## Patch inventory

| Patch | Reason | Status |
| --- | --- | --- |
| `postgres-explicit-prefix` | Literal single-word prefixes on PostgreSQL (ENG-2114), including stopword/stem prefixes. | Native and packaged 62-case controls, isolated 46-case API matrix and three routed Events cases pass. |
| `synchronous-timeout` | Operator-configurable synchronous execution-API wait deadline (ENG-2093); default 30 seconds. | Isolated real-API profiles passed; see the sanitized deadline receipts. |
| `http-native-curl` | Let executor HTTP waits yield to function callbacks in the API process. | Existing qualification environment correction; retained in this image. |
| `relationship-lookups` | Defer unused collection metadata; opt in to direct relationship IDs for locked old-row reads (ENG-2084); invalidate document cache keys after the outer transaction finishes (ENG-2091). | Native and local API comparison passed; dev2 publication acceptance pending. |
| `operator-variables` | Register maintained settings in the packaged variable registry; the repository Compose definition forwards them. | Transaction-limit and execution-API timeout registration and rendered Compose checks passed. |
| `transaction-limit` | Let self-hosted operators configure transaction capacity (ENG-2082). | Default remains 100; configured capacity requires API qualification before deployment acceptance. |
| `transaction-event-documents` | Bounded final event reads (ENG-2083), identity/permission staging reads (ENG-2084). | Native and local API acceptance passed. |
| `transaction-event-reads` | Bounded final events and guarded old-row reads for PostgreSQL transaction updates. | Only declared internal read changes; event dispatch and rollback boundaries verified. |
| `transaction-staging-reads` | Use narrow staging reads through legacy/TablesDB operations routes (ENG-2084). | Local API staging and 150-row wiring comparison passed. |

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

Every build first runs installer positive/negative controls for every patched target,
applies the verified patches, then syntax-checks the changed files and runs the
ENG-2082 bootstrap configuration regression and ENG-2083/2084 read/dispatch boundary guards. The pinned patch utility is removed
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

## Transaction capacity

Set `_APP_LIMIT_DATABASE_TRANSACTION` on API containers to choose the maximum
number of operations in one transaction. The default remains **100**. For example,
`_APP_LIMIT_DATABASE_TRANSACTION=1000` allows the same operation count as Cloud Pro.
The value is read at process startup; recreate the relevant containers through the
normal reviewed deployment process to change it. No deployment occurs automatically.

The maintained image patches the variable registry. The repository
`docker-compose.yml` forwards this setting wherever the existing batch setting
is forwarded. The upstream API image does not contain a Compose file. Existing installations must add the environment entry to their own
Compose service definition before recreating containers; editing `.env` alone does
not add missing entries to an older Compose file. Use the qualified maintained
image digest for the API service. Upstream PHP source files in this repository remain
pristine; the patch manifest defines the packaged PHP changes.

The setting follows the existing `_APP_LIMIT_DATABASE_BATCH` parsing convention:
the shared environment reader treats an unset, empty or `0` value as the default
100; other values are converted to an integer and clamped to at least 1. Negative
and nonnumeric values therefore become 1, and no value disables the limit. Bulk request size remains a
separate setting. Cloud's `databasesTransactionSize` plan value still takes
precedence over this self-hosted default; all existing transaction entry points
continue to use the same shared constant.

This controls cumulative staged operations across all requests in a transaction.
Sending smaller staging batches does not bypass it. Higher capacity does not
increase execution deadlines or make larger commits faster. Qualify the intended
workload and test boundary rejection, rollback and atomicity through the real API
before accepting a deployment.

## Transaction event reads (ENG-2083)

PostgreSQL legacy/TablesDB commits now fetch final event documents in windows of
at most 100 operations. Fetches are deduplicated by logical database, collection
and document ID; the original event loop still replays each operation in order.
Create/update/upsert events use readable final state; delete events retain their
staged snapshots. ENG-2084 additionally narrows the locked old-row read for eligible updates; mutation
logic, commit/rollback code, and event dispatch remain unchanged. Other adapters and DocumentsDB/VectorsDB retain individual reads.
The PostgreSQL guard reads the underlying Utopia PDO driver name through the
adapter pool; checking the outer adapter class would silently disable batching.
The library fixture uses this same pooled adapter shape.

`TransactionState::getCommittedDocuments` retains normal caller authorization and
full relationship population. If a collection denies list access, it falls back
to individual reads to preserve the existing empty result for write-only callers.
The fetched map is discarded between windows. Each window observes state when it
is fetched, so concurrent changes can become visible between windows; this is not
a transaction-wide historical snapshot or an event-delivery atomicity guarantee.

The ticketed native API cost reproducer on the preceding image measured 23,944
Redis HGETs and a 34.294-second commit for 150 synthetic relationship-heavy changes.
Its cost budget of 18,000 failed before production edits. Writes were verified and
the isolated fixture removed. Hardware saturation and disk blocking were not
supported by preceding matched diagnostic windows; raising transaction capacity
alone does not address these repeated reads.

The focused native PostgreSQL comparison of final reads passes with unchanged
Utopia dependencies: 8,250 cache loads become 162 across 300 operations. Seven
behavior groups cover exact nested values/array order, batch boundaries, mixed
logical namespaces and operations, payload-copy isolation, write-only collections,
owner/outsider/anonymous visibility, and fresh/rolled-back state. This is a library
result, not a full-publication performance claim. CI runs the same comparison with
PostgreSQL 18.3 and memory/Redis caches. `REVENTO_TRANSACTION_SOURCE` selects the
hash-pinned original or candidate TransactionState source, with the existing
patched Utopia source supplied through `REVENTO_DATABASE_SOURCE`.

`tests/eng2083-api.mjs` is a separate synthetic API/realtime acceptance harness,
using Node 22+ built-ins. It tests 106 operations across the read-window boundary,
105 ordered realtime events, duplicate IDs across tables, final payloads, deleted
row snapshots, create-then-delete, precommit invisibility and explicit rollback.
It observes a two-second quiet interval and checks all frames after socket close.
It does not certify function/webhook delivery, transaction ownership/actor matrices,
failed-commit atomicity, indefinite absence of late events, or full Program150.

Supply a private mode-600 JSON config containing `endpoint`, `project`, `apiKey`,
`syntheticOnly: true`, `evidenceLabel` (`baseline-harness-validation` or
`candidate-acceptance`) and `expectedImage` (immutable SHA256 image). For remote
synthetic targets, also set `targetName` and explicitly pass `--remote=TARGET_NAME`.
The target image must be independently verified before and after the run;
`expectedImage` records the expectation and is not an API attestation. Local runs
require loopback and a project ID starting with `eng2083`. The target must allow
at least 106 operations. Never use real data or a production project.

```sh
node revento/tests/eng2083-api.mjs /private/qualification.json /private/new-evidence-directory
```

Run only with the appropriate environment authorization. A new owned database and
transaction journal are created; committed/rolled-back fixtures are removed with
identity and absence checks. Acceptance failures, ambiguous or pending mutations
retain the fixture for inspection. Baseline harness validation must not be reported as candidate acceptance.

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

## Transaction internal reads (ENG-2084)

Staging operations use only document identity and permissions. For PostgreSQL
legacy/TablesDB, `TransactionState::getDocumentForOperation` selects those fields
through the existing authorization and transaction-overlay path. Other adapters
and API families keep their ordinary reads.

Transaction updates opt into a new, default-false Utopia `updateDocument` argument.
The locked old-row read retains every scalar/system field and direct IDs for every
relationship, including reverse and many-to-many membership needed for removals.
Incoming relationship values must be null, strings, or lists containing only
strings. Nested documents/maps, mixed lists and relationship operators retain the
full old-row read. Nested writes and upserts do not inherit the opt-in. The returned
document remains fully populated; ordinary three-argument calls remain unchanged.

The installer still applies one hash-pinned patch per target. The ENG-2083 boundary
guard reverses only the declared ENG-2084 read additions and verifies the exact
previous reviewed action hash before checking event/rollback boundaries.

`tests/eng2084-transaction-reads.php candidate` runs the permanent native semantic
and fanout regression using the same PostgreSQL/source environment variables as
ENG-2083, plus `REVENTO_TRANSACTION_SOURCE`. `--cost-only` isolates the 150-row
reproducer; `--extras-only` isolates nested/operator/conflict cases. Original mode
requires the deployed ca171 Database and TransactionState hashes and intentionally
fails the amplification budget. CI runs the candidate regression on each change.

Native local API checks use Python 3 and Node 22+, a private JSON config (mode 600)
with `endpoint`, `project`, `apiKey`, `syntheticOnly: true`, `expectedImage`, and
`evidenceLabel: "candidate-acceptance"`. The project must start with `eng2083` for
the realtime fixture and `eng208` for the Python fixture. No real users/data:

```sh
python3 revento/tests/eng2084-api.py PRIVATE_CONFIG NEW_OUTPUT_DIRECTORY
node revento/tests/eng2083-api.mjs PRIVATE_CONFIG NEW_OUTPUT_DIRECTORY
```

The API test accepts `--staging-only` to repeat only the newly affected inherited
routes after the seven relationship/permission groups have passed. An optional
`realtimeEndpoint` points the realtime check at a separate loopback service.
API image identity must be verified externally against the manifest and recorded
with the config; the public API does not attest its own image.

`eng2084-api-cost.py` is a deliberately local fixture adapter: it expects isolated
Compose project `eng2084qualification`, candidate API on loopback 18084, ca171 API
on 18086, and its own Redis service. It checks container/source identities, stages
and commits 150 updates through each real API, compares HGET counts, verifies every
saved row, and removes its owned database after all transactions are terminal.
This is a wiring/performance comparison, not a function-publication deadline test.

See [ENG-2084 qualification](qualification/ENG-2084.md) for measured results and
limits. The earlier Program150 synchronous failure remains unresolved until the
reviewed image is explicitly deployed to dev2 and tested with the Events function.
No workflow deploys remotely or migrates production data.

Transaction cache correctness qualification and limits: [ENG-2091](qualification/ENG-2091.md). This adds invalidation at the outer SQL transaction boundary; it does not qualify signup queue throughput or prevent writer-side uncommitted cache publication (ENG-2092).

## Synchronous execution API deadline (ENG-2093)

Set `_APP_FUNCTIONS_SYNC_TIMEOUT` on API containers to an integer number of seconds from 1 through 900. Unset, empty, malformed, zero, negative and out-of-range values use 30 seconds. The upstream default remains 30. Recreate the API service to apply a changed environment; deployments are explicit and no workflow applies this automatically.

This changes how long the API waits for a synchronous executor response. It does not change the function execution timeout, asynchronous dispatch, worker capacity or client/proxy timeouts. A function can still time out earlier. A client must wait longer than the configured API deadline plus network overhead to observe that outcome. A longer deadline does not cancel or safely retry work after disconnect and is not a throughput fix. The error message reports the effective configured request deadline. Qualify the complete caller path before rollout.

The maintained Compose API service forwards `_APP_FUNCTIONS_SYNC_TIMEOUT`; existing operator-owned Compose files need the same environment entry before recreating the API. The packaged installer registry exposes the optional default of `30`.

ENG-2093 real-API qualification uses `python3 revento/tests/eng2093-api.py PRIVATE_CONFIG OWNED_OUTPUT --phase prepare`, then `--phase case --case default|extended|short|malformed`, and finally `--phase cleanup`. A separate controller must verify and record the immutable image and actual API container environment before each case. The harness requires a private mode-600 config with endpoint `http://127.0.0.1:18084/v1`, an owned `eng2093` project, `apiKey`, `syntheticOnly: true`, `expectedImage`, `evidenceLabel: candidate-acceptance`, and matching `case`. Profiles use unset, 90, 1, and invalid respectively. It creates one side-effect-free Dart function; run only in a deliberately owned synthetic project with function/execution read-write scopes, preserve failed fixtures for diagnosis, and delete the project/key after function cleanup. Never substitute production identifiers.

Sanitized behavioral receipts are in `revento/qualification/eng2093-deadlines.json`. Ordinary CI verifies patch pins, syntax and operator configuration; it does not provision an Appwrite stack or rerun this remote API harness automatically.

Scope: `_APP_FUNCTIONS_SYNC_TIMEOUT` controls only `POST /v1/functions/{functionId}/executions` with `async: false`, including SDK `createExecution` calls. Direct generated/custom function domains keep their upstream 60-second request deadline; Sites are unchanged. Use the asynchronous execution API for work that must outlive its caller. The qualified signup pump invokes the execution API and has a separate 210-second HTTP client timeout.

## Explicit PostgreSQL word prefixes (ENG-2114)

The PostgreSQL adapter stripped `*` before translating search, so the participant
catalog query `zol*` missed `zolty`. The ninth hash-pinned patch recognizes only a
complete Unicode letter/number token followed by one asterisk. Search and notSearch
use a bound, case-insensitive literal word-prefix pattern, retaining prefixes such
as `the*`, `runn*` and Arabic digits without dictionary stemming or token loss.
Only the validated token enters the fixed pattern; regex operators are rejected.
The boundary uses a generated Unicode letter/number class matching the pinned
image's PCRE tables. PostgreSQL POSIX alnum alone excludes some numeric characters
(such as `²`) and would create false word boundaries. The build-time generator
verifies the constant against packaged PCRE; no Unicode enumeration runs per request.
Ordinary searches, quoted phrases/tokens, multiword or malformed wildcard strings
retain the existing sanitizer and websearch path. Permissions, null handling,
column quoting, SDK validation and query composition are unchanged.

`tests/eng2114-prefix.php` executes the actual pinned original and candidate adapters
against an owned loopback PostgreSQL database named `eng2114_prefix_*`. Its 62 cases
cover prefix/complement, Unicode and numeric words, AND/token/range composition,
reader/outsider/anonymous predicates, dictionary boundaries and malformed grammar.
The original must reproduce the prefix failure; the candidate must pass and retain
all original non-prefix controls. Fixtures are transaction-local temporary tables,
rolled back in finally. CI runs this against the packaged images.

This patch changes no index DDL or database-wide text-search setting. It establishes
query correctness, not scalable indexed search: the pinned adapter creates a raw
attribute index for fulltext metadata, not a prefix-search index. The regex branch makes no index-use claim.
The isolated Appwrite API actor/endpoint matrix and unchanged three-case ENG-2002
Events selection both pass on synthetic dev2. See [qualification evidence](qualification/ENG-2114-prefix.md). Index lifecycle and representative query-plan/performance
qualification remain separate work before claiming production search readiness.
