#!/usr/bin/env bash
# Wrapper script to ensure nvm/node is loaded before running build commands.
# This helps external tools (like WebNative extension) which may not source your shell rc.

set -euo pipefail

# Load nvm if installed
export NVM_DIR="$HOME/.nvm"
if [ -s "$NVM_DIR/nvm.sh" ]; then
  # shellcheck source=/dev/null
  . "$NVM_DIR/nvm.sh"
fi

# Use .nvmrc if present
if [ -f ".nvmrc" ]; then
  NODE_VERSION=$(cat .nvmrc)
  nvm install "$NODE_VERSION" >/dev/null
  nvm use "$NODE_VERSION"
fi

# Run build and capacitor copy using npm/npx directly (no extra npx wrapper)
npm run build -- --configuration=production
npx cap copy
