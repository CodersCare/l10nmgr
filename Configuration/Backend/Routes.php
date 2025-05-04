<?php

declare(strict_types=1);

use Localizationteam\L10nmgr\Controller\LocalizationModuleController;

return [
    'download_setting' => [
        'path' => '/downloadSetting',
        'target' => LocalizationModuleController::class . '::downloadSetting',
    ],
];
