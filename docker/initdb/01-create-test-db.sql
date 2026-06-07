-- Provision a separate database for the integration test suite.
-- The MYSQL_DATABASE env var on the mysql service creates `xml2emmet`;
-- this script adds `xml2emmet_test` so phpunit's HTTP/Db tests have an
-- isolated namespace from the dev runtime.
CREATE DATABASE IF NOT EXISTS xml2emmet_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
