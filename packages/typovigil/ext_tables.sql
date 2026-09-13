CREATE TABLE tx_typovigil_project (
    title varchar(255) DEFAULT '' NOT NULL,
    token_hash varchar(255) DEFAULT '' NOT NULL,
    last_report_at int(11) unsigned DEFAULT 0 NOT NULL,
    core_version varchar(32) DEFAULT '' NOT NULL,
    notes text,
    fe_users int(11) unsigned DEFAULT 0 NOT NULL,

    KEY token_hash (token_hash)
);

-- No uid: TYPO3's schema migration only adds one to tables that have TCA, and
-- this one deliberately has none — it is written by the report middleware and
-- never edited in the backend. Rows are addressed by (project, composer_name).
CREATE TABLE tx_typovigil_package (
    project int(11) unsigned DEFAULT 0 NOT NULL,
    -- when the agent last reported this package; checked_at is something else
    -- entirely, namely when it was last matched against the upstream sources.
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    composer_name varchar(255) DEFAULT '' NOT NULL,
    extension_key varchar(255) DEFAULT '' NOT NULL,
    installed_version varchar(64) DEFAULT '' NOT NULL,
    latest_version varchar(64) DEFAULT '' NOT NULL,
    severity varchar(16) DEFAULT 'ok' NOT NULL,
    advisory_json text,
    checked_at int(11) unsigned DEFAULT 0 NOT NULL,
    is_core tinyint(1) unsigned DEFAULT 0 NOT NULL,

    KEY project (project),
    KEY project_package (project, composer_name),
    KEY severity (severity)
);

-- Reachability of each upstream source at its last query.
--
-- A table rather than the cache: this records what happened, not a result that
-- can be recomputed. The entrypoint flushes every cache on container start, so
-- a cached status vanished on each deployment and the footer fell back to
-- "not queried yet" even though the check had run.
--
-- No uid, like tx_typovigil_package: no TCA, never edited in the backend. Rows
-- are addressed by host.
CREATE TABLE tx_typovigil_source (
    host varchar(255) DEFAULT '' NOT NULL,
    reachable tinyint(1) unsigned DEFAULT 0 NOT NULL,
    checked_at int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (host)
);

CREATE TABLE tx_typovigil_project_feuser_mm (
    uid_local int(11) unsigned DEFAULT 0 NOT NULL,
    uid_foreign int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT 0 NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);
