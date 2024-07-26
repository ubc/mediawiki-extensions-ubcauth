ALTER TABLE /*_*/user_cwl_extended_account_data
    -- Drop unused columns
    DROP COLUMN CWLSaltedID,
    DROP COLUMN ubc_role_id,
    DROP COLUMN ubc_dept_id,
    -- Add new columns
    ADD COLUMN CWLRolePast VARBINARY(1000) NOT NULL DEFAULT '' COMMENT 'Tells us if this person used to have a role even if their current CWLRole is empty.',
    ADD COLUMN date_updated DATETIME ON UPDATE CURRENT_TIMESTAMP,
    -- Increase column length to 255
    MODIFY COLUMN puid VARBINARY(255) NOT NULL,
    MODIFY COLUMN CWLLogin VARBINARY(255) NOT NULL,
    MODIFY COLUMN CWLNickname VARBINARY(1000) NOT NULL,
    MODIFY COLUMN CWLRole VARBINARY(255) NOT NULL,
    MODIFY COLUMN account_status VARBINARY(255) NOT NULL;
