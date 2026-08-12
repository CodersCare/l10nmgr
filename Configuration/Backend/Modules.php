<?php

declare(strict_types=1);

/**
 * Definitions for modules provided by EXT:l10nmgr
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ExtensionArchitecture/HowTo/BackendModule/ModuleConfiguration.html
 */

use Localizationteam\L10nmgr\Controller\ConfigurationModuleController;
use Localizationteam\L10nmgr\Controller\LocalizationModuleController;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$lll = 'LLL:EXT:l10nmgr/Resources/Private/Language/';

$navigationComponent = GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 13
    ? '@typo3/backend/tree/page-tree-element'
    : '@typo3/backend/page-tree/page-tree-element';

return [
    'l10nmgr_configuration' => [
        'parent' => 'web',
        'access' => 'user', // user, admin or systemMaintainer
        'path' => '/module/l10nmgr/configuration',
        'iconIdentifier' => 'module-configuration',
        'labels' => $lll . 'Modules/Configuration/locallang_mod.xlf',
        'navigationComponent' => $navigationComponent,
        'routes' => [
            '_default' => [
                'target' => ConfigurationModuleController::class . '::handleRequest',
            ],
            'localize' => [
                'path' => '/localization',
                'target' => LocalizationModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
