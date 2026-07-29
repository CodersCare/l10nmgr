<?php

use Localizationteam\L10nmgr\Hooks\Tcemain;
use Localizationteam\L10nmgr\LanguageRestriction\LanguageRestrictionRegistry;
use Localizationteam\L10nmgr\Task\L10nmgrAdditionalFieldProvider;
use Localizationteam\L10nmgr\Task\L10nmgrFileGarbageCollection;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addUserTSConfig('
	options.saveDocNew.tx_l10nmgr_cfg=1
	options.saveDocNew.tx_l10nmgr_priorities=1
');

//! increase with every change to XML Format

if (!defined('L10NMGR_FILEVERSION')) {
    define('L10NMGR_FILEVERSION', '2.0');
}
if (!defined('L10NMGR_VERSION')) {
    define('L10NMGR_VERSION', '13.0.0');
}

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_l10nmgr'] = Tcemain::class;

// Add file cleanup task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][L10nmgrFileGarbageCollection::class] = [
    'extension'        => 'l10nmgr',
    'title'            => 'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.name',
    'description'      => 'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.description',
    'additionalFields' => L10nmgrAdditionalFieldProvider::class,
];

ExtensionManagementUtility::addPageTSConfig(
    '@import \'EXT:l10nmgr/Configuration/TSConfig/PageTSConfig.typoscript\''
);

// $GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'] .= ',l10nmgr_configuration,l10nmgr_configuration_next_level';

LanguageRestrictionRegistry::getInstance()->registerField(
    'core',
    'pages'
);
LanguageRestrictionRegistry::getInstance()->registerField(
    'core',
    'tt_content'
);
