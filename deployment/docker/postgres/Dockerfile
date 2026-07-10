# Dedicated Postgres for Travel Companion: PostGIS + pgvector on PostgreSQL 18.
# The shared staging Postgres (pgvector/pgvector:pg16) has no PostGIS, and this
# product is geospatial-first (PRD §9.3, §14.2). Built and pushed by
# .github/workflows/postgres-image.yml (not on every app deploy).
#
# Based on the official multi-arch postgres:18 (amd64 + arm64) so it builds on
# both the amd64 server/CI and Apple Silicon dev machines. (postgis/postgis:18-*
# is amd64-only, which breaks local arm64 builds.) The official image ships the
# PGDG apt repo, so PostGIS and pgvector install straight from apt.
FROM postgres:18-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        postgresql-18-postgis-3 \
        postgresql-18-postgis-3-scripts \
        postgresql-18-pgvector \
    && rm -rf /var/lib/apt/lists/*

# Enable both extensions in every new database created from template1.
# (Migrations should also `CREATE EXTENSION IF NOT EXISTS ...` to be self-contained.)
COPY deployment/docker/postgres/init/10-extensions.sql /docker-entrypoint-initdb.d/10-extensions.sql
