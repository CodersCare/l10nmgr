<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Task;

/***************************************************************
 * Copyright notice
 * (c) 2011 Francois Suter <typo3@cobweb.ch>
 * All rights reserved
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use DirectoryIterator;
use Exception;
use RuntimeException;
use SplFileInfo;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * L10N Manager file garbage collection task
 * The L10N Manager creates quite a large number of output files. It is necessary
 * to clean them up regularly, lest they accumulate and clog the file system.
 * Credits: some code taken from task tx_scheduler_RecyclerGarbageCollection by Kai Vogel
 *
 * @author Francois Suter <typo3@cobweb.ch>
 */
class L10nmgrFileGarbageCollection extends AbstractTask
{
    protected static array $targetDirectories = [
        'uploads/tx_l10nmgr/saved_files',
        'uploads/tx_l10nmgr/jobs/out',
        'uploads/tx_l10nmgr/jobs/in',
    ];

    public int $age = 30;

    public string $excludePattern = '(index\.html|\.htaccess)';

    /**
     * Removes old files, called by the Scheduler.
     *
     * @return bool TRUE if task run was successful
     * @throws Exception
     */
    public function execute(): bool
    {
        // There is no file ctime on windows, so this task disables itself if OS = win
        if (Environment::isWindows()) {
            throw new Exception('This task is not reliable on Windows OS', 1323272367);
        }
        // Calculate a reference timestamp, based on age of files to delete
        $seconds = (60 * 60 * 24 * $this->age);
        $timestamp = ((GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp') ?? 0) - $seconds);
        // Loop on all target directories
        $globalResult = true;
        foreach (self::$targetDirectories as $directory) {
            $result = $this->cleanUpDirectory($directory, $timestamp);
            $globalResult &= $result;
        }
        // Return the global result, which is a success only if all directories could be cleaned up without problem
        return (bool)$globalResult;
    }

    /**
     * Gets a list of all files in a directory recursively and removes
     * old ones.
     *
     * @param string $directory Path to the directory
     * @param int $timestamp Timestamp of the last file modification
     *
     * @return bool TRUE if success
     * @throws RuntimeException If folders are not found or files can not be deleted
     */
    protected function cleanUpDirectory(string $directory, int $timestamp): bool
    {
        $fullPathToDirectory = GeneralUtility::getFileAbsFileName($directory);
        // Check if given directory exists
        if (!(@is_dir($fullPathToDirectory))) {
            throw new RuntimeException('Given directory "' . $fullPathToDirectory . '" does not exist', 1323272107);
        }
        // Find all files in the directory
        $directoryContent = new DirectoryIterator($fullPathToDirectory);
        /** @var SplFileInfo $fileObject */
        $fileObject = null;
        foreach ($directoryContent as $fileObject) {
            // Remove files that are older than given timestamp and don't match the exclude pattern
            if ($fileObject->isFile()
                && !preg_match(
                    '/' . $this->excludePattern . '/i',
                    $fileObject->getFilename()
                ) && $fileObject->getCTime() < $timestamp
            ) {
                if (!(@unlink($fileObject->getRealPath()))) {
                    throw new RuntimeException(
                        'Could not remove file "' . $fileObject->getRealPath() . '"',
                        1323272115
                    );
                }
            }
        }
        return true;
    }
}
