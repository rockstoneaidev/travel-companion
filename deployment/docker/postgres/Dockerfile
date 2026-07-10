# Dedicated Postgres for Travel Companion: PostGIS + pgvector on PostgreSQL 18.
# The shared staging Postgres (pgvector/pgvector:pg16) has no PostGIS, and this
# product is geospatial-first (PRD §9.3, §14.2). Built and pushed by
# .github/workflows/postgres-image.yml (not on every app deploy).
#
# Base: official PostGIS image (Debian, PGDG apt repo preconfigured).
# If the 18-3.6 tag is unavailable, fall back to postgis/postgis:18-master.
FROM postgis/postgis:18-3.6

# pgvector from the PGDG apt repo that the PostGIS image already trusts.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-18-pgvector \
    && rm -rf /var/lib/apt/lists/*

# Enable both extensions in every new database created from template1.
# (Migrations should also `CREATE EXTENSION IF NOT EXISTS ...` to be self-contained.)
COPY deployment/docker/postgres/init/10-extensions.sql /docker-entrypoint-initdb.d/10-extensions.sql
