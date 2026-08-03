<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nAccumulatedInformation;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class L10nBaseServiceTest extends UnitTestCase
{
    private function createSubject(): L10nBaseService
    {
        return new L10nBaseService(new EmConfiguration(['enable_ftp' => 0]));
    }

    private function createSubjectWithStubbedSubmit(mixed $submitReturns): L10nBaseService
    {
        return new class(new EmConfiguration(['enable_ftp' => 0]), $submitReturns) extends L10nBaseService {
            public function __construct(EmConfiguration $emConfiguration, private readonly mixed $submitReturns)
            {
                parent::__construct($emConfiguration);
            }

            protected function _submitContentAndGetFlexFormDiff(array $accum, array $inputArray): mixed
            {
                return $this->submitReturns;
            }
        };
    }

    private function createSubjectWithStubbedSubmitVariants(): L10nBaseService
    {
        return new class(new EmConfiguration(['enable_ftp' => 0])) extends L10nBaseService {
            protected function _submitContentAsDefaultLanguageAndGetFlexFormDiff(array $accum, array $inputArray): array
            {
                return ['variant' => 'default'];
            }

            protected function _submitContentAsTranslatedLanguageAndGetFlexFormDiff(array $accum, array $inputArray): array
            {
                return ['variant' => 'translated'];
            }
        };
    }

    private function invokeSubmitDispatcher(L10nBaseService $subject): mixed
    {
        return (new \ReflectionMethod($subject, '_submitContentAndGetFlexFormDiff'))->invoke($subject, [], []);
    }

    private function createAccumulatedInformationStub(): L10nAccumulatedInformation
    {
        $accumObj = self::createStub(L10nAccumulatedInformation::class);
        $accumObj->method('getInfoArray')->willReturn([]);

        return $accumObj;
    }

    private function createConfigurationStub(L10nAccumulatedInformation $accumObj): L10nConfiguration
    {
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(false);
        $l10ncfgObj->method('getL10nAccumulatedInformationsObjectForLanguage')->willReturn($accumObj);

        return $l10ncfgObj;
    }

    private function createTranslationData(int $sysLang = 1, int $previewLanguage = 0): TranslationData
    {
        $translationData = new TranslationData();
        $translationData->setLanguage($sysLang);
        $translationData->setPreviewLanguage($previewLanguage);
        $translationData->setTranslationData(['tt_content' => [5 => ['tt_content:5/1/0:header' => 'Hello']]]);

        return $translationData;
    }

    #[Test]
    public function importAsDefaultLanguageDefaultsToFalse(): void
    {
        self::assertFalse($this->createSubject()->getImportAsDefaultLanguage());
    }

    #[Test]
    public function submitContentAndGetFlexFormDiffDispatchesToTheDefaultLanguageVariantWhenEnabled(): void
    {
        $subject = $this->createSubjectWithStubbedSubmitVariants();
        $subject->setImportAsDefaultLanguage(true);

        self::assertSame(['variant' => 'default'], $this->invokeSubmitDispatcher($subject));
    }

    #[Test]
    public function submitContentAndGetFlexFormDiffDispatchesToTheTranslatedLanguageVariantByDefault(): void
    {
        $subject = $this->createSubjectWithStubbedSubmitVariants();

        self::assertSame(['variant' => 'translated'], $this->invokeSubmitDispatcher($subject));
    }

    #[Test]
    public function setImportAsDefaultLanguageChangesTheGetterResult(): void
    {
        $subject = $this->createSubject();

        $subject->setImportAsDefaultLanguage(true);

        self::assertTrue($subject->getImportAsDefaultLanguage());
    }

    #[Test]
    public function saveTranslationResetsLastSaveErrorsAndTargetLanguageIdOnEachCall(): void
    {
        $subject = $this->createSubjectWithStubbedSubmit(['some' => 'diff']);
        (new \ReflectionProperty($subject, 'lastSaveErrors'))->setValue($subject, ['a stale error from a previous call']);
        (new \ReflectionProperty(L10nBaseService::class, 'targetLanguageID'))->setValue(null, 99);
        $translationData = $this->createTranslationData();
        $l10ncfgObj = $this->createConfigurationStub($this->createAccumulatedInformationStub());

        $subject->saveTranslation($l10ncfgObj, $translationData);

        self::assertSame([], $subject->getLastSaveErrors());
        self::assertSame(0, L10nBaseService::getTargetLanguageID());
    }

    #[Test]
    public function saveTranslationUpdatesFlexFormDiffOnTheConfigurationWhenSubmitReturnsAnArray(): void
    {
        $flexFormDiff = ['field' => 'diff-value'];
        $subject = $this->createSubjectWithStubbedSubmit($flexFormDiff);
        $translationData = $this->createTranslationData(sysLang: 1);
        $l10ncfgObj = $this->createMock(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(false);
        $l10ncfgObj->method('getL10nAccumulatedInformationsObjectForLanguage')
            ->willReturn($this->createAccumulatedInformationStub());
        $l10ncfgObj->expects(self::once())->method('updateFlexFormDiff')->with(1, $flexFormDiff);

        $subject->saveTranslation($l10ncfgObj, $translationData);
    }

    #[Test]
    public function saveTranslationDoesNotUpdateFlexFormDiffWhenSubmitReturnsFalse(): void
    {
        $subject = $this->createSubjectWithStubbedSubmit(false);
        $translationData = $this->createTranslationData();
        $l10ncfgObj = $this->createMock(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(false);
        $l10ncfgObj->method('getL10nAccumulatedInformationsObjectForLanguage')
            ->willReturn($this->createAccumulatedInformationStub());
        $l10ncfgObj->expects(self::never())->method('updateFlexFormDiff');

        $subject->saveTranslation($l10ncfgObj, $translationData);
    }

    #[Test]
    public function saveTranslationUpdatesFlexFormDiffWhenSubmitReturnsAnEmptyArray(): void
    {
        $subject = $this->createSubjectWithStubbedSubmit([]);
        $translationData = $this->createTranslationData();
        $l10ncfgObj = $this->createMock(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(false);
        $l10ncfgObj->method('getL10nAccumulatedInformationsObjectForLanguage')
            ->willReturn($this->createAccumulatedInformationStub());
        $l10ncfgObj->expects(self::once())->method('updateFlexFormDiff')->with($translationData->getLanguage(), []);

        $subject->saveTranslation($l10ncfgObj, $translationData);
    }

    #[Test]
    public function saveTranslationInvokesSavePreAndPostProcessHooksInOrderWithTheExpectedArguments(): void
    {
        $flexFormDiff = ['field' => 'diff-value'];
        $subject = $this->createSubjectWithStubbedSubmit($flexFormDiff);
        $translationData = $this->createTranslationData();
        $l10ncfgObj = $this->createConfigurationStub($this->createAccumulatedInformationStub());

        $callOrder = [];
        $preProcessHook = new class($callOrder) {
            private array $callOrder;

            public array $calls = [];

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function processBeforeSaving($l10ncfgObj, $translationObj, $service): void
            {
                $this->calls[] = [$l10ncfgObj, $translationObj, $service];
                $this->callOrder[] = 'pre';
            }
        };
        $postProcessHook = new class($callOrder) {
            private array $callOrder;

            public array $calls = [];

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function processAfterSaving($l10ncfgObj, $translationObj, $flexFormDiffArray, $service): void
            {
                $this->calls[] = [$l10ncfgObj, $translationObj, $flexFormDiffArray, $service];
                $this->callOrder[] = 'post';
            }
        };
        GeneralUtility::addInstance($preProcessHook::class, $preProcessHook);
        GeneralUtility::addInstance($postProcessHook::class, $postProcessHook);
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'] = [$preProcessHook::class];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'] = [$postProcessHook::class];

        try {
            $subject->saveTranslation($l10ncfgObj, $translationData);
        } finally {
            unset(
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'],
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess']
            );
        }

        self::assertSame(['pre', 'post'], $callOrder);
        self::assertCount(1, $preProcessHook->calls);
        self::assertSame([$l10ncfgObj, $translationData, $subject], $preProcessHook->calls[0]);
        self::assertCount(1, $postProcessHook->calls);
        self::assertSame([$l10ncfgObj, $translationData, $flexFormDiff, $subject], $postProcessHook->calls[0]);
    }

    #[Test]
    public function remapInputDataForExistingTranslationsDoesNothingWhenOverrideIsNotEnabled(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(false);
        $translationData = $this->createMock(TranslationData::class);
        $translationData->expects(self::never())->method('getTranslationData');
        $translationData->expects(self::never())->method('setTranslationData');

        (new \ReflectionMethod($subject, 'remapInputDataForExistingTranslations'))
            ->invoke($subject, $l10ncfgObj, $translationData);
    }

    #[Test]
    public function remapInputDataForExistingTranslationsLeavesFieldKeysWithARealUidUnchanged(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(true);
        $translationData = new TranslationData();
        $inputData = ['tt_content' => [5 => ['tt_content:11/1/10:header' => 'German Translation']]];
        $translationData->setTranslationData($inputData);

        (new \ReflectionMethod($subject, 'remapInputDataForExistingTranslations'))
            ->invoke($subject, $l10ncfgObj, $translationData);

        self::assertSame($inputData, $translationData->getTranslationData());
    }
}
