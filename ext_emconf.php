<?php

/** @noinspection PhpUndefinedVariableInspection */

/***************************************************************
 * Extension Manager/Repository config file for ext "l10nmgr".
 * Auto generated 10-03-2015 18:54
 * Manual updates:
 * Only the data in the array - everything else is removed by next
 * writing. "version" and "dependencies" must not be touched!
 ***************************************************************/
$EM_CONF[$_EXTKEY] = [
    'title'            => 'Localization Manager',
    'description'      => 'Bulk translation export and import for TYPO3 - CAT XML and MS Excel workflows, CLI automation with FTP/email delivery, fine-grained table and field selection. The established localization manager for professional TYPO3 translation projects. v12+v13 (TYPO3 11-13): free in TER. v14: Priority Access.',
    'category'         => 'module',
    'version'          => '13.0.0',
    'state'            => 'stable',
    'author'           => 'Kasper Skaarhoej, Daniel Zielinski, Daniel Poetzinger, Fabian Seltmann, Andreas Otto, Jo Hasenau, Peter Russ, Stefano Kowalke',
    'author_email'     => 'kasperYYYY@typo3.com, info@loctimize.com, , , , info@cybercraft.de, pruss@uon.li, info@arroba-it.de',
    'author_company'   => 'Localization Manager Team',
    'constraints'      => [
        'depends'   => [
            'typo3'              => '12.0.0-13.4.99',
            'scheduler'          => '12.0.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
