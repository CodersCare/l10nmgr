<?php

defined('TYPO3') || die();

use Localizationteam\L10nmgr\Utility\L10nmgrExtensionManagementUtility;
L10nmgrExtensionManagementUtility::makeTranslationsRestrictable(
    'core',
    'tt_content'
);
