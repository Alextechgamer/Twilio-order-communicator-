#!/usr/bin/env bash
# Best-effort: expose /opt/gstack skills to Cursor's native skills-cursor loader.
# Harmless if Cursor ignores these symlinks — agents still follow .cursor/rules/gstack.mdc.
set -euo pipefail

SKILLS_DIR="${HOME}/.cursor/skills-cursor"
mkdir -p "${SKILLS_DIR}"

for skill in office-hours autoplan review qa cso ship investigate document-release; do
  src="/opt/gstack/${skill}"
  if [[ -d "${src}" ]]; then
    ln -sfn "${src}" "${SKILLS_DIR}/gstack-${skill}"
  fi
done

exit 0
