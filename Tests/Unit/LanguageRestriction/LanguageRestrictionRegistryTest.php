<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use InvalidArgumentException;
use Localizationteam\L10nmgr\LanguageRestriction\LanguageRestrictionRegistry;
use Localizationteam\L10nmgr\LanguagesService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class LanguageRestrictionRegistryTest extends UnitTestCase
{
    protected LanguageRestrictionRegistry $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new LanguageRestrictionRegistry(new LanguagesService());
        unset($GLOBALS['TCA']['tt_content']);
    }

    #[Test]
    public function addWithEmptyTableNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1540460445);

        $this->subject->add('some_extension', '');
    }

    #[Test]
    public function addWithEmptyExtensionKeyThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1540460446);

        $this->subject->add('', 'tt_content');
    }

    #[Test]
    public function isRegisteredReturnsFalseForUnknownTable(): void
    {
        self::assertFalse($this->subject->isRegistered('tt_content'));
    }

    #[Test]
    public function addWithoutTcaColumnsRegistersFieldButReturnsFalse(): void
    {
        $didApplyTca = $this->subject->add('some_extension', 'tt_content');

        self::assertFalse($didApplyTca, 'no TCA columns exist yet for tt_content, so TCA could not have been applied');
        self::assertTrue($this->subject->isRegistered('tt_content'), 'the registry entry itself is still recorded regardless of TCA availability');
    }

    #[Test]
    public function addAppliesTcaColumnWhenTableIsAlreadyLoadedInTca(): void
    {
        $GLOBALS['TCA']['tt_content']['columns'] = [];

        $didApplyTca = $this->subject->add('some_extension', 'tt_content');

        self::assertTrue($didApplyTca);
        self::assertTrue($this->subject->isRegistered('tt_content'));
        self::assertArrayHasKey('l10nmgr_language_restriction', $GLOBALS['TCA']['tt_content']['columns']);
    }

    #[Test]
    public function addWithOverrideTrueReplacesPreviouslyRegisteredOptions(): void
    {
        $GLOBALS['TCA']['tt_content']['columns'] = [];

        $this->subject->add('some_extension', 'tt_content', options: ['label' => 'First label']);
        $this->subject->add('some_extension', 'tt_content', options: ['label' => 'Second label'], override: true);

        /** @phpstan-ignore-next-line */
        self::assertSame('Second label', $GLOBALS['TCA']['tt_content']['columns']['l10nmgr_language_restriction']['label']);
    }

    #[Test]
    public function addWithOverrideFalseKeepsPreviouslyRegisteredOptions(): void
    {
        $GLOBALS['TCA']['tt_content']['columns'] = [];

        $this->subject->add('some_extension', 'tt_content', options: ['label' => 'First label']);
        $this->subject->add('some_extension', 'tt_content', options: ['label' => 'Second label'], override: false);

        /** @phpstan-ignore-next-line */
        self::assertSame('First label', $GLOBALS['TCA']['tt_content']['columns']['l10nmgr_language_restriction']['label']);
    }

    #[Test]
    public function getLanguageRestrictableTablesReturnsAllRegisteredTableNames(): void
    {
        $this->subject->add('some_extension', 'tt_content');
        $this->subject->add('some_extension', 'pages');

        self::assertSame(['tt_content', 'pages'], $this->subject->getLanguageRestrictableTables());
    }

    #[Test]
    public function registerFieldAndGetExtensionKeysRoundTrip(): void
    {
        self::assertSame([], $this->subject->getExtensionKeys());

        $this->subject->registerField('some_extension', 'tt_content');

        self::assertSame(['some_extension'], $this->subject->getExtensionKeys());
    }

    #[Test]
    public function getDatabaseTableDefinitionReturnsEmptyStringForUnknownExtension(): void
    {
        self::assertSame('', $this->subject->getDatabaseTableDefinition('unknown_extension'));
    }

    #[Test]
    public function getDatabaseTableDefinitionContainsCreateTableStatementForRegisteredField(): void
    {
        $this->subject->registerField('some_extension', 'tt_content', 'my_field');

        $definition = $this->subject->getDatabaseTableDefinition('some_extension');

        self::assertStringContainsString('CREATE TABLE tt_content (', $definition);
        self::assertStringContainsString('my_field text', $definition);
    }
}
