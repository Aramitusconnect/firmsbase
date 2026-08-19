-- FirmsBase preproduction database bootstrap.
--
-- NOT EXECUTED BY TERRAFORM. Run once, by hand or by a controlled runner,
-- against the freshly created preproduction RDS instance, BEFORE the one-off
-- migrate task. Nothing here creates a schema object — the certified release's
-- own 275 migrations are the sole authority for schema.
--
-- This is not a proposed contract. Every attribute and grant below was read
-- from the live staging database, which is the environment where this exact
-- 275-migration set has already been applied to completion by
-- firmsbase_migrator with exit code 0 and no permission-denied,
-- must-be-owner-of-relation, or RLS errors. It is reproduced, not invented.
--
-- Executed as: the RDS master user (firmsbase_root), whose credential lives in
-- firmsbase/preprod/database-master. That identity is used ONLY here. No ECS
-- task references it, and it must never appear in a task definition.
--
-- Passwords are never written in this file. Supply them as psql variables:
--
--   psql "host=<endpoint> port=5432 dbname=postgres user=firmsbase_root sslmode=require" \
--     -v migrator_pw="$(aws secretsmanager get-secret-value \
--          --secret-id firmsbase/preprod/database-migrator \
--          --query SecretString --output text | jq -r .password)" \
--     -v app_pw="$(aws secretsmanager get-secret-value \
--          --secret-id firmsbase/preprod/database-app \
--          --query SecretString --output text | jq -r .password)" \
--     -f 01-create-roles-and-database.sql
--
-- rds.force_ssl is set to 1 by the parameter group in database.tf, so
-- sslmode=require above is enforced by the server, not merely requested.

\set ON_ERROR_STOP on

-- ---------------------------------------------------------------------------
-- Roles.
--
-- Neither role bypasses RLS — including the migrator, even though it owns the
-- tables that FORCE ROW LEVEL SECURITY applies to. The certified policies
-- carry no TO clause, so they bind every role including the owner. The 275
-- migrations complete under exactly these attributes; BYPASSRLS is not
-- required and must not be granted.
-- ---------------------------------------------------------------------------

CREATE ROLE firmsbase_migrator
  LOGIN
  PASSWORD :'migrator_pw'
  NOSUPERUSER
  NOCREATEDB
  NOCREATEROLE
  NOBYPASSRLS
  INHERIT;

CREATE ROLE firmsbase_app
  LOGIN
  PASSWORD :'app_pw'
  NOSUPERUSER
  NOCREATEDB
  NOCREATEROLE
  NOBYPASSRLS
  INHERIT;

-- ---------------------------------------------------------------------------
-- Database.
--
-- Terraform's aws_db_instance already creates db_name = firmsbase_preprod, so
-- the CREATE DATABASE below is normally a no-op and is left commented. It is
-- retained as documentation of the intended owner if the database is ever
-- created manually instead.
-- ---------------------------------------------------------------------------

-- CREATE DATABASE firmsbase_preprod;

REVOKE ALL ON DATABASE firmsbase_preprod FROM PUBLIC;
GRANT CONNECT ON DATABASE firmsbase_preprod TO firmsbase_migrator;
GRANT CONNECT ON DATABASE firmsbase_preprod TO firmsbase_app;

\connect firmsbase_preprod

-- ---------------------------------------------------------------------------
-- Schema privileges.
--
-- The public schema is owned by pg_database_owner (the PostgreSQL 15+ default,
-- confirmed on the live staging database). The migrator gets CREATE; the app
-- role deliberately does NOT, which is what makes "the runtime cannot perform
-- DDL" a database-enforced property rather than a convention.
-- ---------------------------------------------------------------------------

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

GRANT USAGE, CREATE ON SCHEMA public TO firmsbase_migrator;
GRANT USAGE          ON SCHEMA public TO firmsbase_app;

-- ---------------------------------------------------------------------------
-- Default privileges.
--
-- Set FOR ROLE firmsbase_migrator so they attach to every object the migration
-- run creates. This is why no post-migration GRANT sweep is needed and why the
-- live staging database shows uniform coverage across all of its tables.
--
-- Run as the migrator itself: pg_default_acl records defaclrole, and it must
-- be firmsbase_migrator for these to apply to migrator-created objects.
--
-- The app role receives exactly SELECT/INSERT/UPDATE/DELETE on tables. It is
-- NOT granted TRUNCATE, REFERENCES or TRIGGER — matching the live contract.
-- ---------------------------------------------------------------------------

SET ROLE firmsbase_migrator;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO firmsbase_app;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO firmsbase_app;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE ON TYPES TO firmsbase_app;

RESET ROLE;

-- ---------------------------------------------------------------------------
-- Post-bootstrap verification (read-only; run before the migrate task).
--
-- Expected:
--   both roles     super=f createdb=f createrole=f bypassrls=f login=t inherit=t
--   app CREATE on public  = f
--   migrator CREATE       = t
--   default ACL entries   = 3, all defaclrole = firmsbase_migrator
-- ---------------------------------------------------------------------------

SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolcanlogin,
       rolbypassrls, rolinherit
  FROM pg_roles
 WHERE rolname IN ('firmsbase_app', 'firmsbase_migrator')
 ORDER BY rolname;

SELECT has_schema_privilege('firmsbase_app', 'public', 'CREATE')      AS app_create_must_be_false,
       has_schema_privilege('firmsbase_app', 'public', 'USAGE')       AS app_usage_must_be_true,
       has_schema_privilege('firmsbase_migrator', 'public', 'CREATE') AS migrator_create_must_be_true;

SELECT defaclrole::regrole::text AS grantor,
       defaclobjtype             AS object_type,
       array_to_string(defaclacl, ',') AS acl
  FROM pg_default_acl
 ORDER BY defaclobjtype;
