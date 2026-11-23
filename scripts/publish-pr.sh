#!/usr/bin/env bash
# Run in the executor's own environment. Never exports or copies credentials.
set -euo pipefail
repo=Felipefsa03/AporteAI
command -v gh >/dev/null || { echo 'BLOCKED: gh unavailable in this executor.' >&2; exit 1; }
gh auth status --hostname github.com >/dev/null 2>&1 || { echo 'BLOCKED: GitHub authentication unavailable in this executor. Use the authenticated host; do not mark done.' >&2; exit 1; }
gh api "repos/$repo" --jq '.permissions.push' | grep -qx true || { echo 'BLOCKED: repository push permission missing.' >&2; exit 1; }
if [[ "${1:-}" == --check ]]; then
  echo 'GitHub preflight passed (read access and advertised push permission; publication still requires verification).'
  exit 0
fi
[[ $# -eq 2 ]] || { echo 'Usage: scripts/publish-pr.sh TITLE BODY_FILE (or --check)' >&2; exit 2; }
[[ -f "$2" ]] || { echo 'Body file missing.' >&2; exit 2; }
branch=$(git symbolic-ref --short HEAD)
[[ "$branch" != main && "$branch" != master ]] || { echo 'Refusing trunk publication.' >&2; exit 1; }
[[ -z "$(git status --porcelain)" ]] || { echo 'Commit intended changes in an isolated clean checkout first.' >&2; exit 1; }
sha=$(git rev-parse HEAD)
git -c credential.helper= -c 'credential.helper=!gh auth git-credential' push "https://github.com/$repo.git" "HEAD:refs/heads/$branch"
url=$(gh pr list --repo "$repo" --state open --head "$branch" --base main --json url --jq '.[0].url // empty')
if [[ -z "$url" ]]; then
  url=$(gh pr create --repo "$repo" --head "$branch" --base main --title "$1" --body-file "$2")
fi
remote_sha=$(gh pr view "$url" --repo "$repo" --json headRefOid --jq .headRefOid)
[[ "$remote_sha" == "$sha" ]] || { echo 'BLOCKED: PR does not match local HEAD.' >&2; exit 1; }
gh pr view "$url" --repo "$repo" --json url,state,headRefOid,baseRefName,isDraft,statusCheckRollup
printf '\nPublication verified: %s\n' "$url"
printf 'Before Gartrol done: verify required CI success and card acceptance evidence. Do not wait for review or merge.\n'
