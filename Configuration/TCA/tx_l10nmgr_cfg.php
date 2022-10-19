<?php

$l10n = 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf';
if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('static_info_tables')) {
    return [
        'ctrl' => [
            'title' => $l10n . ':tx_l10nmgr_cfg',
            'label' => 'title',
            'tstamp' => 'tstamp',
            'crdate' => 'crdate',
            'cruser_id' => 'cruser_id',
            'default_sortby' => 'ORDER BY title',
            'iconfile' => 'EXT:l10nmgr/Resources/Public/Icons/icon_tx_l10nmgr_cfg.gif',
        ],
        'feInterface' => [
            'fe_admin_fieldList' => 'title, depth, tablelist, exclude',
        ],
        'columns' => [
            'title' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.title',
                'config' => [
                    'type' => 'input',
                    'size' => 48,
                    'eval' => 'required',
                ],
            ],
            'filenameprefix' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.filenameprefix',
                'config' => [
                    'type' => 'input',
                    'size' => 48,
                    'eval' => 'required',
                ],
            ],
            'depth' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.depth',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'onChange' => 'reload',
                    'items' => [
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.0', '0'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.1', '1'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.2', '2'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.3', '3'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.4', '100'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.-1', '-1'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.-2', '-2'],
                    ],
                    'size' => 1,
                    'maxitems' => 1,
                ],
            ],
            'pages' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.pages',
                'displayCond' => 'FIELD:depth:<=:-2',
                'config' => [
                    'type' => 'group',
                    'internal_type' => 'db',
                    'allowed' => 'pages',
                    'size' => 5,
                    'maxitems' => 100,
                ],
            ],
            'displaymode' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.displaymode',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => [
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.0', '0'],
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.1', '1'],
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.2', '2'],
                    ],
                    'size' => 1,
                    'maxitems' => 1,
                ],
            ],
            'tablelist' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.tablelist',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectMultipleSideBySide',
                    'special' => 'tables',
                    'size' => 5,
                    'autoSizeMax' => 50,
                    'maxitems' => 100,
                    'itemsProcFunc' => 'Localizationteam\L10nmgr\Backend\ItemsProcFuncs\Tablelist->itemsProcFunc',
                ],
            ],
            'exclude' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.exclude',
                'config' => [
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'include' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.include',
                'config' => [
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'metadata' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.metadata',
                'config' => [
                    'readOnly' => 1,
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'sourceLangStaticId' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.sourceLang',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => [
                        ['', 0],
                    ],
                    'foreign_table' => 'static_languages',
                    'foreign_table_where' => 'AND static_languages.pid=0 ORDER BY static_languages.lg_name_en',
                    'size' => 1,
                    'minitems' => 0,
                    'maxitems' => 1,
                ],
            ],
            'forcedSourceLanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.forcedSourceLanguage',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => [
                        ['', 0],
                    ],
                    'foreign_table' => 'sys_language',
                    'size' => 1,
                    'minitems' => 0,
                    'maxitems' => 1,
                ],
            ],
            'onlyForcedSourceLanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.onlyForcedSourceLanguage',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'targetLanguages' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.targetLanguages',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectMultipleSideBySide',
                    'foreign_table' => 'sys_language',
                    'size' => 3,
                    'minitems' => 0,
                ],
            ],
            'incfcewithdefaultlanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.incfcewithdefaultall',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'pretranslatecontent' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.pretranslatecontent',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'overrideexistingtranslations' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.overrideexistingtranslations',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'sortexports' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.sortexports',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'applyExcludeToChildren' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.applyExcludeToChildren',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
        ],
        'types' => [
            0 => ['showitem' => 'title,filenameprefix, depth, pages, sourceLangStaticId, --palette--;;forcedSourceLanguageSettings, targetLanguages, tablelist, exclude, include, metadata, displaymode, incfcewithdefaultlanguage, pretranslatecontent, overrideexistingtranslations, sortexports, applyExcludeToChildren'],
        ],
        'palettes' => [
            '1' => ['showitem' => ''],
            'forcedSourceLanguageSettings' => ['showitem' => 'forcedSourceLanguage, onlyForcedSourceLanguage'],
        ],
    ];
}
    return [
        'ctrl' => [
            'title' => $l10n . ':tx_l10nmgr_cfg',
            'label' => 'title',
            'tstamp' => 'tstamp',
            'crdate' => 'crdate',
            'cruser_id' => 'cruser_id',
            'default_sortby' => 'ORDER BY title',
            'iconfile' => 'EXT:l10nmgr/Resources/Public/Icons/icon_tx_l10nmgr_cfg.gif',
        ],
        'feInterface' => [
            'fe_admin_fieldList' => 'title, depth, tablelist, exclude',
        ],
        'interface' => [
            'showRecordFieldList' => 'title,depth,pages,tablelist,exclude,incfcewithdefaultlanguage,pretranslatecontent,overrideexistingtranslations,sortexports',
        ],
        'columns' => [
            'title' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.title',
                'config' => [
                    'type' => 'input',
                    'size' => 48,
                    'eval' => 'required',
                ],
            ],
            'filenameprefix' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.filenameprefix',
                'config' => [
                    'type' => 'input',
                    'size' => 48,
                    'eval' => 'required',
                ],
            ],
            'depth' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.depth',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'onChange' => 'reload',
                    'items' => [
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.0', '0'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.1', '1'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.2', '2'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.3', '3'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.4', '100'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.-1', '-1'],
                        [$l10n . ':tx_l10nmgr_cfg.depth.I.-2', '-2'],
                    ],
                    'size' => 1,
                    'maxitems' => 1,
                ],
            ],
            'pages' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.pages',
                'displayCond' => 'FIELD:depth:<=:-2',
                'config' => [
                    'type' => 'group',
                    'internal_type' => 'db',
                    'allowed' => 'pages',
                    'size' => 5,
                    'maxitems' => 100,
                ],
            ],
            'displaymode' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.displaymode',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => [
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.0', '0'],
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.1', '1'],
                        [$l10n . ':tx_l10nmgr_cfg.displaymode.I.2', '2'],
                    ],
                    'size' => 1,
                    'maxitems' => 1,
                ],
            ],
            'tablelist' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.tablelist',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectMultipleSideBySide',
                    'special' => 'tables',
                    'size' => 5,
                    'autoSizeMax' => 50,
                    'maxitems' => 100,
                    'itemsProcFunc' => 'Localizationteam\L10nmgr\Backend\ItemsProcFuncs\Tablelist->itemsProcFunc',
                ],
            ],
            'exclude' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.exclude',
                'config' => [
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'include' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.include',
                'config' => [
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'metadata' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.metadata',
                'config' => [
                    'readOnly' => 1,
                    'type' => 'text',
                    'cols' => 48,
                    'rows' => 3,
                ],
            ],
            'forcedSourceLanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.forcedSourceLanguage',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectSingle',
                    'items' => [
                        ['', 0],
                    ],
                    'foreign_table' => 'sys_language',
                    'size' => 1,
                    'minitems' => 0,
                    'maxitems' => 1,
                ],
            ],
            'onlyForcedSourceLanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.onlyForcedSourceLanguage',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'targetLanguages' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.targetLanguages',
                'config' => [
                    'type' => 'select',
                    'renderType' => 'selectMultipleSideBySide',
                    'foreign_table' => 'sys_language',
                    'size' => 3,
                    'minitems' => 0,
                ],
            ],
            'incfcewithdefaultlanguage' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.incfcewithdefaultall',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'pretranslatecontent' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.pretranslatecontent',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'overrideexistingtranslations' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.overrideexistingtranslations',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'sortexports' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.sortexports',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
            'applyExcludeToChildren' => [
                'exclude' => 1,
                'label' => $l10n . ':tx_l10nmgr_cfg.applyExcludeToChildren',
                'config' => [
                    'type' => 'check',
                    'default' => 0,
                ],
            ],
        ],
        'types' => [
            0 => ['showitem' => 'title,filenameprefix, depth, pages, --palette--;;forcedSourceLanguageSettings, targetLanguages, tablelist, exclude, include, metadata, displaymode, incfcewithdefaultlanguage, pretranslatecontent, overrideexistingtranslations, sortexports, applyExcludeToChildren'],
        ],
        'palettes' => [
            '1' => ['showitem' => ''],
            'forcedSourceLanguageSettings' => ['showitem' => 'forcedSourceLanguage, onlyForcedSourceLanguage'],
        ],
    ];
