<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\LanguageRestriction;

use InvalidArgumentException;
use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\LanguagesService;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use RuntimeException;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class LanguageRestrictionRegistry implements SingletonInterface
{
    use BackendUserTrait;

    protected array $registry = [];

    protected array $extensions = [];

    protected string $template = '';

    public function __construct(protected readonly LanguagesService $languagesService)
    {
        $this->template = str_repeat(PHP_EOL, 3) . 'CREATE TABLE %s (' . PHP_EOL
            . '  %s text ' . PHP_EOL . ');' . str_repeat(PHP_EOL, 3);
    }

    /**
     * Returns a class instance
     */
    public static function getInstance(): LanguageRestrictionRegistry
    {
        return GeneralUtility::makeInstance(__CLASS__);
    }

    /**
     * Gets all language restrictable tables
     */
    public function getLanguageRestrictableTables(): array
    {
        return array_keys($this->registry);
    }

    /**
     * Apply TCA to all registered tables
     *
     * @internal
     */
    public function applyTcaForPreRegisteredTables(): void
    {
        $this->registerDefaultTranslationRestrictableTables();
        foreach ($this->registry as $tableName => $fields) {
            foreach ($fields as $fieldName => $_) {
                $this->applyTcaForTableAndField($tableName, $fieldName);
            }
        }
    }

    /**
     * Add default translation restrictable tables to the registry
     */
    protected function registerDefaultTranslationRestrictableTables(): void
    {
        $defaultTranslationRestrictableTables = GeneralUtility::trimExplode(
            ',',
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['defaultTranslationRestrictableTables'] ?? '',
            true
        );
        foreach ($defaultTranslationRestrictableTables as $defaultTranslationRestrictedTable) {
            if (!$this->isRegistered($defaultTranslationRestrictedTable)) {
                $this->add(
                    'core',
                    $defaultTranslationRestrictedTable
                );
            }
        }
    }

    /**
     * Tells whether a table has a language restriction configuration in the registry.
     *
     * @param string $tableName Name of the table to be looked up
     * @param string $fieldName Name of the field to be looked up
     */
    public function isRegistered(string $tableName, string $fieldName = Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME): bool
    {
        return isset($this->registry[$tableName][$fieldName]);
    }

    /**
     * This function is used to add a field dynamically for the event AlterTableDefinitionStatementsEvent
     * The registration is done within the file ext_localconf.php
     *
     * This is required due the chaching mechanism of TYPO3 and using the typo3 console to process the SQL
     * information.
     *
     * @param string $extensionKey
     * @param string $tableName
     * @param string $fieldName
     * @return void
     */
    public function registerField(
        string $extensionKey,
        string $tableName,
        string $fieldName = Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
    ): void {
        $this->extensions[$extensionKey][$tableName][$fieldName] = $fieldName;
    }

    /**
     * Adds a new language restriction configuration to this registry.
     * TCA changes are directly applied
     *
     * @param string $extensionKey Extension key to be used
     * @param string $tableName Name of the table to be registered
     * @param string $fieldName Name of the field to be registered
     * @param array $options Additional configuration options
     *              + fieldList: field configuration to be added to showitems
     *              + typesList: list of types that shall visualize the language restriction field
     *              + position: insert position of the language restriction field
     *              + label: backend label of the language restriction field
     *              + fieldConfiguration: TCA field config array to override defaults
     * @param bool $override If FALSE, any language restriction configuration for the same table / field is kept as is even though the new configuration is added
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function add(
        string $extensionKey,
        string $tableName,
        string $fieldName = Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME,
        array $options = [],
        bool $override = true
    ): bool {
        $didRegister = false;
        if (empty($tableName) || !is_string($tableName)) {
            throw new InvalidArgumentException('No or invalid table name "' . $tableName . '" given.', 1540460445);
        }
        if (empty($extensionKey) || !is_string($extensionKey)) {
            throw new InvalidArgumentException(
                'No or invalid extension key "' . $extensionKey . '" given.',
                1540460446
            );
        }

        if ($override) {
            $this->remove($tableName, $fieldName);
        }

        if (!$this->isRegistered($tableName, $fieldName)) {
            if (!isset($this->registry[$tableName])) {
                $this->registry[$tableName] = [];
            }
            $this->registry[$tableName][$fieldName] = $options;

            if (isset($GLOBALS['TCA'][$tableName]['columns'])) {
                $this->applyTcaForTableAndField($tableName, $fieldName);
                $didRegister = true;
            }
        }

        return $didRegister;
    }

    /**
     * Removes the given field in the given table from the registry if it is found.
     *
     * @param string $tableName The name of the table for which the registration should be removed.
     * @param string $fieldName The name of the field for which the registration should be removed.
     */
    protected function remove(string $tableName, string $fieldName): void
    {
        if (!$this->isRegistered($tableName, $fieldName)) {
            return;
        }

        unset($this->registry[$tableName][$fieldName]);

        foreach ($this->extensions as $extensionKey => $tableFieldConfig) {
            foreach ($tableFieldConfig as $extTableName => $fieldNameArray) {
                if ($extTableName === $tableName && isset($fieldNameArray[$fieldName])) {
                    unset($this->extensions[$extensionKey][$tableName][$fieldName]);
                    break;
                }
            }
        }
    }

    /**
     * Applies the additions directly to the TCA
     */
    protected function applyTcaForTableAndField(string $tableName, string $fieldName): void
    {
        $this->addTcaColumn($tableName, $fieldName, $this->registry[$tableName][$fieldName] ?? '');
        $this->addToAllTCAtypes($tableName, $this->registry[$tableName][$fieldName] ?? '');
    }

    /**
     * Add a new TCA Column
     *
     * @param string $tableName Name of the table to be language restrictable
     * @param string $fieldName Name of the field to be used to store language restrictions
     * @param array $options Additional configuration options
     *              + fieldConfiguration: TCA field config array to override defaults
     *              + label: backend label of the language restriction field
     *              + interface: boolean if the language restriction should be included in the "interface" section of the TCA table
     *              + l10n_mode
     *              + l10n_display
     */
    protected function addTcaColumn(string $tableName, string $fieldName, array $options): void
    {
        // Makes sure to add more TCA to an existing structure
        if (isset($GLOBALS['TCA'][$tableName]['columns'])) {
            // Take specific label into account
            $label = 'LLL:EXT:l10nmgr/Resources/Private/Language/locallang_db.xlf:sys_language.restrictions';
            if (!empty($options['label'])) {
                $label = $options['label'];
            }

            // Take specific value of exclude flag into account
            $exclude = true;
            if (isset($options['exclude'])) {
                $exclude = (bool)$options['exclude'];
            }

            $fieldConfiguration = $options['fieldConfiguration'] ?? [];

            $columns = [
                $fieldName => [
                    'exclude' => $exclude,
                    'label' => $label,
                    'config' => static::getTcaFieldConfiguration($fieldConfiguration),
                ],
            ];

            if (isset($options['l10n_mode'])) {
                $columns[$fieldName]['l10n_mode'] = $options['l10n_mode'];
            }
            if (isset($options['l10n_display'])) {
                $columns[$fieldName]['l10n_display'] = $options['l10n_display'];
            }
            if (isset($options['displayCond'])) {
                $columns[$fieldName]['displayCond'] = $options['displayCond'];
            }

            // Add field to interface list per default (unless the 'interface' property is FALSE)
            if (
                (!isset($options['interface']) || $options['interface'])
                && !empty($GLOBALS['TCA'][$tableName]['interface']['showRecordFieldList'])
                && !GeneralUtility::inList($GLOBALS['TCA'][$tableName]['interface']['showRecordFieldList'], $fieldName)
            ) {
                $GLOBALS['TCA'][$tableName]['interface']['showRecordFieldList'] .= ',' . $fieldName;
            }

            // Adding fields to an existing table definition
            ExtensionManagementUtility::addTCAcolumns($tableName, $columns);
        }
    }

    /**
     * Get the config array for given table and field.
     * This method does NOT take care of adding sql fields or adding the field to TCA types.
     * This has to be taken care of manually!
     *
     * @param array $fieldConfigurationOverride Changes to the default configuration
     * @api
     */
    public static function getTcaFieldConfiguration(array $fieldConfigurationOverride = []): array
    {
        // Forges a new field, default name is "l10nmgr_language_restriction"
        $fieldConfiguration = [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'itemsProcFunc' => LanguageRestrictionRegistry::class . '->populateAvailableSiteLanguages',
            'maxitems' => 9999,
        ];

        // Merge changes to TCA configuration
        if (!empty($fieldConfigurationOverride)) {
            ArrayUtility::mergeRecursiveWithOverrule($fieldConfiguration, $fieldConfigurationOverride);
        }

        return $fieldConfiguration;
    }

    /**
     * Add a new field into the TCA types -> showitem
     *
     * @param string $tableName Name of the table to be language restrictable
     * @param array $options Additional configuration options
     *              + fieldList: field configuration to be added to showitems
     *              + typesList: list of types that shall visualize the language restriction field
     *              + position: insert position of the language restriction field
     */
    protected function addToAllTCAtypes(string $tableName, array $options): void
    {
        // Makes sure to add more TCA to an existing structure
        if (isset($GLOBALS['TCA'][$tableName]['columns'])) {
            $fieldList = $options['fieldList'] ?? '';

            if (empty($fieldList)) {
                $fieldList = Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME;
            }

            $typesList = '';
            if (isset($options['typesList']) && $options['typesList'] !== '') {
                $typesList = $options['typesList'];
            }

            $position = $tableName === 'pages' ? 'after:l18n_cfg' : 'after:sys_language_uid';
            if (!empty($options['position'])) {
                $position = $options['position'];
            }
            // Makes the new "l10nmgr_language_restriction" field to be visible in TSFE.
            ExtensionManagementUtility::addToAllTCAtypes($tableName, $fieldList, $typesList, $position);
        }
    }

    /**
     * A event listener to inject the required language restriction database fields to the
     * tables definition string
     *
     * @internal
     */
    public function addLanguageRestrictionDatabaseSchema(AlterTableDefinitionStatementsEvent $event): void
    {
        $this->registerDefaultTranslationRestrictableTables();
        $event->addSqlData($this->getDatabaseTableDefinitions());
    }

    /**
     * Generates tables definitions for all registered tables.
     */
    public function getDatabaseTableDefinitions(): string
    {
        $sql = '';
        foreach ($this->getExtensionKeys() as $extensionKey) {
            $sql .= $this->getDatabaseTableDefinition($extensionKey);
        }
        return $sql;
    }

    /**
     * Gets all extension keys that registered a language restriction configuration.
     */
    public function getExtensionKeys(): array
    {
        return array_keys($this->extensions);
    }

    /**
     * Generates table definitions for registered tables by an extension.
     *
     * @param string $extensionKey Extension key to have the database definitions created for
     */
    public function getDatabaseTableDefinition(string $extensionKey): string
    {
        if (!isset($this->extensions[$extensionKey]) || !is_array($this->extensions[$extensionKey])) {
            return '';
        }
        $sql = '';

        foreach ($this->extensions[$extensionKey] as $tableName => $fields) {
            foreach ($fields as $fieldName) {
                $sql .= sprintf($this->template, $tableName, $fieldName);
            }
        }
        return $sql;
    }


    /**
     * Provides a list of all languages available for ALL sites.
     * In case no site configuration can be found in the system,
     * a fallback is used to add at least the default language.
     *
     * Used by be_users and be_groups for their `allowed_languages` column.
     */
    public function populateAvailableSiteLanguages(array &$fieldInformation): void
    {
        $allLanguages = $this->languagesService->getAll();

        if ($allLanguages !== []) {
            ksort($allLanguages);
            unset($allLanguages[0]);
            foreach ($allLanguages as $item) {
                $fieldInformation['items'][] = [$item['label'], $item['value'], $item['icon']];
            }
            return;
        }

        // Fallback if no site configuration exists
        $recordPid = (int)($fieldInformation['row']['pid'] ?? 0);
        $languages = (new NullSite())->getAvailableLanguages($this->getBackendUser(), false, $recordPid);

        foreach ($languages as $languageId => $language) {
            $fieldInformation['items'][] = [
                'label' => $language->getTitle(),
                'value' => $languageId,
                'icon' => $language->getFlagIdentifier(),
            ];
        }
    }
}
