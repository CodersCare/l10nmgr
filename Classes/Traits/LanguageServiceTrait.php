<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Traits;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

trait LanguageServiceTrait
{
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'] ?? GeneralUtility::makeInstance(LanguageService::class);
    }

    protected function translate(string $path): string
    {
        $languageService = $this->getLanguageService();
        return $languageService->sL($path);
    }
}
