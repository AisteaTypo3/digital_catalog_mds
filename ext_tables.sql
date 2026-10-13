#
# Technical data per article, read from the ordering tables of the ordering catalogs / surgical techniques
# (command digitalcatalog:extract-specifications, SpecificationParser). Keyed by the article number of the doc_manager.
#
CREATE TABLE tx_digitalcatalog_specification (
    uid int(11) unsigned NOT NULL auto_increment,
    article_number varchar(64) DEFAULT '' NOT NULL,
    section varchar(255) DEFAULT '' NOT NULL,
    attributes text,
    source_file int(11) unsigned DEFAULT '0' NOT NULL,
    source_page int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY article (article_number)
);

#
# What the last run read from each PDF (digitalcatalog:extract-specifications): unchanged PDFs (same sys_file.sha1,
# same parser version) are not read again.
#
CREATE TABLE tx_digitalcatalog_specification_source (
    uid int(11) unsigned NOT NULL auto_increment,
    file_uid int(11) unsigned DEFAULT '0' NOT NULL,
    file_hash varchar(64) DEFAULT '' NOT NULL,
    parser_version int(11) unsigned DEFAULT '0' NOT NULL,
    records mediumtext,
    row_count int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY file (file_uid)
);

#
# Case reports (file metadata type "Case Report") and the implant families they are about, as section titles of
# tx_digitalcatalog_specification: found by CaseReportMatcher (sections), added or taken out in the backend module
# (manual_sections, excluded_sections; kept by the matching). The text is kept, so unchanged reports are not read
# again.
#
CREATE TABLE tx_digitalcatalog_casereport (
    uid int(11) unsigned NOT NULL auto_increment,
    file_uid int(11) unsigned DEFAULT '0' NOT NULL,
    file_hash varchar(64) DEFAULT '' NOT NULL,
    content mediumtext,
    sections text,
    manual_sections text,
    excluded_sections text,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY file (file_uid)
);

#
# The article numbers named in each product information / product overview PDF, so the article page lists the
# documents an article is really in (digitalcatalog:extract-specifications). Unchanged PDFs are not read again.
#
CREATE TABLE tx_digitalcatalog_document_articles (
    uid int(11) unsigned NOT NULL auto_increment,
    file_uid int(11) unsigned DEFAULT '0' NOT NULL,
    file_hash varchar(64) DEFAULT '' NOT NULL,
    articles mediumtext,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY file (file_uid)
);

#
# Demand for the catalog articles, as counters per month (DemandRepository): articles asked for in the inquiry
# form (with quantity and country), put on the wishlist, and searches without result. No personal data: no names,
# e-mail addresses, IP addresses, sessions or times, only what was asked for, how often, from which country.
#
CREATE TABLE tx_digitalcatalog_demand (
    uid int(11) unsigned NOT NULL auto_increment,
    period int(11) unsigned DEFAULT '0' NOT NULL,
    type varchar(20) DEFAULT '' NOT NULL,
    article_number varchar(64) DEFAULT '' NOT NULL,
    country varchar(2) DEFAULT '' NOT NULL,
    term varchar(100) DEFAULT '' NOT NULL,
    hits int(11) unsigned DEFAULT '0' NOT NULL,
    quantity int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY entry (period, type, article_number, country, term)
);
