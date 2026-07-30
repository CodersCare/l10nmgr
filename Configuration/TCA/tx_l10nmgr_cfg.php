<?php

use Localizationteam\L10nmgr\Backend\ItemsProcFuncs\Tablelist;

defined('TYPO3') || die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
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
                'eval' => 'required',
            ],
        ],
        'filenameprefix' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.filenameprefix',
            'config' => [
                'type' => 'input',
                'size' => 48,
                'eval' => 'required,alphanum_x',
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
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.0', '0'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.1', '1'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.2', '2'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.3', '3'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.4', '100'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.-1', '-1'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.depth.I.-2', '-2'],
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
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.0', '0'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.1', '1'],
                    ['LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_cfg.displaymode.I.2', '2'],
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
        0 => ['showitem' => 'title,filenameprefix, depth, pages, sourceLangStaticId, --palette--;;forcedSourceLanguageSettings, tablelist, exclude, include, metadata, displaymode, incfcewithdefaultlanguage, pretranslatecontent, overrideexistingtranslations, sortexports, applyExcludeToChildren'],
    ],
    'palettes' => [
        'forcedSourceLanguageSettings' => ['showitem' => 'forcedSourceLanguage, onlyForcedSourceLanguage'],
    ],
];
