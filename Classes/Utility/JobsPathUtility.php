<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Utility;

use InvalidArgumentException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class JobsPathUtility
{
    /**
     * Retrieve the base storage path for L10nmgr files.
     * Main logic:
     * 1. Use configurable value if set in Extension Configuration.
     * 2. Use existing legacy path (`uploads/tx_l10nmgr/`) if it exists.
     * 3. Use recommended fallback `var/tx_l10nmgr/` if no other option is viable.
     *
     * @return string Absolute path to the base folder for file storage.
     */
    public static function getBasePath(): string
    {
        $basePath = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'] ?? null;
        if (!empty($basePath) && !is_dir($basePath)) {
            GeneralUtility::mkdir_deep($basePath);
        }
        $basePath = $basePath ?: Environment::getPublicPath() . '/uploads/tx_l10nmgr/';
        if (!is_dir($basePath)) {
            $basePath = Environment::getVarPath() . '/tx_l10nmgr/';
            GeneralUtility::mkdir_deep($basePath);
        }
        if (!is_dir($basePath) || !is_writable($basePath)) {
            throw new InvalidArgumentException("The configured path '$basePath' is not valid.");
        }
        return $basePath;
    }

    /**
     * Resolve a subpath within the base storage directory.
     *
     * @param string $subPath Subdirectory or file path relative to the base path.
     * @return string Absolute path to the resolved directory or file.
     */
    public static function resolvePath(string $subPath): string
    {
        return rtrim(self::getBasePath(), '/') . '/' . ltrim($subPath, '/');
    }
}
