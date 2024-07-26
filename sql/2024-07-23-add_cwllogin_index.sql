-- We use the cwl login to map to wiki user accounts
-- Had to remove "IF NOT EXISTS" cause we're running on ancient mariadb 5.7?!
CREATE INDEX ucead_cwllogin ON /*_*/user_cwl_extended_account_data(CWLLogin);
