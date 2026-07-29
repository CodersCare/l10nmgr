<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Utility;

use InvalidArgumentException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class JobsPathUtility
{
    /**
     * Resolve a subpath within the base storage directory.
     *
     * @param string $subPath Subdirectory or file path relative to the base path.
     * @return string Absolute path to the resolved directory or file.
     */
    public static function resolvePath(string $subPath): string
    {
        $configuredRelativePath = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'] ?? null;

        if (!empty($configuredRelativePath)) {
            $basePath = Environment::getPublicPath() . '/' . ltrim($configuredRelativePath, '/');
        } elseif (is_dir(Environment::getPublicPath() . '/uploads/tx_l10nmgr/')) {
            $basePath = Environment::getPublicPath() . '/uploads/tx_l10nmgr/';
        } else {
            $basePath = Environment::getVarPath() . '/tx_l10nmgr/';
        }

        if (!is_dir($basePath)) {
            GeneralUtility::mkdir_deep($basePath);
        }

        if (!is_writable($basePath)) {
            throw new InvalidArgumentException("Export path '$basePath' is not writable.");
        }

        return rtrim($basePath, '/') . '/' . ltrim($subPath, '/');
    }
}
