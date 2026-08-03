<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class L10nBaseServiceDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    private function createSubject(): L10nBaseService
    {
        return new L10nBaseService(new EmConfiguration(['enable_ftp' => 0]));
    }

    private function invokeRemap(L10nBaseService $subject, L10nConfiguration $l10ncfgObj, TranslationData $translationData): void
    {
        (new \ReflectionMethod($subject, 'remapInputDataForExistingTranslations'))
            ->invoke($subject, $l10ncfgObj, $translationData);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function rewritesNewFieldKeyToTheRealUidOfAnAlreadyExistingTranslation(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(true);
        $translationData = new TranslationData();
        $translationData->setTranslationData([
            'tt_content' => [10 => ['tt_content:NEW/1/10:header' => 'German Translation']],
        ]);

        $this->invokeRemap($subject, $l10ncfgObj, $translationData);

        self::assertSame(
            ['tt_content' => [10 => ['tt_content:11:header' => 'German Translation']]],
            $translationData->getTranslationData()
        );
    }

    #[Test]
    public function leavesNewFieldKeyUnchangedWhenNoTranslationExistsYet(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(true);
        $translationData = new TranslationData();
        $inputData = ['tt_content' => [10 => ['tt_content:NEW/2/10:header' => 'French Translation']]];
        $translationData->setTranslationData($inputData);

        $this->invokeRemap($subject, $l10ncfgObj, $translationData);

        self::assertSame($inputData, $translationData->getTranslationData());
    }
}
