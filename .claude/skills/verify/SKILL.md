---
name: verify
description: Build/launch/drive recipe for verifying AccessGuard changes end-to-end (Drupal module + Node scanner)
---

# Verifying AccessGuard changes

## Scanner (Node service)

```bash
cd scanner && node src/server.js          # listens on :3000 (PORT to override)
SCANNER_AUTH_TOKEN=sekret PORT=3001 node src/server.js   # token-protected instance
curl -X POST http://127.0.0.1:3000/pdf -H 'content-type: application/json' \
  -d '{"html":"<!doctype html><html><body><h1>x</h1></body></html>"}'      # expect %PDF bytes
```

## Drupal (DDEV)

- `ddev describe` for the current 127.0.0.1 HTTP port (changes every start; do NOT use accessguard.ddev.site from scripts).
- Login for curl: `ddev drush uli --uri=http://127.0.0.1:<port>` then
  `curl -L -c cookies.txt '<login-url>'`; reuse `-b cookies.txt` for admin pages.
- Surfaces: `/admin/reports/accessguard` (overview), `/admin/reports/accessguard/rules` and `/authors` (analytics tabs), `/admin/reports/accessguard/export/pdf` (PDF), `/export` (CSV).
- The configured scanner endpoint is `http://accessguard-scanner:3000` (a DDEV service running the *committed* scanner image). To verify against working-tree scanner code, run it on the host and temporarily:
  `ddev drush cset accessguard.settings scanner_endpoint 'http://host.docker.internal:3000' -y`
  — and RESTORE the original value afterwards.
- After changing any service constructor or services.yml: `ddev drush cr`, or routes 500 with "controller not callable".
- Logs: `ddev drush watchdog:show --count=5 --severity=Error --format=yaml`.

## Gotchas

- Run drush through the Bash tool (`ddev drush ...`); PowerShell mangles the quoting.
- The site has demo content with real scans/violations/waivers — the dashboard and PDF render meaningful numbers without seeding.
- Always `git fetch` before assuming origin/main matches the local checkout — cloud/PR work lands on GitHub without touching this machine.
