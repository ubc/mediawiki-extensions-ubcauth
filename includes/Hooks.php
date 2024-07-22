<?php

namespace MediaWiki\Extension\UBCAuth;

use Exception;
use DatabaseUpdater;
use User;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class Hooks {
    public const CWL_DATA_SESSION_KEY = "CWL_DATA";
    public const LOGGER_UBC_AUTH = 'UBCAuth';
    public const SYSTEM_USER_UBC_AUTH = 'UBCAuth Extension';

    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
        $updater->addExtensionTable( 'user_cwl_extended_account_data', dirname( __DIR__ ) . '/sql/add_table.sql' );
    }

    /**
     * Link up the auto-created user with cwl login
     *
     * @param User $user
     * @param bool $autocreated
     */
    public static function onLocalUserCreated( $user, $autocreated ) {
        if ( $autocreated ) {
            if ( !static::_create_cwl_extended_account_data( $user ) ) {
                throw new Exception('Failed to create CWL extended data record');
            }
        }
    }

    public static function onUserLoggedIn( $user ) {
        if ( !static::_update_cwl_extended_account_data( $user ) ) {
            throw new Exception('Failed to update CWL extended data record');
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // helper functions
    ///////////////////////////////////////////////////////////////////////////
    
    /**
     * Get the user extended CWL data for the given user's cwl login name.
     * Returns the database row if found, false otherwise.
     */
    public static function getUceadByCwlLogin( string $cwlLogin ) {
        # TODO: getConnectionProvider() is only available after REL1.42
        #$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        # note, CWLRole/puid/CWLNickname fields are needed for checking if we
        # need to update extended cwl data
        $row = $dbr->newSelectQueryBuilder()
                   ->select( [ 'u.user_name', 'ucead.CWLLogin', 'ucead.CWLRole', 'ucead.puid', 'ucead.CWLNickname' ] )
                   ->from( 'user_cwl_extended_account_data', 'ucead' )
                   ->join( 'user', 'u', 'ucead.user_id=u.user_id' )
                   ->where( [ 'ucead.CWLLogin' => $cwlLogin ] )
                   ->caller( __METHOD__ )->fetchRow();
        return $row;
    }

    /**
     * _block_user_if_basic_cwl - spam bots only have basic CWL, so we're going
     * to start off every basic CWL user as blocked. Real users can contact the
     * wiki team to get unblocked on a case-by-case basis. Assuming that basic
     * CWL users have an empty affiliation list.
     *
     * Mediawiki doesn't really support deleting users, hence why we're blocking
     * them instead.
     *
     * @param User $user
     */
    private static function _block_user_if_basic_cwl($user, $ubcAffiliation) {
        if ($ubcAffiliation) return;
        # a missing affiliation indicates a basic cwl user, we want to block the
        # account permanently. Note that by default, 'autoblocking' is enabled
        # which also temporarily blocks the user's IP address for 24 hours.
        $blockUserFactory = MediaWikiServices::getInstance()->getBlockUserFactory();
        $performer = User::newSystemUser( static::SYSTEM_USER_UBC_AUTH,
            [ 'steal' => true ] ); # steal means disable account for normal use
        $res = $blockUserFactory->newBlockUser(
            $user,
            $performer,
            'infinity',
            'UBC Wiki no longer allows the use of a Basic CWL account for security reasons. Please contact the LT Hub for assistance.',
            [
                'isCreateAccountBlocked' => true,
                'isEmailBlocked' => true, # can't use Special:EmailUser
                'isUserTalkEditBlocked' => true, # can't edit their own user page
                'isAutoblocking' => true, # ban ip for 1 day
                'isHardBlock' => true, # ban ip again if they try to login later
            ]
        )->placeBlockUnsafe(); # unsafe just means no need to check permissions
        $log = static::_get_log();
        if (!$res->isOK()) {
            $log->error("Failed to block Basic CWL User: " . $user->getName());
            $log->error($res->getMessage()->text());
        }
    }

    /**
     * _create_cwl_extended_account_data  - insert new record to cwl_extended_account_data
     *
     * @param string $user_id Mediawiki user_id
     * @param string $puid user PUID
     * @param string $cwlLoginName
     * @param string $ubcAffiliation
     * @param string $real_name
     * @return bool
     */
    private static function _create_cwl_extended_account_data( $user ) {
        global $wgDBprefix;

        # TODO: wfGetDB() deprecated in 1.39, use MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase() in 1.42
        $dbw = wfGetDB( DB_MASTER );
        $table = $wgDBprefix."user_cwl_extended_account_data";

        $cwl_data = static::_get_cwl_data();

        if ( $cwl_data['wiki_username'] != $user->getName() ) {
            throw new Exception( 'Problem linking new user with CWL account' );
        }
        #$log = static::_get_log();
        #$log->debug("---------------");
        #$log->debug(print_r($cwl_data, true));
        #$log->debug("---------------");
        static::_block_user_if_basic_cwl($user, $cwl_data['ubcAffiliation']);

        $insert_a = array(
            'user_id' => $user->getId(),
            'puid'    => $cwl_data['puid'],
            'ubc_role_id' => '',  // no longer captured doing SSO
            'ubc_dept_id' => '', // no longer captured doing SSO
            'wgDBprefix' => $wgDBprefix,
            'CWLLogin' => $cwl_data['cwl_login_name'],
            'CWLRole' => $cwl_data['ubcAffiliation'],
            'CWLNickname' => $cwl_data['full_name'],
            //'CWLSaltedID' => $CWLSaltedID, // no longer needed using PUID
            'account_status' => 1   //might never be used.
        );

        # TODO: use new db API InsertQueryBuilder when we upgrade >= REL1.41
        $res_ad = $dbw->insert( $table, $insert_a );
        return $res_ad;
    }
    
    /**
     * Update CWL extended account data, we have a lot of users with empty
     * fields due to our LDAP attributes being restricted at some point,
     * hopefully this will fill them in gradually.
     */
    private static function _update_cwl_extended_account_data( $user ) {
        #$log = static::_get_log();
        #$log->debug("Start Update CWL Extended Accoutn Data");
        global $wgDBprefix;

        # TODO: wfGetDB() deprecated in 1.39, use MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase() in 1.42
        $dbw = wfGetDB( DB_MASTER );
        $table = $wgDBprefix."user_cwl_extended_account_data";

        $cwl_data = static::_get_cwl_data();
        $ucead = static::getUceadByCwlLogin($cwl_data['cwl_login_name']);

        if ($ucead->puid == $cwl_data['puid'] &&
            $ucead->CWLRole == $cwl_data['ubcAffiliation'] &&
            $ucead->CWLNickname == $cwl_data['full_name']) {
            #$log->debug("No Changes");
            // no changes
            return true;
        }

        $updates = array(
            'puid'    => $cwl_data['puid'],
            'CWLRole' => $cwl_data['ubcAffiliation'],
            'CWLNickname' => $cwl_data['full_name'],
        );

        # TODO: use new db API InsertQueryBuilder when we upgrade >= REL1.41
        $res_ad = $dbw->update(
            $table,
            $updates,
            ['CWLLogin' => $cwl_data['cwl_login_name']]
        );
        #$log->debug("Updated with new changes");
        return $res_ad;
    }

    private static function _get_cwl_data(): array {
        $authManager = MediaWikiServices::getInstance()->getAuthManager();
        $cwl_data = $authManager->getAuthenticationSessionData(
            static::CWL_DATA_SESSION_KEY
        );
        $authManager->removeAuthenticationSessionData(
            static::CWL_DATA_SESSION_KEY
        );
        if ( empty( $cwl_data ) ) {
            throw new Exception( 'UBCAUth - Unable to get CWL attribute data' );
        }
        # existing data shows users with multiple affiliations stores them as a
        # space delimited string, so we'll follow that behaviour
        $cwl_data['ubcAffiliation'] = implode(' ', $cwl_data['ubcAffiliation']);
        # TODO: not sure this name sanitization is necessary anymore, keeping
        # it for now to avoid potential issues, but should test later
        $cwl_data['full_name'] = preg_replace( "/[^A-Za-z0-9 ]/", '', $cwl_data['full_name']);
        return $cwl_data;
    }

    // get the logging instance for this extension
    private static function _get_log() {
        return LoggerFactory::getInstance( static::LOGGER_UBC_AUTH );
    }
}

