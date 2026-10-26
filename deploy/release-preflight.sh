#!/usr/bin/env bash
# Read-only release planning, shared by deploy-prod.sh and offline tests.
# Output: exactly two lines, the resolved base and target commit IDs.
# No database, network, branch creation or production filesystem access.
shg_release_plan() {
  local repo=$1 base=$2 target=$3 release=$4 base_sha target_sha
  local -a git_cmd=(git --git-dir="$repo")

  if [[ ! "$release" =~ ^release-[0-9]{8}-[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
    echo 'ABORT: release name must be release-YYYYMMDD-label' >&2; return 2
  fi
  "${git_cmd[@]}" check-ref-format "refs/heads/$release" >/dev/null || {
    echo 'ABORT: invalid release branch name' >&2; return 2;
  }
  # Never accept command options as refs. --end-of-options also protects
  # rev-parse; quoted revision expressions remain compatible with old usage.
  if [[ -z "$base" || -z "$target" || "$base" == -* || "$target" == -* ]]; then
    echo 'ABORT: base and target must be non-option Git refs' >&2; return 2
  fi
  base_sha=$("${git_cmd[@]}" rev-parse --verify --end-of-options "$base^{commit}" 2>/dev/null) || {
    echo 'ABORT: unknown base commit' >&2; return 2;
  }
  target_sha=$("${git_cmd[@]}" rev-parse --verify --end-of-options "$target^{commit}" 2>/dev/null) || {
    echo 'ABORT: unknown target commit' >&2; return 2;
  }
  "${git_cmd[@]}" merge-base --is-ancestor "$base_sha" "$target_sha" || {
    echo 'ABORT: target is not built on base (downgrade or divergent history)' >&2; return 2;
  }
  # show-ref --verify has exit 1 for absence, but also for a broken repo.
  # List refs instead so repository failures cannot look like an unused name.
  local existing
  existing=$("${git_cmd[@]}" for-each-ref --format='%(objectname)' "refs/heads/$release") || return 2
  if [[ -n "$existing" ]]; then
    echo 'ABORT: release branch already exists; choose a new release name' >&2; return 2
  fi
  printf '%s\n%s\n' "$base_sha" "$target_sha"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -euo pipefail
  [[ $# == 4 ]] || { echo 'usage: release-preflight.sh <git-dir> <base> <target> <release>' >&2; exit 2; }
  shg_release_plan "$@"
fi
