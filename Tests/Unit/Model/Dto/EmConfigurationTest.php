<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\BaseTestCase;

class EmConfigurationTest extends BaseTestCase
{
    protected EmConfiguration $subject;

    protected function setUp(): void
    {
        $configuration = [
            'enable_hidden_languages' => 0,
            'enable_notification' => 0,
            'enable_customername' => 0,
            'enable_ftp' => 0,
            'enable_neverHideAtCopy' => 1,
            'disallowDoktypes' => '255, ---div---',
            'import_dontProcessTransformations' => 1,
            'l10nmgr_cfg' => '',
            'l10nmgr_tlangs' => '',
            'email_recipient' => '',
            'email_recipient_import' => '',
            'email_sender' => '',
            'email_sender_name' => '',
            'email_sender_organisation' => '',
            'email_attachment' => 0,
            'ftp_server' => '',
            'ftp_server_path' => '',
            'ftp_server_downpath' => '',
            'ftp_server_username' => '',
            'ftp_server_password' => '',
            'service_children' => 3,
            'service_user' => '',
            'service_pwd' => '',
            'service_enc' => '',
        ];

        $this->subject = new EmConfiguration($configuration);
    }

    #[Test]
    public function enableHiddenLanguages(): void
    {
        self::assertFalse($this->subject->isEnableHiddenLanguages());
    }

    #[Test]
    public function enableNotificationIsSetAndReturnsCorrectValue(): void
    {
        self::assertFalse($this->subject->isEnableNotification());
    }

    #[Test]
    public function enableCustomernameIsSetAndReturnsCorrectValue(): void
    {
        self::assertFalse($this->subject->isEnableCustomername());
    }

    #[Test]
    public function enableFtpIsSetAndReturnsCorrectValue(): void
    {
        self::assertFalse($this->subject->isEnableFtp());
    }

    #[Test]
    public function enableNeverHideAtCopyIsSetAndReturnsCorrectValue(): void
    {
        self::assertTrue($this->subject->isEnableNeverHideAtCopy());
    }

    #[Test]
    public function disallowDoktypesIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('255, ---div---', $this->subject->getDisallowDoktypes());
    }

    #[Test]
    public function importDontProcessTransformationsIsSetAndReturnsCorrectValue(): void
    {
        self::assertTrue($this->subject->isImportDontProcessTransformations());
    }

    #[Test]
    public function l10NmgrCfgIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getL10NmgrCfg());
    }

    #[Test]
    public function l10NmgrTlangsIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getL10NmgrTlangs());
    }

    #[Test]
    public function emailRecipientIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getEmailRecipient());
    }

    #[Test]
    public function emailRecipientImportIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getEmailRecipientImport());
    }

    #[Test]
    public function emailSenderIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getEmailSender());
    }

    #[Test]
    public function emailSenderNameIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getEmailSenderName());
    }

    #[Test]
    public function emailSenderOrganisationIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getEmailSenderOrganisation());
    }

    #[Test]
    public function emailAttachmentIsSetAndReturnsCorrectValue(): void
    {
        self::assertFalse($this->subject->isEmailAttachment());
    }

    #[Test]
    public function ftpServerIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getFtpServer());
    }

    #[Test]
    public function ftpServerPathIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getFtpServerPath());
    }

    #[Test]
    public function ftpServerDownPathIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getFtpServerDownPath());
    }

    #[Test]
    public function ftpServerUsernameIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getFtpServerUsername());
    }

    #[Test]
    public function ftpServerPasswordIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getFtpServerPassword());
    }

    #[Test]
    public function serviceChildrenIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals(3, $this->subject->getServiceChildren());
    }

    #[Test]
    public function serviceUserIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getServiceUser());
    }

    #[Test]
    public function servicePwdIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getServicePwd());
    }

    #[Test]
    public function serviceEncIsSetAndReturnsCorrectValue(): void
    {
        self::assertEquals('', $this->subject->getServiceEnc());
    }

    /**
     * @return array[]
     */
    public static function ftpCredentialsDataProvider(): array
    {
        return [
            'No FTP-Credentials given returns false' => [
                false,
                ['', '', ''],
            ],
            'only FTP UserName given returns false' => [
                false,
                ['', 'username', ''],
            ],
            'only FTP Password given returns false' => [
                false,
                ['', '', 'password'],
            ],
            'only FTP Server given returns false' => [
                false,
                ['server', '', ''],
            ],
            'all FTP-Credentials given given returns true' => [
                true,
                ['server', 'username', 'password'],
            ],

        ];
    }

    #[Test]
    #[DataProvider('ftpCredentialsDataProvider')]
    public function hasFtpCredentialsCalculatesCorrectValue($expected, $input): void
    {
        $configuration = [
            'ftp_server' => $input[0],
            'ftp_server_username' => $input[1],
            'ftp_server_password' => $input[2],
        ];

        $this->subject = new EmConfiguration($configuration);

        self::assertEquals($expected, $this->subject->hasFtpCredentials());
    }
}
