<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Command;

/***************************************************************
 * Copyright notice
 * (c) 2008 Daniel Zielinski (d.zielinski@l10ntech.de)
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

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\NotificationService;
use Localizationteam\L10nmgr\View\CatXmlView;
use Localizationteam\L10nmgr\View\ExcelXmlView;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class Export extends L10nCommand
{
    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure(): void
    {
        $this->setDescription('Export the translations as file')
            ->setHelp('With this command you can Export translation')
            ->addOption('check-exports', null, InputOption::VALUE_NONE, 'Check for already exported content')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_OPTIONAL,
                "UIDs of the localization manager configurations to be used for export. Comma separated values, no spaces.\nDefault is EXTCONF which means values are taken from extension configuration.",
                'EXTCONF'
            )
            ->addOption(
                'forcedSourceLanguage',
                'f',
                InputOption::VALUE_OPTIONAL,
                'UID of the already translated language used as overlaid source language during export.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                "Format for export of translatable data can be:\n CATXML = XML for translation tools (default)\n EXCEL = Microsoft XML format",
                'CATXML'
            )
            ->addOption('noHidden', null, InputOption::VALUE_NONE, 'Do not export hidden contents')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Export only new contents')
            ->addOption(
                'srcPID',
                'p',
                InputOption::VALUE_OPTIONAL,
                'UID of the page used during export. Needs configuration depth to be set to "current page" Default = 0',
                0
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_OPTIONAL,
                'UIDs for the target languages used during export. Comma seperated values, no spaces. Default is 0. In that case UIDs are taken from extension configuration.'
            )
            ->addOption('updated', 'u', InputOption::VALUE_NONE, 'Export only updated contents')
            ->addOption(
                'workspace',
                'w',
                InputOption::VALUE_OPTIONAL,
                'UID of the workspace used during export. Default = 0',
                0
            )
            ->addOption(
                'customer',
                null,
                InputOption::VALUE_OPTIONAL,
                'Name of the responsible customer. Default = Real name of the CLI backend user',
                0
            )
            ->addOption(
                'baseUrl',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Base URL for the export. E.g. https://example.com/',
                ''
            )
            ->addOption(
                'checkXml',
                'x',
                InputOption::VALUE_OPTIONAL,
                'Set to true if invalid XML should be excluded from export. When set to false (default) the falsy XML string will be wrapped in CDATA.',
                false
            )
            ->addOption(
                'utf8',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set to true if XML should be checked for valid UTF-8. If set to false (default) no such check is performed.',
                false
            );
    }

    /**
     * Executes the command for straightening content elements
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $error = false;
        $time_start = microtime(true);

        // Ensure the _cli_ user is authenticated
        $this->getBackendUser()->backendCheckLogin();

        // get format (CATXML,EXCEL)
        $format = $input->getOption('format');

        // get l10ncfg command line takes precedence over extensionConfiguration
        $l10nConfiguration = $input->getOption('config');
        $l10nConfigurations = [];
        if ($l10nConfiguration !== 'EXTCONF' && !empty($l10nConfiguration)) {
            //export single
            $l10nConfigurations = explode(',', $l10nConfiguration);
        } elseif (!empty($this->emConfiguration->getL10NmgrCfg())) {
            //export multiple
            $l10nConfigurations = explode(',', $this->emConfiguration->getL10NmgrCfg());
        } else {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.no_l10ncfg.msg') . '</error>');
            $error = true;
        }

        // get target languages
        $targetLanguageIdsFromCli = $input->getOption('target') ?? '0';
        $targetLanguageIds = [];
        if ($targetLanguageIdsFromCli !== '0') {
            //export single
            $targetLanguageIds = explode(',', $targetLanguageIdsFromCli);
        } elseif (!empty($this->emConfiguration->getL10NmgrTlangs())) {
            //export multiple
            $targetLanguageIds = explode(',', $this->emConfiguration->getL10NmgrTlangs());
        } else {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.target_language_id.msg') . '</error>');
            $error = true;
        }

        // get workspace ID
        $wsId = $input->getOption('workspace') ?? '0';
        // todo does workspace exits?
        if (MathUtility::canBeInterpretedAsInteger($wsId) === false) {
            $output->writeln('<error>' . $this->getLanguageService()->getLL('error.workspace_id_int.msg') . '</error>');
            $error = true;
        }

        $msg = '';

        // to
        // Set workspace to the required workspace ID from CATXML:
        $this->getBackendUser()->setWorkspace($wsId);

        if ($error) {
            return 1;
        }
        foreach ($l10nConfigurations as $l10nConfiguration) {
            if (MathUtility::canBeInterpretedAsInteger($l10nConfiguration) === false) {
                $output->writeln('<error>' . $this->getLanguageService()->getLL('error.l10ncfg_id_int.msg') . '</error>');
                return 1;
            }
            foreach ($targetLanguageIds as $targetLanguageId) {
                if (MathUtility::canBeInterpretedAsInteger($targetLanguageId) === false) {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.target_language_id_integer.msg') . '</error>');
                    return 1;
                }
                try {
                    $msg .= $this->exportXML((int)$l10nConfiguration, (int)$targetLanguageId, (string)$format, $input, $output);
                } catch (Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return 1;
                }
            }
        }

        $time_end = microtime(true);
        $time = $time_end - $time_start;
        $output->writeln($msg . LF);
        $output->writeln(sprintf($this->getLanguageService()->getLL('export.process.duration.message'), $time) . LF);
        return 0;
    }

    /**
     * exportCATXML which is called over cli
     *
     * @param int $l10nConfigurationId
     * @param int $targetLanguageId
     * @param string $format
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string An error message in case of failure
     * @throws Exception
     * @throws SiteNotFoundException
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    protected function exportXML(int $l10nConfigurationId, int $targetLanguageId, string $format, InputInterface $input, OutputInterface $output): string
    {
        $error = '';
        // Load the configuration
        /** @var L10nConfiguration $l10nmgrCfgObj */
        $l10nmgrCfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10nmgrCfgObj->load($l10nConfigurationId);
        $sourcePid = $input->getOption('srcPID') ?? 0;
        $l10nmgrCfgObj->setSourcePid($sourcePid);
        if ($l10nmgrCfgObj->isLoaded()) {
            if ($format === 'CATXML') {
                /** @var CatXmlView $l10nmgrGetXML */
                $l10nmgrGetXML = GeneralUtility::makeInstance(CatXmlView::class, $l10nmgrCfgObj, $targetLanguageId);
                if ($input->hasOption('baseUrl')) {
                    $baseUrl = $input->getOption('baseUrl');
                    $baseUrl = rtrim($baseUrl, '/') . '/';
                    $l10nmgrGetXML->setBaseUrl($baseUrl);
                }
                $l10nmgrGetXML->setOverrideParams(
                    [
                        'noxmlcheck' => !$input->getOption('checkXml'),
                        'utf8' => (bool)$input->getOption('utf8'),
                    ]
                );
            } elseif ($format === 'EXCEL') {
                $l10nmgrGetXML = GeneralUtility::makeInstance(ExcelXmlView::class, $l10nmgrCfgObj, $targetLanguageId);
            } else {
                throw new Exception("Wrong format. Use 'CATXML' or 'EXCEL'");
            }

            $forceLanguage = $input->getOption('forcedSourceLanguage');
            if (is_string($forceLanguage)) {
                $l10nmgrGetXML->setForcedSourceLanguage((int)$forceLanguage);
            }
            $onlyChanged = $input->getOption('updated');
            if ($onlyChanged) {
                $l10nmgrGetXML->setModeOnlyChanged();
            }
            $onlyNew = $input->getOption('new');
            if ($onlyNew) {
                $l10nmgrGetXML->setModeOnlyNew();
            }
            $noHidden = $input->getOption('noHidden');
            if ($noHidden) {
                $l10nmgrGetXML->setModeNoHidden();
            }
            $customer = $input->getOption('customer');
            if ($customer) {
                $l10nmgrGetXML->setCustomer($customer);
                // If not set, customer set by CLI backend user name will give a default value for CLI based exports
            }
            // If the check for already exported content is enabled, run the ckeck.
            $checkExportsCli = $input->getOption('check-exports');
            $checkExports = $l10nmgrGetXML->checkExports();
            if ($checkExportsCli && !$checkExports) {
                $output->writeln('<error>' . $this->getLanguageService()->getLL('export.process.duplicate.title') . ' ' . $this->getLanguageService()->getLL('export.process.duplicate.message') . LF . '</error>');
                $output->writeln('<error>' . $l10nmgrGetXML->renderExportsCli() . LF . '</error>');
            } else {
                // Save export to XML file
                $xmlFileName = Environment::getPublicPath() . '/' . $l10nmgrGetXML->render();
                $l10nmgrGetXML->saveExportInformation();
                // If email notification is set send export files to responsible translator
                if ($this->emConfiguration->isEnableNotification()) {
                    if (empty($this->emConfiguration->getEmailRecipient())) {
                        $output->writeln('<error>' . $this->getLanguageService()->getLL('error.email.repient_missing.msg') . '</error>');
                    } else {
                        /** @var NotificationService $notificationService */
                        $notificationService = GeneralUtility::makeInstance(NotificationService::class);
                        $notificationService->sendMail($xmlFileName, $l10nmgrCfgObj, $targetLanguageId, $this->emConfiguration);
                    }
                } else {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.email.notification_disabled.msg') . '</error>');
                }
                // If FTP option is set, upload files to remote server
                if ($this->emConfiguration->isEnableFtp()) {
                    if (file_exists($xmlFileName)) {
                        $error .= $this->ftpUpload($xmlFileName, $l10nmgrGetXML->getFilename());
                    } else {
                        $output->writeln('<error>' . $this->getLanguageService()->getLL('error.ftp.file_not_found.msg') . '</error>');
                    }
                } else {
                    $output->writeln('<error>' . $this->getLanguageService()->getLL('error.ftp.disabled.msg') . '</error>');
                }
                if ($this->emConfiguration->isEnableNotification() === false && $this->emConfiguration->isEnableFtp() === false) {
                    $output->writeln(sprintf(
                        $this->getLanguageService()->getLL('export.file_saved.msg'),
                        $xmlFileName
                    ));
                }
            }
        } else {
            $error .= $this->getLanguageService()->getLL('error.l10nmgr.object_not_loaded.msg') . "\n";
        }
        return $error;
    }

    /**
     * The function ftpUpload puts an export on a remote FTP server for further processing
     *
     * @param string $xmlFileName Path to the file to upload
     * @param string $filename Name of the file to upload to
     *
     * @return string Error message
     */
    protected function ftpUpload(string $xmlFileName, string $filename): string
    {
        $error = '';
        $connection = ftp_connect($this->emConfiguration->getFtpServer()) or die('Connection failed');
        if (@ftp_login(
            $connection,
            $this->emConfiguration->getFtpServerUsername(),
            $this->emConfiguration->getFtpServerPassword()
        )) {
            if (ftp_put(
                $connection,
                $this->emConfiguration->getFtpServerPath() . $filename,
                $xmlFileName,
                FTP_BINARY
            )) {
                ftp_close($connection) or die("Couldn't close connection");
            } else {
                $error .= sprintf(
                    $this->getLanguageService()->getLL('error.ftp.connection.msg'),
                    $this->emConfiguration->getFtpServerPath(),
                    $filename
                ) . "\n";
            }
        } else {
            $error .= sprintf(
                $this->getLanguageService()->getLL('error.ftp.connection_user.msg'),
                $this->emConfiguration->getFtpServerUsername()
            ) . "\n";
            ftp_close($connection) or die("Couldn't close connection");
        }
        return $error;
    }
}
