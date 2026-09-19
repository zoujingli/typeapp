#!/usr/bin/env bash
set -euo pipefail
task_script_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TYPE_TEST_SUITE=integration TYPE_ROLLOUT_NATIVE="${TYPE_INTEGRATION_NATIVE:-0}" bash "$task_script_root/test-rollout.sh" "${1:-sqlite}"
