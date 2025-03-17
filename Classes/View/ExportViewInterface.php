<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\View;

use Doctrine\DBAL\Exception as DBALException;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;

/***************************************************************
 * Copyright notice
 * (c) 2018 B13
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
interface ExportViewInterface
{
    public function setForcedSourceLanguage(int $forceLanguage): void;
    public function setOnlyForcedSourceLanguage(): void;
    public function setModeOnlyChanged(): void;
    public function setModeNoHidden(): void;

    /**
     * Saves the information of the export in the database table 'tx_l10nmgr_sava_data'
     *
     * @throws Exception
     */
    public function saveExportInformation(): bool;

    /**
     * Render the simple XML export
     *
     * @throws SiteNotFoundException
     */
    public function render(): string;

    /**
     * Checks if an export exists
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function checkExports(): bool;

    /**
     * Renders a list of saved exports as text.
     *
     * @throws DBALException
     */
    public function renderExportsCli(): string;

    /**
     * Get filename
     *
     * @throws Exception
     */
    public function getFileName(): string;
}
