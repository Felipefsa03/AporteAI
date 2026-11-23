# Workspace Instructions

## Project

AporteAI

## Objective

MVP v1.2 de consultoria assistida por IA via WhatsApp. Dois números, um dashboard do proprietário e bloqueio financeiro antes do LLM. Liberação comercial condicionada aos gates do PRD.

## Gartrol operating contract

- Read `memory.md` and load the `workspace-context` skill before planning.
- Inspect repository state before editing.
- Use routed cards for executable work and encode dependencies explicitly.
- Claim a ready card before the first mutation; keep its lease alive while working.
- Maintain the card's single rolling checkpoint after commits, decisions and tests, and before long operations or handoff. Target 6,000 characters and never exceed 12,000.
- Resume by reading the checkpoint and Git state instead of replaying transcripts; never put credentials, lease tokens, raw transcripts or copied logs in a checkpoint.
- Deliver agent work as `done` with evidence. Only humans validate.
- When implementation and evidence are ready, deliver before waiting for pull-request review or merge. Never keep or pause an attempt solely to await review.
- Use `type(scope): imperative summary` for every commit and pull-request title. The scope is mandatory and must remain stable for the project.
- Follow Trunk-Based Development: `main` is the only trunk; use short-lived typed branches, keep incomplete work behind feature flags, and delete branches after verified integration.
- Treat one pull request as one coherent delivery, not as one incremental request. Batch related micro-adjustments on the same branch and keep commits atomic.
- Run the cheapest targeted checks while iterating, then the repository's required full gate once before integration. Require human review for high-risk changes; low-risk changes may auto-merge after required checks.
- Never push directly to `main` unless repository-specific guidance explicitly permits it.
- Pass multiline pull-request Markdown through `gh --body-file`, then verify it with `gh pr view`; never pass escaped `\n` sequences as body text.
- Never store credentials, lease tokens, or raw transcripts in project files.
- Create missing guidance files only; never overwrite existing guidance.

## Mandatory PR publication — owner decision 2026-11-23

- Applies to OpenCode/Big Pickle and every executor. Before implementation run `bash scripts/publish-pr.sh --check` inside the actual executor environment. If absent on an older checkout, check `command -v gh`, `gh auth status`, and repository access manually. Never assume a previous executor's missing tools or authentication describe this environment.
- Use the already authenticated host when an isolated executor cannot publish. Do not copy tokens, log credentials, or disable isolation. Request a publication handoff with exact branch and SHA; keep delivery blocked until the remote PR is verified.
- Use an isolated clean checkout, commit only intended files, and run `bash scripts/publish-pr.sh 'type(aporteai): summary' /absolute/path/body.md`. It pushes through the existing gh credential helper, creates/reuses an open PR and verifies its head SHA. It does not merge or deliver a card.
- Publication is a required acceptance condition: NEVER mark done without a remotely verified PR URL, matching head SHA, required checks passing, and the card's required evidence. Record URL, SHA and CI result in the rolling checkpoint. Failed/pending CI or missing PR requires correction or an explicit blocker, not a completed delivery.
- Deliver after these checks and before human review/merge. Human gates C01–C07/GO remain untouched. Draft PRs with incomplete operational evidence are reviewable work, not proof of completed acceptance.
- On interruption preserve a concise checkpoint and use the Gartrol pause/handoff flow; do not leave the lease to expire or mark done to escape a publication failure.
