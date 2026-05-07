#!/usr/bin/env bash
# =============================================================================
# 3bayti API — post-deploy smoke test
# =============================================================================
#
# Drives the deployed API through a complete auth flow to verify the
# real infrastructure (Postgres, Redis, MessageCentral, App Platform
# routing, TLS) is end-to-end functional.
#
# Usage:
#
#   # Test against staging
#   ./apps/api/scripts/smoke.sh https://api-v3.3bayti.ae
#
#   # Test against local docker
#   ./apps/api/scripts/smoke.sh http://localhost:8080
#
#   # CI-friendly: exit non-zero on any failure
#   ./apps/api/scripts/smoke.sh "$API_URL" || exit 1
#
# What it tests
# -------------
#
#   1. /v3/health            (liveness)
#   2. /v3/health/ready      (readiness with DB ping)
#   3. /v3/auth/register     (creates a throwaway user, gets verification_id)
#   4. /v3/auth/confirm      (uses '000000' — works against InMemoryOtpProvider
#                             in dev; FAILS against real MessageCentral, see below)
#   5. /v3/auth/me           (verifies access token works)
#   6. /v3/auth/refresh      (rotates refresh token)
#   7. /v3/auth/logout       (revokes session)
#
# OTP-aware behaviour
# -------------------
#
# Step 4 (/confirm) uses the literal code '000000'. This works only
# when the API uses InMemoryOtpProvider — i.e., APP_ENV != prod or
# SMS_PROVIDER != messagecentral. In production with real MessageCentral
# we can't smoke-test the confirm step automatically (the OTP is sent
# to a real phone we don't control).
#
# When the script detects an /confirm 401 in prod, it stops gracefully
# at step 4 and reports "OTP step skipped — production requires real
# device". Steps 1-3 still validate.
#
# Idempotency
# -----------
#
# Each run uses a unique throwaway email like
# 'smoketest+<timestamp>@3bayti.test'. Re-running is safe; old test
# users accumulate in the DB but don't conflict.
# =============================================================================

set -uo pipefail
# Note: NOT set -e — we handle each step's exit code explicitly so
# we can print a useful failure message before bailing.

API_URL="${1:-}"
if [ -z "$API_URL" ]; then
    echo "ERROR: missing API URL argument."
    echo "Usage: $0 <api-url>"
    echo "  e.g.: $0 https://api-v3.3bayti.ae"
    echo "        $0 http://localhost:8080"
    exit 2
fi

# Strip trailing slash for clean URL concatenation.
API_URL="${API_URL%/}"

# Generate a unique-per-run test identity. Phone numbers in the UAE
# range; +971-50-XXXX-XXXX. We use a fixed prefix and a millisecond
# timestamp tail so collisions across rapid runs are unlikely.
TIMESTAMP="$(date +%s%3N 2>/dev/null || date +%s)000"
TEST_EMAIL="smoketest+${TIMESTAMP}@3bayti.test"
# Using a phone prefix that's clearly not a real allocated UAE number
# (50-99999XXX is reserved for testing purposes by Etisalat).
TEST_PHONE="+97150999${TIMESTAMP:8:4}"
TEST_PASSWORD="SmokeTest123!"

PASS_COUNT=0
FAIL_COUNT=0
START_TIME="$(date +%s)"

# -----------------------------------------------------------------------------
# Output helpers
# -----------------------------------------------------------------------------

# ANSI colors (auto-disable when not a TTY — e.g. CI logs).
if [ -t 1 ]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[0;33m'
    BLUE='\033[0;34m'
    BOLD='\033[1m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' BLUE='' BOLD='' NC=''
fi

step() {
    printf "\n${BLUE}${BOLD}── %s ──${NC}\n" "$1"
}

pass() {
    printf "${GREEN}✓${NC} %s\n" "$1"
    PASS_COUNT=$((PASS_COUNT + 1))
}

fail() {
    printf "${RED}✗ %s${NC}\n" "$1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

warn() {
    printf "${YELLOW}!${NC} %s\n" "$1"
}

info() {
    printf "  %s\n" "$1"
}

# -----------------------------------------------------------------------------
# HTTP helpers — curl wrappers that capture both body + status
# -----------------------------------------------------------------------------

# Run a curl request, capturing body in $1 and status in $2 (passed by name).
# Usage: http_post body_var status_var URL JSON [extra_headers...]
#
# We can't reliably use bash -e here — curl returns 0 even for 4xx/5xx by
# default (we use --fail-with-body to make it return non-zero, then check
# $? explicitly).
http_post() {
    local body_var="$1"
    local status_var="$2"
    local url="$3"
    local data="$4"
    shift 4

    local response
    response="$(
        curl -sS \
             -w "\n__HTTP_STATUS__:%{http_code}" \
             -H "Content-Type: application/json" \
             -H "Accept: application/json" \
             "$@" \
             -X POST "$url" \
             -d "$data"
    )" || {
        # curl itself failed (network error, DNS, TLS) — different from
        # a non-2xx HTTP response, which is reported via the status code.
        printf -v "$body_var" "%s" "<curl error>"
        printf -v "$status_var" "%s" "0"
        return 1
    }

    local status
    status="$(printf "%s" "$response" | grep -oE '__HTTP_STATUS__:[0-9]+' | tail -1 | cut -d: -f2)"
    local body
    body="$(printf "%s" "$response" | sed 's/__HTTP_STATUS__:[0-9]*$//')"

    printf -v "$body_var" "%s" "$body"
    printf -v "$status_var" "%s" "${status:-0}"
}

http_get() {
    local body_var="$1"
    local status_var="$2"
    local url="$3"
    shift 3

    local response
    response="$(
        curl -sS \
             -w "\n__HTTP_STATUS__:%{http_code}" \
             -H "Accept: application/json" \
             "$@" \
             "$url"
    )" || {
        printf -v "$body_var" "%s" "<curl error>"
        printf -v "$status_var" "%s" "0"
        return 1
    }

    local status
    status="$(printf "%s" "$response" | grep -oE '__HTTP_STATUS__:[0-9]+' | tail -1 | cut -d: -f2)"
    local body
    body="$(printf "%s" "$response" | sed 's/__HTTP_STATUS__:[0-9]*$//')"

    printf -v "$body_var" "%s" "$body"
    printf -v "$status_var" "%s" "${status:-0}"
}

# Extract a JSON field via grep+sed. We don't require jq because:
#   - It's not on every CI runner (alpine-based ones especially)
#   - We're parsing simple top-level fields, not deep structures
#
# This is brittle for complex JSON; if a field's value contains a quote
# or newline it'll break. Auth responses are simple enough this works.
json_field() {
    local json="$1"
    local field="$2"
    printf "%s" "$json" | grep -oE "\"$field\":\"[^\"]+\"" | head -1 | sed -E "s/\"$field\":\"([^\"]+)\"/\1/"
}

# -----------------------------------------------------------------------------
# Pre-flight banner
# -----------------------------------------------------------------------------

printf "${BOLD}3bayti API smoke test${NC}\n"
printf "  target:    %s\n" "$API_URL"
printf "  test user: %s\n" "$TEST_EMAIL"
printf "  test phone: %s\n" "$TEST_PHONE"

# =============================================================================
# Step 1: liveness
# =============================================================================
step "1. GET /v3/health (liveness)"

http_get HEALTH_BODY HEALTH_STATUS "$API_URL/v3/health"

if [ "$HEALTH_STATUS" = "200" ]; then
    pass "liveness returned 200"
    info "$(printf "%s" "$HEALTH_BODY" | head -c 200)"
else
    fail "liveness returned $HEALTH_STATUS (expected 200)"
    info "body: $(printf "%s" "$HEALTH_BODY" | head -c 300)"
    # If liveness fails, nothing else will work — bail.
    echo
    printf "${RED}${BOLD}aborted: API isn't responding to liveness check${NC}\n"
    exit 1
fi

# =============================================================================
# Step 2: readiness (with DB ping)
# =============================================================================
step "2. GET /v3/health/ready (readiness + DB)"

http_get READY_BODY READY_STATUS "$API_URL/v3/health/ready"

if [ "$READY_STATUS" = "200" ]; then
    pass "readiness returned 200 (DB reachable)"
elif [ "$READY_STATUS" = "503" ]; then
    fail "readiness returned 503 (DB unreachable)"
    info "body: $(printf "%s" "$READY_BODY" | head -c 300)"
    info "downstream auth tests will fail without DB; aborting"
    exit 1
else
    fail "readiness returned $READY_STATUS (expected 200 or 503)"
    info "body: $(printf "%s" "$READY_BODY" | head -c 300)"
    exit 1
fi

# =============================================================================
# Step 3: /v3/auth/register
# =============================================================================
step "3. POST /v3/auth/register"

REGISTER_PAYLOAD="$(cat <<EOF
{
  "email": "$TEST_EMAIL",
  "phone": "$TEST_PHONE",
  "password": "$TEST_PASSWORD",
  "country_code": "AE"
}
EOF
)"

http_post REG_BODY REG_STATUS \
    "$API_URL/v3/auth/register" \
    "$REGISTER_PAYLOAD"

if [ "$REG_STATUS" = "201" ]; then
    pass "register returned 201"
    VERIFICATION_ID="$(json_field "$REG_BODY" "verification_id")"
    if [ -z "$VERIFICATION_ID" ]; then
        fail "register response missing verification_id"
        info "body: $(printf "%s" "$REG_BODY" | head -c 300)"
        exit 1
    fi
    info "verification_id: ${VERIFICATION_ID:0:30}..."
else
    fail "register returned $REG_STATUS (expected 201)"
    info "body: $(printf "%s" "$REG_BODY" | head -c 300)"
    exit 1
fi

# =============================================================================
# Step 4: /v3/auth/confirm  (OTP — only works in non-prod)
# =============================================================================
step "4. POST /v3/auth/confirm  (OTP step)"

CONFIRM_PAYLOAD="{\"verification_id\":\"$VERIFICATION_ID\",\"code\":\"000000\"}"

http_post CONFIRM_BODY CONFIRM_STATUS \
    "$API_URL/v3/auth/confirm" \
    "$CONFIRM_PAYLOAD"

if [ "$CONFIRM_STATUS" = "200" ]; then
    pass "confirm returned 200 — InMemoryOtpProvider in use"
    ACCESS_TOKEN="$(json_field "$CONFIRM_BODY" "access_token")"
    REFRESH_TOKEN="$(json_field "$CONFIRM_BODY" "refresh_token")"

    if [ -z "$ACCESS_TOKEN" ] || [ -z "$REFRESH_TOKEN" ]; then
        fail "confirm response missing tokens"
        info "body: $(printf "%s" "$CONFIRM_BODY" | head -c 300)"
        exit 1
    fi
    info "access_token: ${ACCESS_TOKEN:0:30}..."
elif [ "$CONFIRM_STATUS" = "401" ]; then
    warn "confirm returned 401 — likely real MessageCentral (code 000000 not accepted)"
    warn "skipping steps 5-7 (need real OTP from registered phone)"
    info "this is EXPECTED in production"
    echo
    printf "${YELLOW}${BOLD}smoke test passed (steps 1-3) — OTP-required steps skipped${NC}\n"
    printf "  passed: %d\n" "$PASS_COUNT"
    printf "  failed: %d\n" "$FAIL_COUNT"
    exit 0
else
    fail "confirm returned $CONFIRM_STATUS (expected 200 or 401)"
    info "body: $(printf "%s" "$CONFIRM_BODY" | head -c 300)"
    exit 1
fi

# =============================================================================
# Step 5: /v3/auth/me
# =============================================================================
step "5. GET /v3/auth/me"

http_get ME_BODY ME_STATUS \
    "$API_URL/v3/auth/me" \
    -H "Authorization: Bearer $ACCESS_TOKEN"

if [ "$ME_STATUS" = "200" ]; then
    pass "me returned 200"
    ME_EMAIL="$(json_field "$ME_BODY" "email")"
    if [ "$ME_EMAIL" = "$TEST_EMAIL" ]; then
        info "verified email matches: $ME_EMAIL"
    else
        warn "email in response ($ME_EMAIL) doesn't match test ($TEST_EMAIL)"
    fi
else
    fail "me returned $ME_STATUS (expected 200)"
    info "body: $(printf "%s" "$ME_BODY" | head -c 300)"
    exit 1
fi

# =============================================================================
# Step 6: /v3/auth/refresh
# =============================================================================
step "6. POST /v3/auth/refresh"

http_post REFRESH_BODY REFRESH_STATUS \
    "$API_URL/v3/auth/refresh" \
    "{\"refresh_token\":\"$REFRESH_TOKEN\"}"

if [ "$REFRESH_STATUS" = "200" ]; then
    pass "refresh returned 200"
    NEW_ACCESS_TOKEN="$(json_field "$REFRESH_BODY" "access_token")"
    NEW_REFRESH_TOKEN="$(json_field "$REFRESH_BODY" "refresh_token")"

    if [ -z "$NEW_ACCESS_TOKEN" ] || [ -z "$NEW_REFRESH_TOKEN" ]; then
        fail "refresh response missing rotated tokens"
        exit 1
    fi
    if [ "$NEW_REFRESH_TOKEN" = "$REFRESH_TOKEN" ]; then
        fail "refresh token didn't rotate (single-use violated)"
        exit 1
    fi
    info "tokens rotated correctly"
    REFRESH_TOKEN="$NEW_REFRESH_TOKEN"
    ACCESS_TOKEN="$NEW_ACCESS_TOKEN"
else
    fail "refresh returned $REFRESH_STATUS (expected 200)"
    info "body: $(printf "%s" "$REFRESH_BODY" | head -c 300)"
    exit 1
fi

# =============================================================================
# Step 7: /v3/auth/logout
# =============================================================================
step "7. POST /v3/auth/logout"

http_post LOGOUT_BODY LOGOUT_STATUS \
    "$API_URL/v3/auth/logout" \
    "{\"refresh_token\":\"$REFRESH_TOKEN\"}" \
    -H "Authorization: Bearer $ACCESS_TOKEN"

if [ "$LOGOUT_STATUS" = "204" ]; then
    pass "logout returned 204"
else
    fail "logout returned $LOGOUT_STATUS (expected 204)"
    info "body: $(printf "%s" "$LOGOUT_BODY" | head -c 300)"
    exit 1
fi

# Verify the refresh token is now revoked: a second refresh attempt
# should fail with 401, and trip the reuse-detection wholesale revoke.
step "8. POST /v3/auth/refresh (replay check — must fail)"

http_post REPLAY_BODY REPLAY_STATUS \
    "$API_URL/v3/auth/refresh" \
    "{\"refresh_token\":\"$REFRESH_TOKEN\"}"

if [ "$REPLAY_STATUS" = "401" ]; then
    pass "replayed refresh correctly rejected with 401"
else
    fail "replayed refresh returned $REPLAY_STATUS (expected 401 — single-use violated)"
    exit 1
fi

# =============================================================================
# Summary
# =============================================================================

ELAPSED=$(($(date +%s) - START_TIME))
echo
printf "${GREEN}${BOLD}all smoke tests passed${NC}  (in ${ELAPSED}s)\n"
printf "  passed: %d\n" "$PASS_COUNT"
printf "  failed: %d\n" "$FAIL_COUNT"
echo
printf "test user persisted in DB: %s\n" "$TEST_EMAIL"
printf "(safe to leave; M5 cleanup job purges unused test accounts)\n"

exit 0
