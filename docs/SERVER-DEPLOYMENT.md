# Server & Deployment Reference

Copied and adapted from the stock-intelligence repo, reflecting the **actual** state of the
shared Hetzner staging box as inspected on 2026-07-10. Travel Companion is deployed as an
additional app alongside the existing ones.

## Target

| Environment | Domain | Status |
|---|---|---|
| Staging | `travel.bergsten.net` | This app (set up here) |

DNS: an `A` record for `travel.bergsten.net` → `157.180.31.231` must exist in Cloudflare
(user handles this). Traefik issues the TLS cert automatically via Let's Encrypt HTTP-01 on
first request, so the DNS record must resolve **before** the first deploy completes.

## Hetzner VPS (shared staging host)

| Property | Value |
|---|---|
| Provider | Hetzner |
| IP | `157.180.31.231` |
| OS | Ubuntu 24.04 LTS |
| SSH | `ssh -i ~/.ssh/hetzner-laravel -o IdentitiesOnly=yes root@157.180.31.231` |
| File owner | `deploy:docker` (SSH is root; app dirs owned by `deploy`) |
| Resources | ~3.7 GiB RAM (**tight** — ~1.2 GiB available), 38 GB disk (~11 GB free) |

**This box is shared.** Other apps running here: `stock-intelligence`, `refinepress`.
Do not disrupt their containers, the shared proxy, or shared data services.

## Shared infrastructure (do NOT recreate per-app)

| Component | Container | Image | Notes |
|---|---|---|---|
| Reverse proxy / TLS | `traefik` | `traefik:v3.6.1` | Let's Encrypt (`le` resolver, HTTP challenge, email mats@beet.se). Config at `/srv/staging/proxy/`. `exposedbydefault=false` → every app must opt in with `traefik.enable=true` labels |
| Redis | `staging-redis` | `redis:7` | Shared cache/queue/session store. Travel **reuses** this with a distinct key prefix to avoid collisions |
| Postgres (shared) | `staging-postgres` | `pgvector/pgvector:pg16` | **No PostGIS, and pg16.** Travel Companion needs PostGIS + prefers pg18 → **does NOT use this**; runs its own DB container instead (see below) |

### Docker networks (both external, shared)

- `web` — Traefik-facing. App web container joins this.
- `internal` — app ↔ data services. App, scheduler, and the dedicated Postgres join this.

## Travel Companion's own containers

Because the shared Postgres lacks PostGIS, Travel runs a **dedicated** Postgres. Everything
else follows the house pattern (image from GHCR, `.env.docker` bind-mount, Traefik labels).

| Container | Role | Networks |
|---|---|---|
| `travel-companion-app` | Nginx + PHP-FPM + Horizon (supervisord) | `web`, `internal` |
| `travel-companion-scheduler` | `php artisan schedule:work` | `internal` |
| `travel-postgres` | PostGIS + pgvector on PG18 (custom image, see `deployment/docker/postgres/`) | `internal` |
| (reuses `staging-redis`) | cache / queue / sessions | `internal` |

> RAM note: this adds ~3 containers to an already-tight box. FPM is capped low
> (`FPM_PM_MAX_CHILDREN=8`) for staging. Watch `free -h` after first deploy; if the box
> is thrashing, the dedicated Postgres is the first candidate to move to a managed DB.

## On-server layout

```
/srv/staging/
├── proxy/                         # shared Traefik (do not touch)
├── shared/{postgres,redis}/       # shared data services
└── apps/
    ├── stock-intelligence/
    ├── refinepress/
    └── travel-companion/          # THIS app
        ├── docker-compose.yml     # from repo deployment/staging/docker-compose.yml
        ├── .env                   # holds IMAGE_TAG (written by CI); NOT secrets
        └── .env.docker            # app secrets, bind-mounted to /var/www/app/.env
```

- `.env` (host) holds only `IMAGE_TAG` — the deployed image tag. CI writes the commit SHA here.
- `.env.docker` holds real secrets, bind-mounted into all app containers at `/var/www/app/.env`.
  Never committed. Edit host-side, then `docker exec travel-companion-app php artisan config:clear`.

## Deployment pipeline (SHA-pinned)

Push to `main` → `.github/workflows/deploy-staging.yml`:

1. Build image, push to `ghcr.io/rockstoneaidev/travel-companion:{sha}` and `:latest`.
2. SSH to the box and run, in `/srv/staging/apps/travel-companion`:
   ```bash
   echo "IMAGE_TAG=${SHA}" > .env
   docker compose pull
   docker compose up -d
   docker compose exec -T app php artisan migrate --force
   docker image prune -f
   ```

**Rollback:** `echo "IMAGE_TAG=<previous-sha>" > .env && docker compose pull && docker compose up -d`.

The dedicated Postgres image (`ghcr.io/rockstoneaidev/travel-postgres:pg18`) is built and pushed
by a separate, rarely-run workflow (`.github/workflows/postgres-image.yml`), not on every deploy.

### GitHub repository secrets (set on `rockstoneaidev/travel-companion`)

| Secret | Value |
|---|---|
| `STAGING_HOST` | `157.180.31.231` |
| `STAGING_USER` | `root` |
| `STAGING_SSH_KEY` | contents of `~/.ssh/hetzner-laravel` (private key) |
| `GH_PACKAGES_TOKEN` | PAT with `read:packages` (Composer private pkgs during build; optional) |

## Common operations

```bash
docker exec travel-companion-app php artisan <cmd>       # artisan
docker exec travel-companion-app php artisan migrate --force
docker logs -f travel-companion-app                      # logs
cd /srv/staging/apps/travel-companion && docker compose restart app scheduler
```
