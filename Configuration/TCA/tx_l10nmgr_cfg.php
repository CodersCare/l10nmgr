<?php

use Localizationteam\L10nmgr\Backend\ItemsProcFuncs\Tablelist;

defined('TYPO3') || die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'ORDER BY title',
        'iconfile' => 'EXT:l10nmgr/Resources/Public/Icons/icon_tx_l10nmgr_cfg.gif',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.title',
            'config' => [
                'type' => 'input',
                'size' => 48,
                'required' => true,
            ],
        ],
        'filenameprefix' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.filenameprefix',
            'config' => [
                'type' => 'input',
                'size' => 48,
                'required' => true,
                'eval' => 'alphanum_x',
            ],
        ],
        'depth' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'onChange' => 'reload',
                'items' => [
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.0',
                        'value' => '0'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.1',
                        'value' => '1'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.2',
                        'value' => '2'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.3',
                        'value' => '3'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.4',
                        'value' => '100'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.-1',
                        'value' => '-1'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.-2',
                        'value' => '-2'
                    ],
                ],
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
        'pages' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.pages',
            'displayCond' => 'FIELD:depth:<=:-2',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 5,
                'maxitems' => 100,
            ],
        ],
        'displaymode' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.0',
                        'value' => '0'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.1',
                        'value' => '1'
                    ],
                    [
                        'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.2',
                        'value' => '2'
                    ],
                ],
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
        'tablelist' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.tablelist',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'size' => 5,
                'autoSizeMax' => 50,
                'maxitems' => 100,
                'itemsProcFunc' => Tablelist::class . '->populateAvailableTables',
            ],
        ],
        'exclude' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.exclude',
            'config' => [
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ],
        ],
        'include' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.include',
            'config' => [
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ],
        ],
        'metadata' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.metadata',
            'config' => [
                'readOnly' => 1,
                'type' => 'text',
                'cols' => 48,
                'rows' => 3,
            ],
        ],
        'forcedSourceLanguage' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.forcedSourceLanguage',
            'config' => [
                'type' => 'language',
            ],
        ],
        'onlyForcedSourceLanguage' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.onlyForcedSourceLanguage',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'incfcewithdefaultlanguage' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.incfcewithdefaultall',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'overrideexistingtranslations' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.overrideexistingtranslations',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'sortexports' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.sortexports',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'applyExcludeToChildren' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.applyExcludeToChildren',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        0 => ['showitem' => 'title,filenameprefix, depth, pages, sourceLangStaticId, --palette--;;forcedSourceLanguageSettings, tablelist, exclude, include, metadata, displaymode, incfcewithdefaultlanguage, overrideexistingtranslations, sortexports, applyExcludeToChildren'],
    ],
    'palettes' => [
        'forcedSourceLanguageSettings' => ['showitem' => 'forcedSourceLanguage, onlyForcedSourceLanguage'],
    ],
];
