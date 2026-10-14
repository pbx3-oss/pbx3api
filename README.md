# pbx3api

**How this was built:** PBX3 was **designed by humans** in mid/late 2025. Most of the code was **implemented in Cursor** under **human-directed AI** assistance — not unattended generation, and not a claim that AI is a panacea.

**Operator documentation (all PBX3 repos):** [pbx3-docs (MkDocs)](https://pbx3-oss.github.io/pbx3-docs/)

- **Way forward:** classical install / admin procedure pages and the shipped installers in these repositories.
- **Optional co-pilot:** if you already use an AI coding agent, start at [AI-assisted operations](https://pbx3-oss.github.io/pbx3-docs/getting-started/ai-assisted/) — same scripts and human gates. Not unattended; **AI is not a panacea**.

Laravel 11 API service for `pbx3`.

## Webserver ownership

`pbx3api` owns the API web interface (nginx + PHP-FPM) and serves HTTPS on port `44300`.

- Nginx site config: `config/nginx/pbx3-api.conf`
- Installer entrypoint: `scripts/installer.sh`
- Install helper: `scripts/install-nginx-site.sh`
- Remove helper: `scripts/remove-nginx-site.sh`
- Deployment guide: `docs/deployment-nginx.md`

The installer is intended to be one-command on Ubuntu 24.04:

```sh
sudo sh scripts/installer.sh
```

It installs runtime packages, runs `composer install` when needed, and deploys/enables the nginx site on `44300`.
It also bootstraps Laravel runtime state (`.env`, symlink to PBX sqlite DB, writable cache/log dirs, artisan cache/key commands).
Installer ownership model keeps code as `root:root` and grants write access only to Laravel runtime dirs.

## Certificate ownership

TLS certificate acquisition, renewal, and **active path selection** (commercial/custom vs Let’s Encrypt vs snakeoil) are owned by **`pbx3`**, not `pbx3api`.

**Canonical documentation (three files in the pbx3 repo, `workingdocs/`):**

1. **`pbx3/workingdocs/TLS_AND_CERTIFICATES.md`** — index + overview  
2. **`pbx3/workingdocs/CERTIFICATES_PANEL_AND_API.md`** — panel + `/certificates/*` API  
3. **`pbx3/workingdocs/LETSENCRYPT_PER_TENANT_FQDN.md`** — **Option A** (multi-SAN), **§12** phases  

The nginx site in this repo references certificate paths only and typically uses snakeoil until a public cert is applied on the host.

## Development notes

This is a standard Laravel application (`php` 8.3+ on Ubuntu 24.04 target hosts). Existing API docs are in `docs/`.
