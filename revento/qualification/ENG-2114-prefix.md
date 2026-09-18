# Explicit PostgreSQL prefix qualification

Candidate scope: a single Unicode letter/number token followed by `*` uses a
bound literal word-prefix expression. Ordinary, quoted, multiword and malformed
searches retain the original path. This changes no table/index DDL, global
text-search setting, API permissions or SDK versions.

## Evidence, 18 September 2026 UTC

- Pinned upstream Appwrite 2.0.0 / Utopia 7.3.4, plus the eight previously qualified
  maintained patches. The running baseline's eight source hashes match the
  candidate manifest; only the PostgreSQL adapter is changed by this patch.
- Original adapter fails 20 of 62 focused controls; candidate passes all 62 on
  PostgreSQL 18.3 using the actual packaged PHP 8.5.9 / PCRE 10.44 code. Non-prefix
  control results are identical. Both temporary fixture transactions rolled
  back; the owned PostgreSQL container and its anonymous storage were removed.
- The candidate also passes 62 native adapter controls on PostgreSQL 17. Source
  and test hashes are recorded independently of the packaged proof.
- Candidate image: `sha256:e068146d023de409050a12759deb1e18c5cad43f060dd8f412b4cd5afd195b11`.
  The build verifies every upstream, patch and resulting-source hash and checks
  the generated Unicode class against the packaged PCRE tables.
- A separate loopback-only API using this image passes 46 checks across TablesDB
  and legacy Databases routes. Real JWT actors cover the authorized owner,
  another user with a different private row, and anonymous access. Cases cover
  exact ID sets, positive/negative prefixes, Unicode digits and case, numeric
  category boundaries, grouped OR, two-prefix/quoted-token/range conjunction,
  NULL exclusion and cursor pagination.
- API fixture creation intents are durable before POST. All owned database/user
  IDs were verified absent through the API and the temporary API container was
  removed. Internal asynchronous storage reclamation is not attested by a 404 response.
  The routed baseline API container ID remained unchanged and running.
- Five no-network cleanup fault tests pass: lost user creation response, lost
  database creation response, failed deletion/retry, ownership mismatch and
  untrusted exception-text redaction. Cleanup retry preserves actionable locally
  authored diagnostics while the normal cleanup path preserves the primary error.
  Unsafe-target tests reject non-loopback/production targets and public config
  permissions under both normal and optimized Python execution.
- Independent source security and pattern reviews have no outstanding blockers.

The earlier query-only `simple` dictionary prototype was rejected after tests
showed PostgreSQL drops Arabic-script numeric lexemes. A POSIX alnum boundary
also failed numeric-category cases. The generated Unicode L/N boundary avoids
both defects without enumerating Unicode at request time. A separate target
probe confirmed the combining Greek ypogegrammeni boundary case behaves as
required with the packaged configuration.

## Remaining deployment gates and limitations

This is candidate correctness/permission evidence, not a production release.
The image was activated on synthetic dev2 at 19:10:45 UTC. All nine live source
hashes and the preserved 90-second execution deadline were verified; all other
services remained unchanged. The unchanged three-case ENG-2002 Events selection
then passed in 18 seconds, including the prefix query and later privacy,
reconciliation and idempotence assertions. Its eleven function deployments,
settings and stored secret values were restored and verified at 19:19:22 UTC.

These API and application acceptance runs were executed separately from CI.
CI runs the packaged adapter and offline guard/cleanup controls. Automating the
owned API bootstrap and original/candidate differential is tracked in
[ENG-2118](https://linear.app/revento/issue/ENG-2118).

No prefix index lifecycle or production-scale throughput claim is made. The
pinned adapter's fulltext metadata uses a raw attribute index, and this patch
adds no matching search index. The generated bound pattern is about 11 KB; query
compilation, statement timeouts and representative data/concurrency require
separate performance qualification before declaring production search ready.
