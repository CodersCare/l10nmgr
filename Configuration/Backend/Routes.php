<?php

declare(strict_types=1);

use Localizationteam\L10nmgr\Controller\LocalizationManager;
use Localizationteam\L10nmgr\Controller\LocalizationModuleController;

return [
    'download_setting' => [
        'path' => '/downloadSetting',
        'target' => LocalizationManager::class . '::downloadSetting',
    ],
    'download_export' => [
        'path' => '/downloadExport',
        'target' => LocalizationModuleController::class . '::downloadExport',
    ],
];
