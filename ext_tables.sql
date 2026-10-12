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
