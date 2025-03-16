<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Command;

/***************************************************************
 * Copyright notice
 * (c) 2008 Daniel Zielinski (d.zielinski@l10ntech.de)
 * (c) 2011 Francois Suter (typo3@cobweb.ch)
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

use Doctrine\DBAL\Exception as DBALException;
use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use Localizationteam\L10nmgr\Model\L10nBaseService;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\MkPreviewLinkService;
use Localizationteam\L10nmgr\Model\Tools\XmlTools;
use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use Localizationteam\L10nmgr\Zip;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Import extends L10nCommand
{
    /**
     * @var int ID of the language being handled
     */
    protected int $sysLanguage = 0;

    /**
     * @var int ID of the forced source language being handled
     */
    protected int $previewLanguage = 0;

    /**
     * @var string Path to temporary de-archiving directory, to be removed after import
     */
    protected string $directoryToCleanUp = '';

    /**
     * @var array List of files that were imported, with additional information, used for reporting after import
     */
    protected array $filesImported = [];

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure(): void
    {
        $this->setDescription('Import the translations as file')
            ->setHelp('With this command you can import translation')
            ->addOption(
                'task',
                't',
                InputOption::VALUE_REQUIRED,
                "The values can be:\n importString = Import a XML string\n importFile = Import a XML file\n preview = Generate a preview of the source from a XML string"
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to the file to import. Can be XML or ZIP archive. If both XML string and import file are not defined, will import from FTP server (if defined).'
            )
            ->addOption('importAsDefaultLanguage', 'd', InputOption::VALUE_NONE, 'Import as default language')
            ->addOption('preview', 'p', InputOption::VALUE_NONE, 'Preview flag')
            ->addOption('server', null, InputOption::VALUE_OPTIONAL, 'Server link for the preview URL.')
            ->addOption(
                'srcPID',
                'P',
                InputOption::VALUE_OPTIONAL,
                'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0',
                0
            )
            ->addOption('string', 's', InputOption::VALUE_OPTIONAL, 'XML string to import.');
    }

    /**
     * Executes the command for straigthening content elements
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);

        // Ensure the _cli_ user is authenticated
        $this->getBackendUser()->backendCheckLogin();
        // Parse the command-line arguments
        try {
            $callParameters = $this->initializeCallParameters($input);
            switch ($callParameters['task']) {
                case 'importString':
                case 'preview':
                    // Get workspace id from CATXML
                    // Continue if found, else exit script execution
                    $wsId = $this->getWsIdFromCATXML($callParameters['string']);
                    // Set workspace to the required workspace ID from CATXML:
                    $this->getBackendUser()->setWorkspace($wsId);
                    if ($callParameters['task'] === 'importString') {
                        $msg = $this->importCATXML($callParameters);
                    } else {
                        $msg = $this->previewSource($callParameters['string']);
                    }
                    break;
                case 'importFile':
                    $this->importXMLFile($callParameters);
                    $msg = "\n\nImport was successful.\n";
                    break;
                default:
                    $output->writeln('<error>Please specify a task with --task. Either "importString", "preview" or "importFile".</error>');
                    return 1;
            }
        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
        // Calculate duration and output result message
        $end = microtime(true);
        $time = $end - $start;
        $output->writeln($msg);
        $output->writeln(sprintf($this->getLanguageService()->getLL('import.process.duration.message'), $time));
        // Send reporting mail
        $this->sendMailNotification();
        return 0;
    }

    /**
     * This method reads the command-line arguments and prepares a list of call parameters
     * It takes care of backwards-compatibility with the old way of calling the import script
     *
     * @throws Exception
     */
    protected function initializeCallParameters(InputInterface $input): array
    {
        // Get the task parameter from either the new or the old input style
        // The default is in the configure()

        if ($input->getOption('task') === 'importString' || $input->getOption('task') === 'importFile' || $input->getOption('task') === 'preview') {
            $callParameters['task'] = $input->getOption('task');
        } else {
            throw new Exception(
                'Please specify a task with --task. Either "importString", "preview" or "importFile".',
                1539950024
            );
        }

        // Get the preview flag
        $callParameters['preview'] = $input->getOption('preview');

        // Get the XML string
        $callParameters['string'] = stripslashes((string)$input->getOption('string'));

        // Get the path to XML or ZIP file
        $callParameters['file'] = $input->getOption('file');

        // Get the server link for preview
        $callParameters['server'] = $input->getOption('server');
        // Import as default language
        $callParameters['importAsDefaultLanguage'] = $input->getOption('importAsDefaultLanguage');
        // Source PID
        $callParameters['sourcePid'] = $input->getOption('srcPID');

        return $callParameters;
    }

    /**
     * Get workspace ID from XML (quick & dirty)
     *
     * @param string $xml XML string to parse
     *
     * @return int ID of the workspace to import to
     * @throws Exception
     */
    protected function getWsIdFromCATXML(string $xml): int
    {
        if (empty($xml)) {
            throw new Exception('No XML passed for import. Pass the XML via --string.', 1322475562);
        }
        preg_match('/<t3_workspaceId>([^<]+)/', $xml, $matches);
        if (!empty($matches)) {
            return (int)$matches[1];
        }
        throw new Exception('No workspace id found in the passed XML', 1322475562);
    }

    /**
     * Imports a CATXML string
     *
     * @param array $callParameters
     * @return string
     * @throws DBALException
     * @throws Exception
     */
    protected function importCATXML(array $callParameters): string
    {
        $out = '';
        /** @var L10nBaseService $service */
        $service = GeneralUtility::makeInstance(L10nBaseService::class);
        if (!empty($callParameters['importAsDefaultLanguage'])) {
            $service->setImportAsDefaultLanguage(true);
        }
        /** @var TranslationDataFactory $factory */
        $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);
        /** @var CatXmlImportManager $importManager */
        $importManager = GeneralUtility::makeInstance(
            CatXmlImportManager::class,
            '',
            $this->sysLanguage,
            $callParameters['string']
        );
        // Parse and check XML, load header data
        if ($importManager->parseAndCheckXMLString() === false) {
            $tmp = var_export($importManager->headerData, true);
            $tmp = str_replace("\n", '', $tmp);
            $error = $tmp . $this->getLanguageService()->getLL('import.manager.error.parsing.xmlstring.message');
            throw new Exception($error);
        }
        // Find l10n configuration record
        /** @var L10nConfiguration $l10ncfgObj */
        $l10ncfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10ncfgObj->load((int)($importManager->headerData['t3_l10ncfg'] ?? 0));
        $l10ncfgObj->setSourcePid((int)$callParameters['sourcePid']);
        $status = $l10ncfgObj->isLoaded();
        if ($status === false) {
            throw new Exception('l10ncfg not loaded! Exiting...');
        }
        //Do import...
        $this->sysLanguage = (int)($importManager->headerData['t3_sysLang'] ?? 0); //set import language to t3_sysLang from XML
        if (!empty($importManager->headerData['t3_sourceLang']) && !empty($importManager->headerData['t3_targetLang'])
            && $importManager->headerData['t3_sourceLang'] === $importManager->headerData['t3_targetLang']) {
            $this->previewLanguage = $this->sysLanguage;
        }
        //Delete previous translations
        $importManager->delL10N($importManager->getDelL10NDataFromCATXMLNodes($importManager->getXMLNodes()));
        //Make preview links
        if (!empty($callParameters['preview'])) {
            if (!ExtensionManagementUtility::isLoaded('workspaces')) {
                $out .= 'Workspace extension not installed. Skipping preview generation.';
            } else {
                $pageIds = [];
                if (empty($importManager->headerData['t3_previewId'])) {
                    $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXMLNodes());
                } else {
                    $pageIds[0] = $importManager->headerData['t3_previewId'];
                }
                /** @var MkPreviewLinkService $mkPreviewLinks */
                $mkPreviewLinks = GeneralUtility::makeInstance(
                    MkPreviewLinkService::class,
                    (int)($importManager->headerData['t3_workspaceId'] ?? 0),
                    (int)($importManager->headerData['t3_sysLang'] ?? 0),
                    $pageIds
                );
                $previewLink = $mkPreviewLinks->mkSinglePreviewLink(
                    (string)($importManager->headerData['t3_baseURL'] ?? ''),
                    (string)$callParameters['server']
                );
                $out .= $previewLink;
            }
        }
        $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());
        $translationData->setLanguage($this->sysLanguage);
        $translationData->setPreviewLanguage($this->previewLanguage);
        unset($importManager);
        $service->saveTranslation($l10ncfgObj, $translationData);
        if (empty($out)) {
            $out = '1';
        } //Means OK if preview = 0
        return $out;
    }

    /**
     * Previews the source to import
     *
     * @throws Exception
     */
    protected function previewSource(string $stringParameter): string
    {
        $out = '';
        $error = '';
        /** @var CatXmlImportManager $importManager */
        $importManager = GeneralUtility::makeInstance(
            CatXmlImportManager::class,
            '',
            $this->sysLanguage,
            $stringParameter
        );
        // Parse and check XML, load header data
        if ($importManager->parseAndCheckXMLString() === false) {
            $tmp = var_export($importManager->headerData, true);
            $tmp = str_replace("\n", '', $tmp);
            $error .= $tmp;
            $error .= $this->getLanguageService()->getLL('import.manager.error.parsing.xmlstring.message');
            throw new Exception($error);
        }
        $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXMLNodes());
        /** @var MkPreviewLinkService $mkPreviewLinks */
        $mkPreviewLinks = GeneralUtility::makeInstance(
            MkPreviewLinkService::class,
            (int)($importManager->headerData['t3_workspaceId'] ?? 0),
            (int)($importManager->headerData['t3_sysLang'] ?? 0),
            $pageIds
        );
        //Only valid if source language = default language (id=0)
        $previewLink = $mkPreviewLinks->mkSingleSrcPreviewLink((string)($importManager->headerData['t3_baseURL'] ?? ''), 0);
        $out .= $previewLink;

        // Output
        return $out;
    }

    /**
     * Imports data from one or more XML files
     * Several files may be contained in a ZIP archive
     *
     * @param array $callParameters
     * @throws DBALException
     * @throws Exception
     */
    protected function importXMLFile(array $callParameters): void
    {
        $out = '';
        $xmlFilesArr = $this->gatherAllFiles((string)$callParameters['file']);

        if (empty($xmlFilesArr)) {
            throw new Exception("\nNo files to import! Either point to a file using the --file option or define a FTP server to get the files from");
        }

        foreach ($xmlFilesArr as $xmlFile) {
            try {
                $xmlFileHead = $this->getXMLFileHead($xmlFile);
                // Set workspace to the required workspace ID from CATXML:
                $workspaceId = $xmlFileHead['t3_workspaceId'][0]['XMLvalue'] ?? 0;
                $this->getBackendUser()->setWorkspace($workspaceId);
                // Set import language to t3_sysLang from XML
                $this->sysLanguage = (int)($xmlFileHead['t3_sysLang'][0]['XMLvalue'] ?? 0);
                $sourceLanguage = $xmlFileHead['t3_sourceLang'][0]['XMLvalue'] ?? 0;
                $targetLanguage = $xmlFileHead['t3_targetLang'][0]['XMLvalue'] ?? 0;
                if ($sourceLanguage === $targetLanguage) {
                    $this->previewLanguage = $this->sysLanguage;
                }
                /** @var L10nBaseService $service */
                $service = GeneralUtility::makeInstance(L10nBaseService::class);
                if ($callParameters['importAsDefaultLanguage']) {
                    $service->setImportAsDefaultLanguage(true);
                }
                /** @var TranslationDataFactory $factory */
                $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);
                // Relevant processing of XML Import with the help of the Importmanager
                /** @var CatXmlImportManager $importManager */
                $importManager = GeneralUtility::makeInstance(
                    CatXmlImportManager::class,
                    $xmlFile,
                    $this->sysLanguage,
                    ''
                );
                if ($importManager->parseAndCheckXMLFile() === false) {
                    $out .= "\n\n" . $importManager->getErrorMessages();
                } else {
                    // Find l10n configuration record
                    /** @var L10nConfiguration $l10ncfgObj */
                    $l10ncfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
                    $l10ncfgObj->load((int)($importManager->headerData['t3_l10ncfg'] ?? 0));
                    $l10ncfgObj->setSourcePid($callParameters['sourcePid'] ?? 0);
                    $status = $l10ncfgObj->isLoaded();
                    if ($status === false) {
                        throw new Exception("l10ncfg not loaded! Exiting...\n");
                    }
                    // Delete previous translations
                    $importManager->delL10N($importManager->getDelL10NDataFromCATXMLNodes($importManager->getXMLNodes()));
                    // Make preview links
                    if ($callParameters['preview']) {
                        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
                            $out .= 'Workspace extension not installed. Skipping preview generation.';
                        } else {
                            $pageIds = [];
                            if (empty($importManager->headerData['t3_previewId'])) {
                                $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXMLNodes());
                            } else {
                                $pageIds[0] = $importManager->headerData['t3_previewId'];
                            }
                            /** @var MkPreviewLinkService $mkPreviewLinks */
                            $mkPreviewLinks = GeneralUtility::makeInstance(
                                MkPreviewLinkService::class,
                                (int)($importManager->headerData['t3_workspaceId'] ?? 0),
                                (int)($importManager->headerData['t3_sysLang'] ?? 0),
                                $pageIds
                            );
                            $previewLink = $mkPreviewLinks->mkSinglePreviewLink(
                                (string)($importManager->headerData['t3_baseURL'] ?? ''),
                                (string)$callParameters['server']
                            );
                            $out .= $previewLink;
                        }
                    }
                    $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());
                    $translationData->setLanguage($this->sysLanguage);
                    $translationData->setPreviewLanguage($this->previewLanguage);
                    unset($importManager);
                    $service->saveTranslation($l10ncfgObj, $translationData);
                    // Store some information about the imported file
                    // This is used later for reporting by mail
                    $this->filesImported[$xmlFile] = [
                        'workspace' => $workspaceId,
                        'language' => $targetLanguage,
                        'configuration' => $xmlFileHead['t3_l10ncfg'][0]['XMLvalue'] ?? 0,
                    ];
                }
            } catch (Exception $e) {
                if ($e->getCode() == 1390394945) {
                    $errorMessage = $e->getMessage();
                } else {
                    $errorMessage = 'Badly formatted file (' . $e->getMessage() . ')';
                }
                $out .= "\n\n" . $xmlFile . ': ' . $errorMessage;
                // Store the error message for later reporting by mail
                $this->filesImported[$xmlFile] = [
                    'error' => $errorMessage,
                ];
            }
        }

        // Clean up after import
        $this->importCleanUp();

        // Means Error
        if (!empty($out)) {
            throw new Exception($out);
        }
    }

    /**
     * Gather all the files to be imported, depending on the call parameters
     *
     * @throws Exception
     */
    protected function gatherAllFiles(string $file): array
    {
        $files = [];
        // If no file path was given, try to gather files from FTP
        if (empty($file)) {
            if (!empty($this->emConfiguration->getFtpServer())) {
                $files = $this->getFilesFromFtp();
            }
        } else {
            // Get list of files to import from given command-line parameter
            $fileInformation = pathinfo($file);
            // Unzip file if *.zip
            if ($fileInformation['extension'] === 'zip') {
                /** @var Zip $unzip */
                $unzip = GeneralUtility::makeInstance(Zip::class);
                $unzipResource = $unzip->extractFile($file);
                // Process extracted files if file type = xml => IMPORT
                $files = $this->checkFileType($unzipResource['fileArr'] ?? [], 'xml');
                // Store the temporary directory's path for later clean up
                $this->directoryToCleanUp = (string)($unzipResource['tempDir'] ?? '');
            } elseif ($fileInformation['extension'] === 'xml') {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Gets all available XML or ZIP files from the FTP server
     *
     * @return array List of files, as local paths
     * @throws Exception
     */
    protected function getFilesFromFtp(): array
    {
        $files = [];
        // First try connecting and logging in
        $connection = ftp_connect($this->emConfiguration->getFtpServer());
        if ($connection === false) {
            throw new Exception('Could not connect to FTP server', 1322489458);
        }
        if (@ftp_login(
            $connection,
            $this->emConfiguration->getFtpServerUsername(),
            $this->emConfiguration->getFtpServerPassword()
        )
        ) {
            ftp_pasv($connection, true);
            // If a path was defined, change directory to this path
            if (!empty($this->emConfiguration->getFtpServerDownPath())) {
                $result = ftp_chdir($connection, $this->emConfiguration->getFtpServerDownPath());
                if ($result === false) {
                    throw new Exception(
                        'Could not change to directory: ' . $this->emConfiguration->getFtpServerDownPath(),
                        1322489723
                    );
                }
            }
            // Get list of files to download from current directory
            $filesToDownload = ftp_nlist($connection, '');
            // If there are any files, loop on them
            if ($filesToDownload) {
                // Check that download directory exists
                $downloadPath = Environment::getPublicPath() . '/uploads/tx_l10nmgr/jobs/in/';
                if (!is_dir(GeneralUtility::getFileAbsFileName($downloadPath))) {
                    GeneralUtility::mkdir_deep($downloadPath);
                }
                foreach ($filesToDownload as $aFile) {
                    // Ignore current directory and reference to upper level
                    if ($aFile != '.' && $aFile != '..') {
                        $fileInformation = pathinfo($aFile);
                        // Download only XML or ZIP files
                        if ($fileInformation['extension'] === 'xml' || $fileInformation['extension'] === 'zip') {
                            $savePath = $downloadPath . $aFile;
                            // Get each file and save them to temporary directory
                            $result = ftp_get($connection, $savePath, $aFile, FTP_BINARY);
                            if ($result) {
                                // If the file is XML, list it for usage as is
                                if ($fileInformation['extension'] === 'xml') {
                                    $files[] = $savePath;
                                } else {
                                    /** @var Zip $unzip */
                                    $unzip = GeneralUtility::makeInstance(Zip::class);
                                    $unzipResource = $unzip->extractFile($savePath);
                                    // Process extracted files if file type = xml => IMPORT
                                    $archiveFiles = $this->checkFileType($unzipResource['fileArr'] ?? [], 'xml');
                                    $files = array_merge($files, $archiveFiles);
                                    // Store the temporary directory's path for later clean up
                                    $this->directoryToCleanUp = (string)($unzipResource['tempDir'] ?? '');
                                }
                                // Remove the file from the FTP server
                                $result = ftp_delete($connection, $aFile);
                                // If deleting failed, register error message
                                // (don't throw exception as this does not need to interrupt the process)
                                if (!$result) {
                                    throw new Exception('Could not remove file ' . $aFile . 'from FTP server');
                                }
                                // If getting the file failed, register error message
                                // (don't throw exception as this does not need to interrupt the process)
                            } else {
                                throw new Exception('Problem getting file ' . $aFile . 'from server or saving it locally');
                            }
                        }
                    }
                }
            }
        } else {
            ftp_close($connection);
            throw new Exception('Could not log into to FTP server', 1322489527);
        }

        return $files;
    }

    /**
     * Check file types from a list of files
     *
     * @param array $files Array of files to be checked
     * @param string $ext File extension to be tested for
     *
     * @return array Files that passed test
     */
    protected function checkFileType(array $files, string $ext): array
    {
        $passed = [];
        foreach ($files as $file) {
            if (preg_match('/' . $ext . '$/', $file)) {
                $passed[] = $file;
            }
        }
        return $passed;
    }

    /**
     * Extracts the header of a CATXML file
     *
     * @param string $filepath Path to the file
     *
     * @throws Exception
     */
    protected function getXMLFileHead(string $filepath): array
    {
        $fileContent = GeneralUtility::getUrl($filepath);
        if ($fileContent === false) {
            throw new Exception(
                "File or URL cannot be read.\n \\TYPO3\\CMS\\Core\\Utility\\GeneralUtility::getURL() error code: ",
                1390394945
            );
        }
        // For some reason PHP chokes on incoming &nbsp; in XML!
        $xmlNodes = XmlTools::xml2tree(str_replace('&nbsp;', '&#160;', $fileContent), 3);
        if (!is_array($xmlNodes)) {
            throw new Exception(
                $this->getLanguageService()->getLL('import.manager.error.parsing.xml2tree.message') . $xmlNodes,
                1322480030
            );
        }
        $headerInformationNodes = $xmlNodes['TYPO3L10N'][0]['ch']['head'][0]['ch'] ?? [];
        if (empty($headerInformationNodes)) {
            throw new Exception(
                $this->getLanguageService()->getLL('import.manager.error.missing.head.message'),
                1322480056
            );
        }
        return $headerInformationNodes;
    }

    /**
     * Cleans up after the import process, as needed
     */
    protected function importCleanUp(): void
    {
        // Clean up directory into which ZIP archives were uncompressed, if any
        if (!empty($this->directoryToCleanUp)) {
            /** @var Zip $unzip */
            $unzip = GeneralUtility::makeInstance(Zip::class);
            $unzip->removeDir($this->directoryToCleanUp);
        }
    }

    /**
     * Sends reporting mail about which files were imported
     * @throws DBALException
     * @throws AspectNotFoundException
     */
    protected function sendMailNotification(): void
    {
        // Send mail only if notifications are active and at least one file was imported
        if ($this->emConfiguration->isEnableNotification() && count($this->filesImported) > 0) {
            // If at least a recipient is indeed defined, proceed with sending the mail
            $recipients = GeneralUtility::trimExplode(',', $this->emConfiguration->getEmailRecipientImport());
            if (count($recipients) > 0) {
                // First of all get a list of all workspaces and all l10nmgr configurations to use in the reporting
                /** @var QueryBuilder $queryBuilder */
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_workspace');
                $records = $queryBuilder->select('uid', 'title')
                    ->from('sys_workspace')
                    ->executeQuery()
                    ->fetchAllAssociative();
                $workspaces = [];
                if (!empty($records)) {
                    foreach ($records as $record) {
                        $workspaces[$record['uid']] = $record;
                    }
                }
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_l10nmgr_cfg');
                $records = $queryBuilder->select('uid', 'title')
                    ->from('tx_l10nmgr_cfg')
                    ->executeQuery()
                    ->fetchAllAssociative();
                $l10nConfigurations = [];
                if (!empty($records)) {
                    foreach ($records as $record) {
                        $l10nConfigurations[$record['uid']] = $record;
                    }
                }
                // Start assembling the mail message
                $message = sprintf(
                    $this->getLanguageService()->getLL('import.mail.intro'),
                    date('d.m.Y H:i:s', GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp')),
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']
                ) . "\n\n";
                foreach ($this->filesImported as $file => $fileInformation) {
                    if (isset($fileInformation['error'])) {
                        $status = $this->getLanguageService()->getLL('import.mail.error');
                        $message .= '[' . $status . '] ' . sprintf(
                            $this->getLanguageService()->getLL('import.mail.file'),
                            $file
                        ) . "\n";
                        $message .= "\t" . sprintf(
                            $this->getLanguageService()->getLL('import.mail.import.failed'),
                            $fileInformation['error']
                        ) . "\n";
                    } else {
                        $status = $this->getLanguageService()->getLL('import.mail.ok');
                        $message .= '[' . $status . '] ' . sprintf(
                            $this->getLanguageService()->getLL('import.mail.file'),
                            $file
                        ) . "\n";
                        // Get the workspace's name and add workspace information
                        if (!empty($fileInformation['workspace'])) {
                            if (isset($workspaces[$fileInformation['workspace']])) {
                                $workspaceName = $workspaces[$fileInformation['workspace']]['title'] ?? '';
                            } else {
                                $workspaceName = $this->getLanguageService()->getLL('import.mail.workspace.unknown');
                            }
                        } else {
                            $workspaceName = 'LIVE';
                        }
                        $message .= "\t" . sprintf(
                            $this->getLanguageService()->getLL('import.mail.workspace'),
                            $workspaceName,
                            $fileInformation['workspace'] ?? 0
                        ) . "\n";
                        // Add language information
                        $message .= "\t" . sprintf(
                            $this->getLanguageService()->getLL('import.mail.language'),
                            $fileInformation['language'] ?? 0
                        ) . "\n";
                        // Get configuration's name and add configuration information
                        if (isset($l10nConfigurations[$fileInformation['configuration'] ?? 0])) {
                            $configurationName = $l10nConfigurations[$fileInformation['configuration']]['title'] ?? '';
                        } else {
                            $configurationName = $this->getLanguageService()->getLL('import.mail.l10nconfig.unknown');
                        }
                        $message .= "\t" . sprintf(
                            $this->getLanguageService()->getLL('import.mail.l10nconfig'),
                            $configurationName,
                            $fileInformation['configuration']
                        ) . "\n";
                    }
                }
                // Add signature
                $message .= "\n\n" . $this->getLanguageService()->getLL('email.goodbye.msg');
                $message .= "\n" . $this->emConfiguration->getEmailSenderName();
                $subject = sprintf(
                    $this->getLanguageService()->getLL('import.mail.subject'),
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''
                );
                // Instantiate the mail object, set all necessary properties and send the mail
                /** @var MailMessage $mailObject */
                $mailObject = GeneralUtility::makeInstance(MailMessage::class);
                $mailObject->setFrom([$this->emConfiguration->getEmailSender() => $this->emConfiguration->getEmailSenderName()]);
                $mailObject->setTo($recipients);
                $mailObject->setSubject($subject);
                $mailObject->text($message);
                $mailObject->send();
            }
        }
    }
}
