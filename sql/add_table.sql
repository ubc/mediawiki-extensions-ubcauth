-- Note that Mediawiki has a war on VARCHAR as they don't want to deal with
-- collation issues, we're using VARBINARY cause that's what Mediawiki converts
-- VARCHAR to anyway
CREATE TABLE IF NOT EXISTS /*_*/user_cwl_extended_account_data
(
 ucad_id        int                auto_increment primary key,
 user_id        int                not null,
 puid           varbinary(255)     not null,
 CWLLogin       varbinary(255)     not null,
 CWLNickname    varbinary(1000)     not null,
 CWLRole        varbinary(255)     not null,
 CWLRolePast    varbinary(1000)     not null default '' comment 'Lets us know this person used to be affiliated with UBC even if their current CWLRole is empty. the autoblocker will skip accounts with a past role.',
 wgDBprefix     varbinary(150)     not null,
 date_created   timestamp          not null default CURRENT_TIMESTAMP,
 date_updated   DATETIME           on update CURRENT_TIMESTAMP,
 account_status varbinary(255)     not null
) /*$wgDBTableOptions*/;
