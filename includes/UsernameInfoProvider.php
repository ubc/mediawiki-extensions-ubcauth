<?php
namespace MediaWiki\Extension\UBCAuth;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\SimpleSAMLphp\UserInfoProvider\Username;
use MediaWiki\Extension\UBCAuth\Hooks;

use Exception;
use User;

class UsernameInfoProvider extends Username {
    private $attrs;
    private $conf;

    public function getValue($attrs, $conf): string {
        $this->attrs = $attrs;
        $this->conf = $conf;

        # we expect usernameAttribute to be configured as the CWL login name key
        $cwlLogin = $this->getStringAttr('usernameAttribute', true);
        # see if this CWL login already has an existing account
        # TODO: getConnectionProvider() is only available after REL1.42
        #$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $row = $dbr->newSelectQueryBuilder()
                   ->select( [ 'u.user_name', 'ucead.CWLLogin' ] )
                   ->from( 'user_cwl_extended_account_data', 'ucead' )
                   ->join( 'user', 'u', 'ucead.user_id=u.user_id' )
                   ->where( [ 'ucead.CWLLogin' => $cwlLogin ] )
                   ->caller( __METHOD__ )->fetchRow();
        if ($row) {
            # return existing user
            return $row->user_name;
        }

        # no existing user, so we have to generate a username
        $wiki_username = $this->getNewWikiUsername();

        # Store relevant attributes so we can save them to the CWL extended
        # account data table later (in Hooks.php) when the user is actually
        # created.
        $cwl_data = [];
        $cwl_data['puid'] = $this->getStringAttr('puidAttribute');
        $cwl_data['cwl_login_name'] = $this->getStringAttr('usernameAttribute');
        $cwl_data['ubcAffiliation'] = $this->getArrayAttr('eduPersonAffiliationAttribute');
        $cwl_data['full_name'] = $this->getStringAttr('realNameAttribute');
        $cwl_data['wiki_username'] = $wiki_username;
        $authManager = MediaWikiServices::getInstance()->getAuthManager();
        $authManager->setAuthenticationSessionData(
            Hooks::CWL_DATA_SESSION_KEY,
            $cwl_data
        );

        return $wiki_username;
    }

    private function getNewWikiUsername() {
        // similar logic as existing CASAuth
        $real_name = $this->getStringAttr('realNameAttribute');
        $cwl_login = $this->getStringAttr('usernameAttribute');
        $uc_real_name = ucfirst( preg_replace( "/[^A-Za-z0-9]/", '', $real_name ) );
        $uc_cwl_login_name = ucfirst( preg_replace( "/[^A-Za-z0-9]/", '', $cwl_login ) );
        $real_name = $this->getStringAttr('realNameAttribute');
        $cwl_login = $this->getStringAttr('usernameAttribute');


        $username_base = $uc_real_name;
        if ( empty( $username_base ) ) {
            // use cwl login if name is empty
            if ( empty( $uc_cwl_login_name ) ) {
                throw new Exception( 'UBCAuth - Failed to generate login name, CWL fallback missing' );
            }
            $username_base = $uc_cwl_login_name;
        }

        $num = 0;
        $username = $username_base;
        // TODO not the best way to generate unique username. possible race condition
        while ( $this->isExistingUser( $username ) ) {
            if ( $num++ > 9999 ) {
                // avoid infinite loop
                if ( !$this->isExistingUser( $uc_cwl_login_name ) ) {
                    return $uc_cwl_login_name;
                }
                throw new Exception( 'UBCAuth - Failed to generate login name' );
            }
            $username = $username_base.".$num";
        }
        return $username;
    }

    private function isExistingUser( $username ) {
        $userId = User::idFromName($username);
        if ($userId) return true;
        return false;
    }

    private function getStringAttr($confKey, $required=false) {
        $val = $this->getAttrByConfKey($confKey, $required);
        if (!$val) return '';
        if (is_array($val)) {
            if (is_string($val[0])) return $val[0];
        }
        if (is_string($val)) return $val;
        throw new Exception("UBCAuth - Could not handle attribute '$confKey' as string");
    }

    private function getArrayAttr($confKey, $required=false) {
        $val = $this->getAttrByConfKey($confKey, $required);
        if (!$val) return [];
        if (is_array($val)) return $val;
        throw new Exception("UBCAuth - Could not handle attribute '$confKey' as array");
    }

    private function getAttrByConfKey($confKey, $required=false) {
        $attrKey = $this->conf->get($confKey);
        if (!$attrKey) {
            throw new Exception("UBCAuth - '$confKey' needs to be set as part of SimpleSAMLphp attribute config");
        }
        $ret = $this->attrs[$attrKey];
        if ($required && !$ret) {
            throw new Exception("UBCAUth - Missing SAML2 attribute '$attrKey'");
        }
        return $ret;
    }
}
