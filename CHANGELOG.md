# Changelog

## [1.1.1] - 2026-10-12

- Support dedicated, evidence-bound certification-cycle awards.
- Preserve immutable historical PDF bytes and distinguish revoked and expired awards.
- Make concurrent issuance and retries reuse the same award identity.
- Prevent ordinary course issuance from bypassing a cycle-bound issuer.
- Preserve backed-up cycle documents as historical copies rather than rebinding them.
- Run seal-engine data providers on both legacy and modern PHPUnit versions.
