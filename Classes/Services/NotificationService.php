<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Services;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Traits\LanguageServiceTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NotificationService
{
    use LanguageServiceTrait;

    public function __construct(
        protected readonly SiteFinder $siteFinder,
        protected readonly MailMessage $mailMessage
    ) {
    }

    /**
     * The function emailNotification sends an email with a translation job to the recipient specified in the extension
     * config.
     *
     * @param string $xmlFileName Name of the XML file
     * @param L10nConfiguration $l10nmgrCfgObj L10N Manager configuration object
     * @param int $targetLanguageId ID of the language to translate to
     * @throws SiteNotFoundException
     */
    public function sendMail(string $xmlFileName, L10nConfiguration $l10nmgrCfgObj, int $targetLanguageId, EmConfiguration $emConfiguration): void
    {
        // If at least a recipient is indeed defined, proceed with sending the mail
        $recipients = GeneralUtility::trimExplode(',', $emConfiguration->getEmailRecipient());
        if (count($recipients) > 0) {
            $jobsOutPath = Environment::getPublicPath() . '/uploads/tx_l10nmgr/jobs/out/';
            if (!is_dir(GeneralUtility::getFileAbsFileName($jobsOutPath))) {
                GeneralUtility::mkdir_deep($jobsOutPath);
            }
            $fullFilename = $jobsOutPath . $xmlFileName;

            // Get source & target language ISO codes
            $site = $this->siteFinder->getSiteByPageId($l10nmgrCfgObj->getPid());
            $targetLang = $site->getLanguageById($targetLanguageId)->getLocale()->getLanguageCode();
            $sourceLang = $site
                            ->getLanguageById((int)($l10nmgrCfgObj->l10ncfg['sourceLangStaticId'] ?? 0))
                            ->getLocale()
                            ->getLanguageCode();

            // Collect mail data
            $fromMail = $emConfiguration->getEmailSender();
            $fromName = $emConfiguration->getEmailSenderName();
            $subject = sprintf(
                $this->getLanguageService()->getLL('email.subject.msg'),
                $sourceLang,
                $targetLang,
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''
            );
            // Assemble message body
            $message = [
                'msg1' => $this->getLanguageService()->getLL('email.greeting.msg'),
                'msg2' => '',
                'msg3' => sprintf(
                    $this->getLanguageService()->getLL('email.new_translation_job.msg'),
                    $sourceLang,
                    $targetLang,
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''
                ),
                'msg4' => $this->getLanguageService()->getLL('email.info.msg'),
                'msg5' => $this->getLanguageService()->getLL('email.info.import.msg'),
                'msg6' => '',
                'msg7' => $this->getLanguageService()->getLL('email.goodbye.msg'),
                'msg8' => $fromName,
                'msg9' => '--',
                'msg10' => $this->getLanguageService()->getLL('email.info.exported_file.msg'),
                'msg11' => $xmlFileName,
            ];
            if ($emConfiguration->isEmailAttachment()) {
                $message['msg3'] = sprintf(
                    $this->getLanguageService()->getLL('email.new_translation_job_attached.msg'),
                    $sourceLang,
                    $targetLang,
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''
                );
            }
            $msg = implode(chr(10), $message);
            // Instantiate the mail object, set all necessary properties and send the mail
            $this->mailMessage->setFrom([$fromMail => $fromName]);
            $this->mailMessage->setTo($recipients);
            $this->mailMessage->setSubject($subject);
            $this->mailMessage->text($msg);
            if ($emConfiguration->isEmailAttachment()) {
                $this->mailMessage->attachFromPath($fullFilename);
            }
            $this->mailMessage->send();
        }
    }
}
