<?php

defined('TYPO3') || die();

use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\LanguageRestriction\LanguageRestrictionRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTCAcolumns('pages', [
    'l10nmgr_configuration' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_DEFAULT,
                    1 => Constants::L10NMGR_CONFIGURATION_DEFAULT,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_NONE,
                    1 => Constants::L10NMGR_CONFIGURATION_NONE,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_EXCLUDE,
                    1 => Constants::L10NMGR_CONFIGURATION_EXCLUDE,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_INCLUDE,
                    1 => Constants::L10NMGR_CONFIGURATION_INCLUDE,
                ],
            ],
            'default' => Constants::L10NMGR_CONFIGURATION_DEFAULT,
        ],
    ],
    'l10nmgr_configuration_next_level' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration_next_level',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_DEFAULT,
                    1 => Constants::L10NMGR_CONFIGURATION_DEFAULT,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_NONE,
                    1 => Constants::L10NMGR_CONFIGURATION_NONE,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_EXCLUDE,
                    1 => Constants::L10NMGR_CONFIGURATION_EXCLUDE,
                ],
                [
                    0 => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.l10nmgr_configuration.I.' . Constants::L10NMGR_CONFIGURATION_INCLUDE,
                    1 => Constants::L10NMGR_CONFIGURATION_INCLUDE,
                ],
            ],
            'default' => Constants::L10NMGR_CONFIGURATION_DEFAULT,
        ],
    ],
    'l10nmgr_language_restriction' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:sys_language.restrictions',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'itemsProcFunc' => LanguageRestrictionRegistry::class . '->populateAvailableSiteLanguages',
            'maxitems' => 9999,
        ],
    ],
]);

ExtensionManagementUtility::addFieldsToPalette(
    'pages',
    'l10nmgr_configuration',
    'l10nmgr_configuration,l10nmgr_configuration_next_level'
);
ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--palette--;LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:pages.palettes.l10nmgr_configuration;l10nmgr_configuration',
    '',
    'after:l18n_cfg'
);

ExtensionManagementUtility::addToAllTCAtypes('pages', 'l10nmgr_language_restriction', '', 'after:l18n_cfg');
