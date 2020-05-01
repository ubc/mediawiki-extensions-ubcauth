<?php

namespace MediaWiki\Extension\UBCAuth;

use DatabaseUpdater;

class Hooks {
    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
        $updater->addExtensionTable( 'user_cwl_extended_account_data', dirname( __DIR__ ) . '/sql/add_table.sql' );
    }
}
