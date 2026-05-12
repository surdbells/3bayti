# WebNative / Extension build helper

This project includes a small wrapper script to ensure Node (via nvm) is loaded before running the web build and Capacitor sync. Some extensions (for example WebNative or other CI agents) spawn shells that don't source `~/.zshrc`, which can lead to `npx: command not found`.

Files added
- `.nvmrc` — pinned Node version used by the project (24.12.0).
- `scripts/wn-build.sh` — wrapper that loads nvm, uses `.nvmrc`, runs the production build and `npx cap copy`.

How to run

Recommended (from project root):

```bash
# runs the wrapper which ensures node/npx is available
npm run wn-build
```

Or run the wrapper directly:

```bash
./scripts/wn-build.sh
```

If you prefer system Node, make sure Node/npm/npx are on PATH (for example via Homebrew or nodejs.org installer). To ensure contributors use the same Node version, use `nvm` (the repo contains `.nvmrc`).

Troubleshooting
- If `nvm` isn't installed, the wrapper will fall back to whatever `node` is on PATH. Install nvm with:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.6/install.sh | bash
``` 

- If an extension still cannot run the script, configure the extension to run `npm run wn-build` or call `./scripts/wn-build.sh` explicitly.
