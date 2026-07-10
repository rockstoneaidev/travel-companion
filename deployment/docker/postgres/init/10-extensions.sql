-- Runs once on first cluster init, against the POSTGRES_DB database created by the
-- entrypoint (travel_companion). Enables the extensions the app relies on. Migrations
-- also CREATE EXTENSION IF NOT EXISTS so a fresh non-Docker database is self-contained.
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS vector;
