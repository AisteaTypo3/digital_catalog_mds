CREATE TABLE tx_digitalcatalog_wishlist (
    uid int(10) unsigned NOT NULL AUTO_INCREMENT,
    pid int(10) unsigned NOT NULL DEFAULT 0,
    tstamp int(10) unsigned NOT NULL DEFAULT 0,
    crdate int(10) unsigned NOT NULL DEFAULT 0,
    deleted smallint(5) unsigned NOT NULL DEFAULT 0,
    session_id varchar(255) NOT NULL DEFAULT '',
    article_uids text NOT NULL,
    name varchar(255) NOT NULL DEFAULT '',
    email varchar(255) NOT NULL DEFAULT '',
    company varchar(255) NOT NULL DEFAULT '',
    message text NOT NULL,
    submitted smallint(5) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    KEY parent (pid,deleted)
);
