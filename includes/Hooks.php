<?php

namespace MediaWiki\Extension\UBCAuth;

use Exception;
use DatabaseUpdater;
use MediaWiki\Extension\PluggableAuth\PluggableAuthLogin;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\LDAPNoDomainConfigException as NoDomain;

class Hooks {
    const CWL_DATA_SESSION_KEY = "CWL_DATA";

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

    public static function onSetupAfterCache() {
        // Tell LDAPAuthentication2 extension to use our custom logic for UBC
        global $LDAPAuthentication2UsernameNormalizer;
        $LDAPAuthentication2UsernameNormalizer = 'MediaWiki\\Extension\\UBCAuth\\Hooks::ldapUserToCWL';
    }

    ///////////////////////////////////////////////////////////////////////////////
    // helper functions

    public static function ldapUserToCWL( $ldapUserName ) {
        global $wgDBprefix;
        $wiki_username = '';
        $existing_user_found = false;

        // find the existing wiki account based on ldap username
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->select(
            array('ucead' => $wgDBprefix.'user_cwl_extended_account_data', 'u' => $wgDBprefix.'user'),   // tables
            array('u.user_name'),       // fields
            array('ucead.CWLLogin' => $ldapUserName, 'ucead.account_status' => 1),   // where clause
            __METHOD__,     // caller function name
            array('LIMIT' => 1),      // options. fetch first row only
            array('u' => array('INNER JOIN', array(     // join the tables
                'ucead.user_id = u.user_id'
            )))
        );
        foreach ( $res as $row ) {
            $wiki_username = $row->user_name;
            $existing_user_found = true;
        }
        $res->free();

        if ( $existing_user_found ) {
            return $wiki_username;
        }

        // if no existing wiki account found, create one and link with cwl login

        // since the LDAP info is not passed in here, needed to retrieve again
        $ldapInfo = static::_ldap_retrieve_info( $ldapUserName );
        // create new wiki user and insert record into cwl extended data table
        $wiki_username = static::_generate_new_wiki_username( $ldapInfo );
        $real_name = static::_real_name_from_ldap( $ldapInfo );
        $puid = static::_puid_from_ldap( $ldapInfo );
        $cwl_login_name = static::_cwl_login_from_ldap( $ldapInfo );
        $email = static::_email_from_ldap( $ldapInfo );
        $ubcAffiliation = '';   // TODO still needed? where to get it from LDAP?

        $cwl_student_number = '';
        $cwl_employee_number = '';
        $cwl_edu_person_entitlement = '';
        
        $cwl_student_number = static::_ubcedustudentnumber_from_ldap( $ldapInfo );
        $cwl_employee_number = static::_employeenumber_from_ldap( $ldapInfo );
        $cwl_edu_person_entitlement = static::_edupersonentitlement_from_ldap( $ldapInfo );

        ##TEST
        $cwl_student_number = '8888888888';
        
        $ubcAffiliation = $cwl_edu_person_entitlement ? $cwl_edu_person_entitlement : ($cwl_employee_number ? $cwl_employee_number:($cwl_student_number ? $cwl_student_number:''));
        
        $cwl_data = [];
        $cwl_data['puid'] = $puid;
        $cwl_data['cwl_login_name'] = $cwl_login_name;
        $cwl_data['ubcAffiliation'] = $ubcAffiliation;
        $cwl_data['full_name'] = $real_name;
        $cwl_data['wiki_username'] = $wiki_username;
        $authManager = MediaWikiServices::getInstance()->getAuthManager();
        $authManager->setAuthenticationSessionData(
            static::CWL_DATA_SESSION_KEY,
            $cwl_data
        );

        return $wiki_username;
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

        $dbw = wfGetDB( DB_MASTER );
        $table = $wgDBprefix."user_cwl_extended_account_data";

        $authManager = MediaWikiServices::getInstance()->getAuthManager();
        $cwl_data = $authManager->getAuthenticationSessionData(
            static::CWL_DATA_SESSION_KEY
        );
        if ( empty( $cwl_data ) ) {
            return true;
        }
        if ( $cwl_data['wiki_username'] != $user->getName() ) {
            throw new Exception( 'Problem linking new user with CWL account' );
        }

        $user_id = $user->getId();
        $puid = $cwl_data['puid'];
        $cwl_login_name = $cwl_data['cwl_login_name'];
        $ubcAffiliation = $cwl_data['ubcAffiliation'];
        $full_name = $cwl_data['full_name'];
        $authManager->removeAuthenticationSessionData(
            static::CWL_DATA_SESSION_KEY
        );

        $ubcAffiliation = preg_replace( "/[^A-Za-z0-9 ]/", '', $ubcAffiliation );
        $full_name = preg_replace( "/[^A-Za-z0-9 ]/", '', $full_name );

        $insert_a = array(
            'user_id' => $user_id,
            'puid'    => $puid,
            'ubc_role_id' => '',  // no longer captured doing SSO
            'ubc_dept_id' => '', // no longer captured doing SSO
            'wgDBprefix' => $wgDBprefix,
            'CWLLogin' => $cwl_login_name,
            'CWLRole' => $ubcAffiliation,   // TODO: check if this field is used
            'CWLNickname' => $full_name,
            //'CWLSaltedID' => $CWLSaltedID, // no longer needed using PUID
            'account_status' => 1   //might never be used.
        );

        $res_ad = $dbw->insert( $table, $insert_a );
        return $res_ad;
    }

    private static function _ldap_retrieve_info( $ldapUserName ) {
        global $ubcLDAPDomain;
        $domain = $ubcLDAPDomain;

        $ldapClient = null;
        $ldapInfo = [];
        try {
            $ldapClient = ClientFactory::getInstance()->getForDomain( $domain );
        } catch ( NoDomain $e ) {
            wfDebugLog( 'error', 'LDAP domain unavailable: '.$domain );
            throw new Exception( 'LDAP domain unavailable' );
        }

        // get user info from LDAP
        try {
            $ldapInfo = $ldapClient->getUserInfo( $ldapUserName );
        } catch ( Exception $ex ) {
            throw new Exception( 'Failed to retrieve user info from LDAP' );
        }
        if ( empty( $ldapInfo ) ) {
            throw new Exception( 'No user info found in LDAP' );
        }

        return $ldapInfo;
    }

    private static function _ldap_get_or_empty( $info, $key ) {
        if ( $info && array_key_exists( $key, $info ) ) {
            return $info[$key];
        }
        return '';
    }

    // user information from LDAP
    private static function _cwl_login_from_ldap( $info ) {
        return static::_ldap_get_or_empty($info, 
            getenv( 'LDAP_SEARCH_ATTRS' )? getenv( 'LDAP_SEARCH_ATTRS' ) : 'cn' );
    }
    private static function _real_name_from_ldap( $info ) {
        // based on display name
        return static::_ldap_get_or_empty( $info, 
            getenv( 'LDAP_REALNAME_ATTR' )? getenv( 'LDAP_REALNAME_ATTR' ) : 'displayname' );
    }
    private static function _puid_from_ldap( $info ) {
        return static::_ldap_get_or_empty( $info, 'ubceducwlpuid' );
    }
    private static function _email_from_ldap( $info ) {
        return static::_ldap_get_or_empty( $info,
            getenv( 'LDAP_EMAIL_ATTR' )? getenv( 'LDAP_EMAIL_ATTR' ) : 'mail' );
    }

    private static function _edupersonentitlement_from_ldap( $info ) {
        return static::_ldap_get_or_empty( $info, 'edupersonentitlement' );
    }
    private static function _employeenumber_from_ldap( $info ) {
        return static::_ldap_get_or_empty( $info, 'employeenumber' );
    }
    private static function _ubcedustudentnumber_from_ldap( $info ) {
        return static::_ldap_get_or_empty( $info, 'ubcedustudentnumber' );
    }

    // check if given wiki username exist
    private static function _wiki_user_exist( $username ) {
        global $wgDBprefix;

        $found = false;
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->select(
            array( 'u' => $wgDBprefix.'user' ),   // tables
            array( 'u.user_name' ),       // fields
            array( 'u.user_name' => $username ),   // where clause
            __METHOD__,     // caller function name
            array( 'LIMIT' => 1 )      // options. fetch first row only
        );
        foreach ( $res as $row ) {
            $found = true;
        }
        $res->free();
        return $found;
    }

    // generate a new and unique wiki user name based on LDAP data
    private static function _generate_new_wiki_username( $info ) {
        // similar logic as existing CASAuth
        $real_name = static::_real_name_from_ldap( $info );
        $cwl_login = static::_cwl_login_from_ldap( $info );
        $uc_real_name = ucfirst( preg_replace( "/[^A-Za-z0-9]/", '', $real_name ) );
        $uc_cwl_login_name = ucfirst( preg_replace( "/[^A-Za-z0-9]/", '', $cwl_login ) );

        $username_base = $uc_real_name;
        if ( empty( $username_base ) ) {
            // use cwl login if name is empty
            if ( empty( $uc_cwl_login_name ) ) {
                throw new Exception( 'Failed to generate login name' );
            }
            $username_base = $uc_cwl_login_name;
        }

        $num = 0;
        $username = $username_base;
        // TODO not the best way to generate unique username. possible race condition
        while ( static::_wiki_user_exist( $username ) ) {
            if ( $num++ > 9999 ) {
                // avoid infinite loop
                if ( !static::_wiki_user_exist( $uc_cwl_login_name ) ) {
                    return $uc_cwl_login_name;
                }
                throw new Exception( 'Failed to generate login name' );
            }
            $username = $username_base.$num;
        }
        return $username;
    }
}

