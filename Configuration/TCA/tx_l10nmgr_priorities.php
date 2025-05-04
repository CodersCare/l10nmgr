<?php

defined('TYPO3') || die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_priorities',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'rootLevel' => 1,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:l10nmgr/Resources/Public/Icons/icon_tx_l10nmgr_priorities.gif',
    ],
    'feInterface' => [
        'fe_admin_fieldList' => 'hidden, title, description, languages, element',
    ],
    'columns' => [
        'hidden' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => '0',
            ],
        ],
        'title' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_priorities.title',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
        'description' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_priorities.description',
            'config' => [
                'type' => 'text',
                'cols' => 30,
                'rows' => 5,
            ],
        ],
        'languages' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_priorities.languages',
            'config' => [
                'type' => 'language',
            ],
        ],
        'element' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:tx_l10nmgr_priorities.element',
            'config' => [
                'type' => 'group',
                'allowed' => '*',
                'prepend_tname' => true,
                'size' => 10,
                'minitems' => 0,
                'maxitems' => 100,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'hidden, title, description, languages, element'],
    ],
];
