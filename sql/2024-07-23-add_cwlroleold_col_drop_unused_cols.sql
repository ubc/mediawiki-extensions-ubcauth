ALTER TABLE /*_*/user_cwl_extended_account_data
    -- Drop unused columns
    DROP COLUMN IF EXISTS CWLSaltedID,
    DROP COLUMN IF EXISTS ubc_role_id,
    DROP COLUMN IF EXISTS ubc_dept_id,
    -- Add new columns
    ADD COLUMN IF NOT EXISTS CWLRolePast VARBINARY(1000) NOT NULL DEFAULT '' COMMENT 'Tells us if this person used to have a role even if their current CWLRole is empty.',
    ADD COLUMN IF NOT EXISTS date_updated DATETIME ON UPDATE CURRENT_TIMESTAMP,
    -- Increase column length to 255
    MODIFY COLUMN IF EXISTS puid VARBINARY(255) NOT NULL,
    MODIFY COLUMN IF EXISTS CWLLogin VARBINARY(255) NOT NULL,
    MODIFY COLUMN IF EXISTS CWLNickname VARBINARY(1000) NOT NULL,
    MODIFY COLUMN IF EXISTS CWLRole VARBINARY(255) NOT NULL,
    MODIFY COLUMN IF EXISTS account_status VARBINARY(255) NOT NULL;
