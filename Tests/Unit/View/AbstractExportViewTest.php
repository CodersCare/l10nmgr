<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\View\AbstractExportView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Characterization test for AbstractExportView::diffCMP(), written ahead of the
 * L10N-004 fix (TYPO3\CMS\Core\Utility\DiffUtility::makeDiffDisplay() is removed in
 * CMS 14; the replacement diff() does not strip tags on its own, so the fix must
 * restore that behavior manually). Localizationteam\L10nmgr\Services\TranslationDetailsService::diffCMP()
 * contains the exact same one-line implementation and is covered by the same fix,
 * but is not separately tested here since it shares this identical logic.
 *
 * Instantiated via a minimal concrete subclass with a no-op constructor, since
 * AbstractExportView's real constructor needs a booted SiteFinder/LanguageService
 * (site configuration) that a pure unit test shouldn't need to depend on, and
 * AbstractExportView has no genuinely unimplemented abstract methods to stub.
 */
class AbstractExportViewTest extends UnitTestCase
{
    private function createSubject(): AbstractExportView
    {
        return new class extends AbstractExportView {
            public function __construct()
            {
                // Intentionally skip the parent constructor's SiteFinder/LanguageService
                // dependency — not needed to exercise diffCMP().
            }

            public function render(): string
            {
                return '';
            }
        };
    }

    #[Test]
    public function diffCMPStripsHtmlTagsBeforeDiffing(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('<p>old text</p>', '<p>new text</p>');

        self::assertStringNotContainsString(
            '<p>',
            $result,
            'diffCMP() must strip HTML tags from its inputs before diffing, matching the pre-CMS14 DiffUtility::makeDiffDisplay() default ($stripTags = true) behavior'
        );
    }

    #[Test]
    public function diffCMPHighlightsWordLevelDifferences(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('the quick fox', 'the slow fox');

        self::assertStringContainsString('quick', $result);
        self::assertStringContainsString('slow', $result);
    }

    #[Test]
    public function diffCMPReturnsEmptyMarkupForIdenticalStrings(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('identical text', 'identical text');

        self::assertStringNotContainsString('<ins', $result);
        self::assertStringNotContainsString('<del', $result);
    }
}
