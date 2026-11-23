---
name: workspace-context
description: Use for work that depends on this project's durable decisions, conventions, constraints, or Gartrol handoff rules.
---

# Workspace Context

1. Read `AGENTS.md` and `memory.md` before planning.
2. Inspect current repository state and preserve user work.
3. Check Gartrol workspace readiness and the active mission.
4. Claim a routed, dependency-ready card before editing.
5. Read the card checkpoint and Git state before resuming; update the single rolling checkpoint after commits, decisions and tests, and before long operations or handoff.
6. Keep checkpoints concise (target 6,000 characters; maximum 12,000) and exclude credentials, lease tokens, raw transcripts and copied logs.
7. Keep the lease alive while working. When implementation and evidence are ready, deliver before waiting for pull-request review or merge; never keep or pause an attempt solely to await review. Leave validation to a human.
8. Before committing, use `type(scope): imperative summary` with a mandatory stable project scope.
9. Use `main` as the only trunk; keep topic branches short-lived and incomplete work behind feature flags.
10. Treat one pull request as one coherent delivery; batch related micro-adjustments and preserve atomic commits.
11. Run targeted checks while iterating and the required full gate once before integration; require human review for high-risk changes.
12. Pass multiline pull-request Markdown with `gh --body-file` and verify it after publication.
13. Update memory only with durable, non-secret facts.

## Mandatory PR publication — owner decision 2026-11-23

- Applies to OpenCode/Big Pickle and every executor. Before implementation run `bash scripts/publish-pr.sh --check` inside the actual executor environment. If absent on an older checkout, check `command -v gh`, `gh auth status`, and repository access manually. Never assume a previous executor's missing tools or authentication describe this environment.
- Use the already authenticated host when an isolated executor cannot publish. Do not copy tokens, log credentials, or disable isolation. Request a publication handoff with exact branch and SHA; keep delivery blocked until the remote PR is verified.
- Use an isolated clean checkout, commit only intended files, and run `bash scripts/publish-pr.sh 'type(aporteai): summary' /absolute/path/body.md`. It pushes through the existing gh credential helper, creates/reuses an open PR and verifies its head SHA. It does not merge or deliver a card.
- Publication is a required acceptance condition: NEVER mark done without a remotely verified PR URL, matching head SHA, required checks passing, and the card's required evidence. Record URL, SHA and CI result in the rolling checkpoint. Failed/pending CI or missing PR requires correction or an explicit blocker, not a completed delivery.
- Deliver after these checks and before human review/merge. Human gates C01–C07/GO remain untouched. Draft PRs with incomplete operational evidence are reviewable work, not proof of completed acceptance.
- On interruption preserve a concise checkpoint and use the Gartrol pause/handoff flow; do not leave the lease to expire or mark done to escape a publication failure.
