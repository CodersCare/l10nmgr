<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\LanguageRestriction\LanguageRestrictionRegistry;
use Localizationteam\L10nmgr\LanguagesService;
use Localizationteam\L10nmgr\Utility\L10nmgrExtensionManagementUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class L10nmgrExtensionManagementUtilityTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['TCA']['tt_content']);
        // LanguageRestrictionRegistry::getInstance() resolves through GeneralUtility::makeInstance(),
        // which only autowires constructor dependencies via a booted DI container - not available in
        // a plain unit test. Pre-registering the singleton mirrors how the real container would supply it.
        GeneralUtility::setSingletonInstance(LanguageRestrictionRegistry::class, new LanguageRestrictionRegistry(new LanguagesService()));
    }

    #[Test]
    public function makeTranslationsRestrictableRegistersTableInTheSharedRegistry(): void
    {
        $GLOBALS['TCA']['tt_content']['columns'] = [];

        L10nmgrExtensionManagementUtility::makeTranslationsRestrictable('some_extension', 'tt_content');

        self::assertTrue(
            GeneralUtility::makeInstance(LanguageRestrictionRegistry::class)->isRegistered('tt_content'),
            'the registry used by the utility must be the same shared singleton instance queried afterward'
        );
    }

    #[Test]
    public function makeTranslationsRestrictableDoesNotThrowWhenTcaIsNotYetLoadedForTheTable(): void
    {
        // tt_content has no TCA columns configured here, so LanguageRestrictionRegistry::add()
        // cannot apply the TCA change and returns false; the utility only logs a warning in that
        // case, it must not raise an exception.
        L10nmgrExtensionManagementUtility::makeTranslationsRestrictable('some_extension', 'tt_content');

        self::assertTrue(
            GeneralUtility::makeInstance(LanguageRestrictionRegistry::class)->isRegistered('tt_content'),
            'the registry entry is still recorded even though the TCA columns could not be extended yet'
        );
    }
}
