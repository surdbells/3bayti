#!/usr/bin/env bash
# =============================================================================
# 3bayti API — CI smoke test (minimal, free)
# =============================================================================
#
# Lighter-weight smoke test for the auto-deploy pipeline. Verifies the
# API responds to liveness + readiness checks, nothing else.
#
# Why a separate script
# ---------------------
# The full smoke.sh exercises the auth flow, which means calling
# /v3/auth/register — which means a real MessageCentral SMS — which
# means a credit (~$0.05) gets burned every time CI deploys.
#
# Per-day waste during active development: $2-5. Per-month: $50-150.
# Real money for a check that has been graceful-401'ing on the OTP
# step anyway (the smoke can't get a real OTP code in CI).
#
# This script does ONLY the cheap, free checks:
#   1. GET /v3/health        (liveness — is the app up?)
#   2. GET /v3/health/ready  (readiness — is the DB reachable?)
#
# That covers the main deploy failure modes:
#   - Migration broke the app (catches via /health 5xx)
#   - DB connection broke (catches via /health/ready 5xx)
#   - Apache vhost broke (catches via DNS/connection failure)
#
# It does NOT catch:
#   - Auth flow regressions — but the existing 130+ unit/integration
#     tests in CI cover that
#   - MessageCentral integration breakage — addressed via periodic
#     manual smoke.sh runs from a developer machine
#
# Usage
# -----
#
#   ./apps/api/scripts/ci-smoke.sh https://api-v3.3bayti.ae
#
# Exits non-zero on any check failure. CI uses this exit code to
# fail the deploy job.
# =============================================================================

set -uo pipefail

API_URL="${1:-}"
if [ -z "$API_URL" ]; then
    echo "ERROR: missing API URL argument."
    echo "Usage: $0 <api-url>"
    echo "  e.g.: $0 https://api-v3.3bayti.ae"
    exit 2
fi

# Strip trailing slash for clean URL concatenation.
API_URL="${API_URL%/}"

# Helper: extract HTTP status from a curl response. We use -w '%{http_code}'
# at the END of the response body so we can split it cleanly.
fetch_with_status() {
    local url="$1"
    # -s silent, -m timeout in seconds, -w prints status after body
    curl -s -m 10 -w "\n%{http_code}" "$url" 2>&1 || echo -e "\n000"
}

# Helper: pretty-print a step header.
step() {
    echo
    echo "── $1 ──"
}

PASS_COUNT=0
FAIL_COUNT=0

# -----------------------------------------------------------------------------
# Step 1: liveness
# -----------------------------------------------------------------------------
step "1. GET /v3/health (liveness)"

LIVENESS_RESPONSE="$(fetch_with_status "$API_URL/v3/health")"
LIVENESS_STATUS="$(echo "$LIVENESS_RESPONSE" | tail -n1)"
LIVENESS_BODY="$(echo "$LIVENESS_RESPONSE" | sed '$d')"

if [ "$LIVENESS_STATUS" = "200" ]; then
    echo "✓ liveness returned 200"
    # Extract version field for visibility in CI logs.
    VERSION="$(echo "$LIVENESS_BODY" | grep -oE '"version":"[^"]*"' | head -1 || true)"
    if [ -n "$VERSION" ]; then
        echo "  $VERSION"
    fi
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo "✗ liveness returned $LIVENESS_STATUS (expected 200)"
    echo "  body: $(echo "$LIVENESS_BODY" | head -c 200)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

# -----------------------------------------------------------------------------
# Step 2: readiness (verifies DB connection)
# -----------------------------------------------------------------------------
step "2. GET /v3/health/ready (readiness)"

READINESS_RESPONSE="$(fetch_with_status "$API_URL/v3/health/ready")"
READINESS_STATUS="$(echo "$READINESS_RESPONSE" | tail -n1)"
READINESS_BODY="$(echo "$READINESS_RESPONSE" | sed '$d')"

if [ "$READINESS_STATUS" = "200" ]; then
    echo "✓ readiness returned 200"
    # Look for the database check in the body. Format:
    #   {"status":"ok","checks":{"database":"ok"}}
    if echo "$READINESS_BODY" | grep -q '"database":"ok"'; then
        echo "  database check: ok"
    fi
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo "✗ readiness returned $READINESS_STATUS (expected 200)"
    echo "  body: $(echo "$READINESS_BODY" | head -c 200)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo
echo "================================================"
if [ $FAIL_COUNT -eq 0 ]; then
    echo "✓ ci-smoke passed ($PASS_COUNT/$PASS_COUNT checks)"
    echo "================================================"
    exit 0
else
    echo "✗ ci-smoke FAILED ($FAIL_COUNT failures, $PASS_COUNT passed)"
    echo "================================================"
    exit 1
fi
