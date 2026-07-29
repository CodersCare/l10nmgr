<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers only the importAsDefaultLanguage getter/setter - the one part of this 979-line class with
 * no database/DataHandler dependency at all. saveTranslation()/translateContentOnPage() and the
 * _submitContentAs*AndGetFlexFormDiff() methods drive real DataHandler-backed record writes against
 * TCA-described content structures; that's the actual future refactor target on ea_14-0 and needs
 * dedicated, much larger fixture work than a coverage pass affords - deferred rather than rushed,
 * alongside TranslationDetailsService/FlexFormService (see backlog).
 */
class L10nBaseServiceTest extends UnitTestCase
{
    private function createSubject(): L10nBaseService
    {
        return new L10nBaseService(new EmConfiguration(['enable_ftp' => 0]));
    }

    #[Test]
    public function importAsDefaultLanguageDefaultsToFalse(): void
    {
        self::assertFalse($this->createSubject()->getImportAsDefaultLanguage());
    }

    #[Test]
    public function setImportAsDefaultLanguageChangesTheGetterResult(): void
    {
        $subject = $this->createSubject();

        $subject->setImportAsDefaultLanguage(true);

        self::assertTrue($subject->getImportAsDefaultLanguage());
    }
}
