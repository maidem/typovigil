CREATE TABLE tx_typovigil_project (
    title varchar(255) DEFAULT '' NOT NULL,
    token_hash varchar(255) DEFAULT '' NOT NULL,
    last_report_at int(11) unsigned DEFAULT 0 NOT NULL,
    core_version varchar(32) DEFAULT '' NOT NULL,
    notes text,
    fe_users int(11) unsigned DEFAULT 0 NOT NULL,

    KEY token_hash (token_hash)
);

CREATE TABLE tx_typovigil_package (
    project int(11) unsigned DEFAULT 0 NOT NULL,
    composer_name varchar(255) DEFAULT '' NOT NULL,
    extension_key varchar(255) DEFAULT '' NOT NULL,
    installed_version varchar(64) DEFAULT '' NOT NULL,
    latest_version varchar(64) DEFAULT '' NOT NULL,
    severity varchar(16) DEFAULT 'ok' NOT NULL,
    advisory_json text,
    checked_at int(11) unsigned DEFAULT 0 NOT NULL,
    is_core tinyint(1) unsigned DEFAULT 0 NOT NULL,

    KEY project (project),
    KEY severity (severity)
);

CREATE TABLE tx_typovigil_project_feuser_mm (
    uid_local int(11) unsigned DEFAULT 0 NOT NULL,
    uid_foreign int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT 0 NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);
