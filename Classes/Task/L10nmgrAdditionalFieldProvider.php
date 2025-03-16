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

use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use Localizationteam\L10nmgr\Traits\LanguageServiceTrait;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Additional BE fields for file garbage collection task.
 * Adds a field to choose the age of files that should be deleted
 * and regexp pattern to exclude files from clean up
 * Credits: most of the code taken from task tx_scheduler_RecyclerGarbageCollection_AdditionalFieldProvider by Kai Vogel
 *
 * @author 2011 Francois Suter <typo3@cobweb.ch>
 */
class L10nmgrAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    use BackendUserTrait;
    use LanguageServiceTrait;

    protected int $defaultAge = 30;

    protected string $defaultPattern = '(index\.html|\.htaccess)';

    /**
     * Gets additional fields to render in the form to add/edit a task
     *
     * @param array $taskInfo Values of the fields from the add/edit task form
     * @param mixed $task The task object being edited. Null when adding a task!
     * @param SchedulerModuleController $parentObject Reference to the scheduler backend module
     *
     * @return array A two dimensional array: array('fieldId' => array('code' => '', 'label' => '', 'cshKey' => '', 'cshLabel' => ''))
     */
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $parentObject): array
    {
        // Initialize selected fields
        if (!isset($taskInfo['l10nmgr_fileGarbageCollection_age'])) {
            $taskInfo['l10nmgr_fileGarbageCollection_age'] = $this->defaultAge;
            if ($parentObject->getCurrentAction() == 'edit') {
                $taskInfo['l10nmgr_fileGarbageCollection_age'] = $task->age;
            }
        }
        if (!isset($taskInfo['l10nmgr_fileGarbageCollection_excludePattern'])) {
            $taskInfo['l10nmgr_fileGarbageCollection_excludePattern'] = $this->defaultPattern;
            if ($parentObject->getCurrentAction() == 'edit') {
                $taskInfo['l10nmgr_fileGarbageCollection_excludePattern'] = $task->excludePattern;
            }
        }
        // Add field for file age
        $fieldName = 'tx_scheduler[l10nmgr_fileGarbageCollection_age]';
        $fieldId = 'task_fileGarbageCollection_age';
        $fieldValue = $taskInfo['l10nmgr_fileGarbageCollection_age'];
        $fieldHtml = '<input type="text" name="' . $fieldName . '" id="' . $fieldId . '" value="' . htmlspecialchars((string)$fieldValue) . '" size="10" />';
        $additionalFields[$fieldId] = [
            'code' => $fieldHtml,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.age',
            'cshKey' => '_tasks_txl10nmgr',
            'cshLabel' => $fieldId,
        ];
        // Add field with pattern for excluding files
        $fieldName = 'tx_scheduler[l10nmgr_fileGarbageCollection_excludePattern]';
        $fieldId = 'task_fileGarbageCollection_excludePattern';
        $fieldValue = $taskInfo['l10nmgr_fileGarbageCollection_excludePattern'];
        $fieldHtml = '<input type="text" name="' . $fieldName . '" id="' . $fieldId . '" value="' . htmlspecialchars((string)$fieldValue) . '" size="30" />';
        $additionalFields[$fieldId] = [
            'code' => $fieldHtml,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.excludePattern',
            'cshKey' => '_tasks_txl10nmgr',
            'cshLabel' => $fieldId,
        ];
        return $additionalFields;
    }

    /**
     * Validates the additional fields' values
     *
     * @param array $submittedData An array containing the data submitted by the add/edit task form
     * @param SchedulerModuleController $parentObject Reference to the scheduler backend module
     *
     * @return bool TRUE if validation was ok (or selected class is not relevant), FALSE otherwise
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $parentObject): bool
    {
        $result = true;
        // Check if number of days is indeed a number and greater than 0
        // If not, fail validation and issue error message
        if (isset($submittedData['l10nmgr_fileGarbageCollection_age'])
            && (
                !is_numeric($submittedData['l10nmgr_fileGarbageCollection_age'])
                || (int)$submittedData['l10nmgr_fileGarbageCollection_age'] <= 0
            )
        ) {
            $result = false;
            // @extensionScannerIgnoreLine
            $this->addMessage(
                $this->getLanguageService()->sL(
                    'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.invalidAge'
                ),
                AbstractMessage::ERROR
            );
        }
        return $result;
    }

    /**
     * Saves given integer value in task object
     *
     * @param array $submittedData Contains data submitted by the user
     *
     * @param AbstractTask $task
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        /** @phpstan-ignore-next-line */
        $task->age = (int)($submittedData['l10nmgr_fileGarbageCollection_age'] ?? 0);
    }
}
