<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Backend\ItemsProcFuncs;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the  GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class/Function which manipulates the item-array for table/field tx_l10nmgr_cfg tablelist.
 *
 * @author Jo Hasenau <info@cybercraft.de>
 */
class Tablelist implements SingletonInterface
{
    public function __construct(readonly TcaItemsProcessorFunctions $tcaItemsProcessor) {}

    /**
     * ItemProcFunc for colpos items
     *
     * @param array $params The array of parameters that is used to render the item list
     */
    public function populateAvailableTables(array &$params)
    {
        $this->tcaItemsProcessor->populateAvailableTables($params);

        $items = [];
        foreach ($params['items'] as $item) {
            if (empty($item['value'])) {
                continue;
            }

            $tableName = $item['value'];
            if (isset($GLOBALS['TCA'][$tableName]['ctrl']['languageField']) && !empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
                $items[] = $item;
            }
        }

        $params['items'] = $items;
    }
}
