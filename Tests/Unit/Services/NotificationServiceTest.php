<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\NotificationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class NotificationServiceTest extends UnitTestCase
{
    private const string TEST_BASE_PATH = 'typo3temp/var/tests/l10nmgr-notificationservice/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'] = self::TEST_BASE_PATH;
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        $absoluteTestBasePath = Environment::getPublicPath() . '/' . self::TEST_BASE_PATH;
        if (is_dir($absoluteTestBasePath)) {
            GeneralUtility::rmdir($absoluteTestBasePath, true);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function createEmConfiguration(array $overrides = []): EmConfiguration
    {
        return new EmConfiguration(array_merge([
            'email_recipient' => 'recipient@example.com',
            'email_sender' => 'sender@example.com',
            'email_sender_name' => 'L10nmgr',
            'email_attachment' => 0,
        ], $overrides));
    }

    private function createL10nConfiguration(): L10nConfiguration
    {
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = ['pid' => 1, 'sourceLangStaticId' => 0];
        return $l10nConfiguration;
    }

    private function createSiteStub(): Site
    {
        $sourceLocale = $this->createStub(Locale::class);
        $sourceLocale->method('getLanguageCode')->willReturn('en');
        $sourceLanguage = $this->createStub(SiteLanguage::class);
        $sourceLanguage->method('getLocale')->willReturn($sourceLocale);

        $targetLocale = $this->createStub(Locale::class);
        $targetLocale->method('getLanguageCode')->willReturn('de');
        $targetLanguage = $this->createStub(SiteLanguage::class);
        $targetLanguage->method('getLocale')->willReturn($targetLocale);

        $site = $this->createStub(Site::class);
        $site->method('getLanguageById')->willReturnMap([
            [0, $sourceLanguage],
            [1, $targetLanguage],
        ]);

        return $site;
    }

    #[Test]
    public function sendMailStillSendsWithASingleEmptyRecipientWhenNoneIsConfigured(): void
    {
        // Characterization of a real, currently-live surprise (found while writing this test, not
        // introduced by it - not fixed here, out of scope for a coverage pass): the intent of
        // `if (count($recipients) > 0)` is clearly "skip sending when no recipient is configured",
        // but $recipients comes from `GeneralUtility::trimExplode(',', $emConfiguration->getEmailRecipient())`
        // called WITHOUT $removeEmptyValues (defaults to false). explode(',', '') returns [''], a
        // one-element array containing an empty string, not []. So count($recipients) is always at
        // least 1 - the guard never actually skips anything, and sendMail() always proceeds to
        // resolve the site/languages and call MailMessage::send(), even with an empty recipient list.
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->createSiteStub());

        $mailMessage = $this->createMock(MailMessage::class);
        $mailMessage->expects(self::once())->method('setTo')->with(['']);
        $mailMessage->expects(self::once())->method('send');

        $subject = new NotificationService($siteFinder, $mailMessage);
        $subject->sendMail(
            'export.xml',
            $this->createL10nConfiguration(),
            1,
            $this->createEmConfiguration(['email_recipient' => ''])
        );
    }

    #[Test]
    public function sendMailSendsToAllConfiguredRecipientsWithResolvedLanguageCodes(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->createSiteStub());

        $mailMessage = $this->createMock(MailMessage::class);
        $mailMessage->expects(self::once())->method('setFrom')->with(['sender@example.com' => 'L10nmgr']);
        $mailMessage->expects(self::once())->method('setTo')->with(['first@example.com', 'second@example.com']);
        $mailMessage->expects(self::once())->method('send');
        $mailMessage->expects(self::never())->method('attachFromPath');

        $subject = new NotificationService($siteFinder, $mailMessage);
        $subject->sendMail(
            'export.xml',
            $this->createL10nConfiguration(),
            1,
            $this->createEmConfiguration(['email_recipient' => 'first@example.com,second@example.com'])
        );
    }

    #[Test]
    public function sendMailUsesTheAttachedFileMessageVariantAndAttachesTheFileWhenEmailAttachmentIsEnabled(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->createSiteStub());

        $mailMessage = $this->createMock(MailMessage::class);
        $mailMessage->expects(self::once())->method('attachFromPath');
        $mailMessage->expects(self::once())->method('text')->with(self::callback(
            static fn (string $message): bool => str_contains($message, 'email.new_translation_job_attached.msg')
                && !str_contains($message, 'email.new_translation_job.msg' . "\n")
        ));

        $subject = new NotificationService($siteFinder, $mailMessage);
        $subject->sendMail(
            'export.xml',
            $this->createL10nConfiguration(),
            1,
            $this->createEmConfiguration(['email_attachment' => 1])
        );
    }

    #[Test]
    public function sendMailUsesTheNormalMessageVariantWhenEmailAttachmentIsDisabled(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->createSiteStub());

        $mailMessage = $this->createMock(MailMessage::class);
        $mailMessage->expects(self::once())->method('text')->with(self::callback(
            static fn (string $message): bool => str_contains($message, 'email.new_translation_job.msg')
                && !str_contains($message, 'email.new_translation_job_attached.msg')
        ));

        $subject = new NotificationService($siteFinder, $mailMessage);
        $subject->sendMail(
            'export.xml',
            $this->createL10nConfiguration(),
            1,
            $this->createEmConfiguration(['email_attachment' => 0])
        );
    }
}
