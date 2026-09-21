CREATE TABLE tx_typovigil_project (
    title varchar(255) DEFAULT '' NOT NULL,
    site_url varchar(255) DEFAULT '' NOT NULL,
    token_hash varchar(255) DEFAULT '' NOT NULL,
    last_report_at int(11) unsigned DEFAULT 0 NOT NULL,
    core_version varchar(32) DEFAULT '' NOT NULL,
    notes text,
    fe_users int(11) unsigned DEFAULT 0 NOT NULL,

    -- Optional Coolify linkage: empty on every project until someone fills it
    -- in for one that actually runs there. A project without these stays
    -- plain monitoring, exactly as before.
    coolify_application_uuid varchar(64) DEFAULT '' NOT NULL,
    -- The volume's own uuid on the platform (Persistent Storage), which is
    -- what both the backup trigger and the schedule creation address.
    coolify_storage_uuid varchar(64) DEFAULT '' NOT NULL,
    -- The schedule created from it by typovigil:onboard-coolify. Kept apart
    -- from the volume so re-running the onboarding can tell "already done"
    -- from "not yet" without guessing at the shape of a uuid.
    coolify_storage_backup_uuid varchar(64) DEFAULT '' NOT NULL,
    coolify_database_uuid varchar(64) DEFAULT '' NOT NULL,
    coolify_db_scheduled_backup_uuid varchar(64) DEFAULT '' NOT NULL,
    last_backup_at int(11) unsigned DEFAULT 0 NOT NULL,
    last_backup_status varchar(255) DEFAULT '' NOT NULL,
    -- Fingerprint of the package state the last successful backup was taken
    -- against. An update is only allowed while this still matches: once the
    -- agent reports different versions, that backup no longer contains what
    -- is about to be updated, so it has to be taken again.
    last_backup_state varchar(64) DEFAULT '' NOT NULL,

    -- Optional GitHub linkage, "owner/repo". Empty means the update button
    -- stays hidden for this project: without a repository there is nowhere to
    -- open a pull request, and updating the live installation directly is
    -- exactly what this feature avoids.
    github_repo varchar(255) DEFAULT '' NOT NULL,
    -- When someone last requested an update. Not "when it was updated": the
    -- pull request may sit unmerged for days, and the agent keeps reporting
    -- the old version until a deploy actually happens.
    update_requested_at int(11) unsigned DEFAULT 0 NOT NULL,

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

    -- AI risk report for a critical finding. Written by the automatic
    -- analysis run, shown only to the agency role in the customer portal,
    -- approved there by a click — no technical update execution yet, see
    -- AnalyzeCriticalPackageService.
    ai_report_json text,
    ai_report_status varchar(16) DEFAULT '' NOT NULL,
    ai_report_created_at int(11) unsigned DEFAULT 0 NOT NULL,
    -- The finding the stored report describes: "name@installed@latest". A run
    -- that would produce this same subject again is skipped, so an unchanged
    -- critical package costs one AI call in total, not one per hourly run.
    ai_report_subject varchar(255) DEFAULT '' NOT NULL,

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

-- Counter column for the customer's side of the project relation. TYPO3 needs
-- it on the local table even though the relation itself lives in the MM table.
CREATE TABLE fe_users (
    tx_typovigil_projects int(11) unsigned DEFAULT 0 NOT NULL
);

CREATE TABLE tx_typovigil_project_feuser_mm (
    uid_local int(11) unsigned DEFAULT 0 NOT NULL,
    uid_foreign int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT 0 NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);
