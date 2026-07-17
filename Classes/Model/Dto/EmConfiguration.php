<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Model\Dto;

use Exception;
use ReflectionNamedType;
use ReflectionProperty;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class EmConfiguration
{
    protected bool $enable_hidden_languages = false;

    // Enable settings
    protected bool $enable_notification = false;

    protected bool $enable_customername = false;

    protected bool $enable_ftp = false;

    protected bool $enable_neverHideAtCopy = true;

    protected string $disallowDoktypes = '255, ---div---';

    protected bool $import_dontProcessTransformations = true;

    protected string $l10nmgr_cfg = '';

    // Load L10N manager configration
    protected string $l10nmgr_tlangs = '';

    protected string $email_recipient = '';

    // Define email notification
    protected string $email_recipient_import = '';

    protected string $email_sender = '';

    protected string $email_sender_name = '';

    protected string $email_sender_organisation = '';

    protected bool $email_attachment = false;

    protected string $ftp_server = '';

    // Define FTP server details
    protected string $ftp_server_path = '';

    protected string $ftp_server_downpath = '';

    protected string $ftp_server_username = '';

    protected string $ftp_server_password = '';

    protected int $service_children = 3;

    // Import service
    protected string $service_user = '';

    protected string $service_pwd = '';

    protected string $service_enc = '';

    public function __construct(array $configuration = [])
    {
        if (empty($configuration)) {
            try {
                $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
                $configuration = $extensionConfiguration->get('l10nmgr');
            } catch (Exception $exception) {
                // do nothing
            }
        }

        foreach ($configuration as $key => $value) {
            if (property_exists(__CLASS__, $key)) {
                $property = new ReflectionProperty($this, $key);
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType) {
                    $typeName = $type->getName();
                    settype($value, $typeName);
                }
                $this->$key = $value;
            }
        }
    }

    public function isEnableHiddenLanguages(): bool
    {
        return $this->enable_hidden_languages;
    }

    public function isEnableNotification(): bool
    {
        return $this->enable_notification;
    }

    public function isEnableCustomername(): bool
    {
        return $this->enable_customername;
    }

    public function isEnableFtp(): bool
    {
        return $this->enable_ftp;
    }

    public function isEnableNeverHideAtCopy(): bool
    {
        return $this->enable_neverHideAtCopy;
    }

    public function getDisallowDoktypes(): string
    {
        return $this->disallowDoktypes;
    }

    public function isImportDontProcessTransformations(): bool
    {
        return $this->import_dontProcessTransformations;
    }

    public function getL10NmgrCfg(): string
    {
        return $this->l10nmgr_cfg;
    }

    public function getL10NmgrTlangs(): string
    {
        return $this->l10nmgr_tlangs;
    }

    public function getEmailRecipient(): string
    {
        return $this->email_recipient;
    }

    public function getEmailRecipientImport(): string
    {
        return $this->email_recipient_import;
    }

    public function getEmailSender(): string
    {
        return $this->email_sender;
    }

    public function getEmailSenderName(): string
    {
        return $this->email_sender_name;
    }

    public function getEmailSenderOrganisation(): string
    {
        return $this->email_sender_organisation;
    }

    public function isEmailAttachment(): bool
    {
        return $this->email_attachment;
    }

    public function getFtpServerPath(): string
    {
        return $this->ftp_server_path;
    }

    public function getFtpServerDownPath(): string
    {
        return $this->ftp_server_downpath;
    }

    public function getServiceChildren(): int
    {
        return $this->service_children;
    }

    public function getServiceUser(): string
    {
        return $this->service_user;
    }

    public function getServicePwd(): string
    {
        return $this->service_pwd;
    }

    public function getServiceEnc(): string
    {
        return $this->service_enc;
    }

    public function hasFtpCredentials(): bool
    {
        return
            !empty($this->getFtpServer())
            && !empty($this->getFtpServerUsername())
            && !empty($this->getFtpServerPassword());
    }

    public function getFtpServer(): string
    {
        return $this->ftp_server;
    }

    public function getFtpServerUsername(): string
    {
        return $this->ftp_server_username;
    }

    public function getFtpServerPassword(): string
    {
        return $this->ftp_server_password;
    }
}
