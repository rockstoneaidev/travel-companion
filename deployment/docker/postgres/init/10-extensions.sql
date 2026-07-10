-- Runs once on first cluster init (empty data dir). Enables the extensions in
-- template1 so every database — including the app DB — inherits them.
\connect template1
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS vector;
