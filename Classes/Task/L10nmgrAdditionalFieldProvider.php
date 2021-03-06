<?php

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
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface;
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
class L10nmgrAdditionalFieldProvider extends AbstractAdditionalFieldProvider implements AdditionalFieldProviderInterface
{
    use BackendUserTrait;

    /**
     * @var LanguageService
     */
    protected $languageService;

    /**
     * @var int Default age
     */
    protected $defaultAge = 30;

    /**
     * @var string Default pattern of files to exclude from cleanup
     */
    protected $defaultPattern = '(index\.html|\.htaccess)';

    /**
     * Add an integer input field for age of fiels to delete
     *
     * @param array $taskInfo Reference to the array containing the info used in the add/edit form
     * @param object $task When editing, reference to the current task object. Null when adding.
     * @param SchedulerModuleController $parentObject Reference to the calling object (Scheduler's BE module)
     *
     * @return array Array containing all the information pertaining to the additional fields
     */
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $parentObject)
    {
        // Initialize selected fields
        if (!isset($taskInfo['l10nmgr_fileGarbageCollection_age'])) {
            $taskInfo['l10nmgr_fileGarbageCollection_age'] = $this->defaultAge;
            if ($parentObject->getCurrentAction() === 'edit') {
                $taskInfo['l10nmgr_fileGarbageCollection_age'] = $task->age;
            }
        }
        if (!isset($taskInfo['l10nmgr_fileGarbageCollection_excludePattern'])) {
            $taskInfo['l10nmgr_fileGarbageCollection_excludePattern'] = $this->defaultPattern;
            if ($parentObject->getCurrentAction() === 'edit') {
                $taskInfo['l10nmgr_fileGarbageCollection_excludePattern'] = $task->excludePattern;
            }
        }
        // Add field for file age
        $fieldName = 'tx_scheduler[l10nmgr_fileGarbageCollection_age]';
        $fieldId = 'task_fileGarbageCollection_age';
        $fieldValue = (int)$taskInfo['l10nmgr_fileGarbageCollection_age'];
        $fieldHtml = '<input type="text" name="' . $fieldName . '" id="' . $fieldId . '" value="' . htmlspecialchars($fieldValue) . '" size="10" />';
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
        $fieldHtml = '<input type="text" name="' . $fieldName . '" id="' . $fieldId . '" value="' . htmlspecialchars($fieldValue) . '" size="30" />';
        $additionalFields[$fieldId] = [
            'code' => $fieldHtml,
            'label' => 'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.excludePattern',
            'cshKey' => '_tasks_txl10nmgr',
            'cshLabel' => $fieldId,
        ];
        return $additionalFields;
    }

    /**
     * Checks if the given value is an integer
     *
     * @param array $submittedData Reference to the array containing the data submitted by the user
     * @param SchedulerModuleController $parentObject Reference to the calling object (Scheduler's BE module)
     *
     * @return bool TRUE if validation was ok (or selected class is not relevant), FALSE otherwise
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $parentObject)
    {
        $result = true;
        // Check if number of days is indeed a number and greater than 0
        // If not, fail validation and issue error message
        if (!is_numeric($submittedData['l10nmgr_fileGarbageCollection_age']) || (int)$submittedData['l10nmgr_fileGarbageCollection_age'] <= 0) {
            $result = false;
            $this->addMessage(
                $this->getLanguageService()->sL(
                    'LLL:EXT:l10nmgr/Resources/Private/Language/Task/locallang.xlf:fileGarbageCollection.invalidAge'
                ),
                FlashMessage::ERROR
            );
        }
        return $result;
    }

    /**
     * getter/setter for LanguageService object
     *
     * @return LanguageService $languageService
     */
    protected function getLanguageService()
    {
        if (!$this->languageService instanceof LanguageService) {
            $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
        }
        if ($this->getBackendUser()) {
            $this->languageService->init($this->getBackendUser()->uc['lang']);
        }
        return $this->languageService;
    }

    /**
     * Saves given integer value in task object
     *
     * @param array $submittedData Contains data submitted by the user
     *
     * @param L10nmgrFileGarbageCollection|AbstractTask $task
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task)
    {
        /** @phpstan-ignore-next-line */
        $task->age = (int)$submittedData['l10nmgr_fileGarbageCollection_age'];
    }
}
