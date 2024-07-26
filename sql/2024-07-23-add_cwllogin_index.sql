-- We use the cwl login to map to wiki user accounts
CREATE INDEX IF NOT EXISTS ucead_cwllogin ON /*_*/user_cwl_extended_account_data(CWLLogin);
