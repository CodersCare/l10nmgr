<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use Localizationteam\L10nmgr\Traits\LanguageServiceTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Used in "BaseScriptClass" from TYPO3 Core
 */
class BaseModule12
{
    use BackendUserTrait;
    use LanguageServiceTrait;

    /**
     * Loaded with the global array $MCONF which holds some module configuration from the conf.php file of backend modules.
     *
     * @see init()
     */
    public array $MCONF = [];

    /**
     * The integer value of the GET/POST var, 'id'. Used for submodules to the 'Web' module (configuration id)
     *
     * @see init()
     */
    public int $id;

    /**
     * The integer value of the GET/POST var, 'srcPID'. Used for submodules to the 'Web' module (page id)
     *
     * @see init()
     */
    public int $srcPID;

    /**
     * The module menu items array. Each key represents a key for which values can range between the items in the array of that key.
     *
     * @see init()
     */
    public array $MOD_MENU = [
        'function' => [],
    ];

    /**
     * Current settings for the keys of the MOD_MENU array
     *
     * @see $MOD_MENU
     */
    public array $MOD_SETTINGS = [];

    /**
     * Module TSconfig based on PAGE TSconfig / USER TSconfig
     *
     * @see menuConfig()
     */
    public array $modTSconfig;

    /**
     * If type is 'ses' then the data is stored as session-lasting data. This means that it'll be wiped out the next time the user logs in.
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     */
    public string $modMenu_type = '';

    /**
     * dontValidateList can be used to list variables that should not be checked if their value is found in the MOD_MENU array. Used for dynamically generated menus.
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     */
    public string $modMenu_dontValidateList = '';

    /**
     * List of default values from $MOD_MENU to set in the output array (only if the values from MOD_MENU are not arrays)
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     */
    public string $modMenu_setDefaultList = '';

    /**
     * Generally used for accumulating the output content of backend modules
     */
    public string $content = '';

    /**
     * May contain an instance of a 'Function menu module' which connects to this backend module.
     *
     * @see checkExtObj()
     */
    public object $extObj;

    /**
     * Initializes the backend module by setting internal variables, initializing the menu.
     *
     * @see menuConfig()
     */
    public function init(): void
    {
        $this->extObj = (object)[];
        // Name might be set from outside
        if (empty($this->MCONF['name'])) {
            $this->MCONF = $GLOBALS['MCONF'] ?? [];
        }
        // @extensionScannerIgnoreLine
        $this->id = (int)GeneralUtility::_GP('id');
        $this->menuConfig();
    }

    /**
     * Initializes the internal MOD_MENU array setting and unsetting items based on various conditions.
     * Then MOD_SETTINGS array is cleaned up (see \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()) so it contains only valid values. It's also updated with any SET[] values submitted.
     * Also loads the modTSconfig internal variable.
     *
     * @see init(), $MOD_MENU, $MOD_SETTINGS, \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     */
    public function menuConfig(): void
    {
        $this->MOD_SETTINGS = BackendUtility::getModuleData(
            $this->MOD_MENU,
            GeneralUtility::_GP('SET'),
            $this->MCONF['name'],
            $this->modMenu_type,
            $this->modMenu_dontValidateList,
            $this->modMenu_setDefaultList
        );
        foreach ($this->MOD_MENU as $key => $var) {
            if (is_array($var) && !array_key_exists($key, $this->MOD_SETTINGS)) {
                $this->MOD_SETTINGS[$key] = (string)key($var);
            }
        }
    }
}
