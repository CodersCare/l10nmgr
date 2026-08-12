<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\LanguageRestriction\Collection\LanguageRestrictionCollection;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers only the in-memory storage behavior (add/remove/getItems/hasItem/getPersistableDataArray),
 * built via the inherited AbstractRecordCollection::create() factory which does not touch the
 * database. load()/loadContents()/getCollectedRecords() need a real SiteFinder/database and belong
 * in a functional test instead.
 */
class LanguageRestrictionCollectionTest extends UnitTestCase
{
    protected LanguageRestrictionCollection $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = LanguageRestrictionCollection::create([
            'uid' => 1,
            'title' => 'Test Collection',
            'description' => 'Restriction Collection',
            'table_name' => 'tt_content',
        ]);
    }

    #[Test]
    public function newlyCreatedCollectionHasNoItems(): void
    {
        self::assertSame([], $this->subject->getItems());
    }

    #[Test]
    public function addAppendsAnEntryToGetItems(): void
    {
        $this->subject->add(['uid' => 5]);

        self::assertSame([['uid' => 5]], $this->subject->getItems());
    }

    #[Test]
    public function addAllCopiesEntriesFromAnotherCollection(): void
    {
        $other = LanguageRestrictionCollection::create([
            'uid' => 2,
            'title' => 'Other',
            'description' => '',
            'table_name' => 'pages',
        ]);
        $other->add(['uid' => 10]);
        $other->add(['uid' => 20]);

        $this->subject->add(['uid' => 5]);
        $this->subject->addAll($other);

        self::assertSame([['uid' => 5], ['uid' => 10], ['uid' => 20]], $this->subject->getItems());
    }

    #[Test]
    public function removeDeletesOnlyTheMatchingEntry(): void
    {
        $this->subject->add(['uid' => 5]);
        $this->subject->add(['uid' => 10]);
        $this->subject->add(['uid' => 15]);

        $this->subject->remove(['uid' => 10]);

        self::assertSame([['uid' => 5], ['uid' => 15]], $this->subject->getItems());
    }

    #[Test]
    public function removeAllEmptiesTheCollection(): void
    {
        $this->subject->add(['uid' => 5]);
        $this->subject->add(['uid' => 10]);

        $this->subject->removeAll();

        self::assertSame([], $this->subject->getItems());
    }

    #[Test]
    public function hasItemReturnsTrueOnlyForAMatchingUid(): void
    {
        $this->subject->add(['uid' => 5]);

        self::assertTrue($this->subject->hasItem(5));
        self::assertFalse($this->subject->hasItem(6));
    }

    #[Test]
    public function hasItemReturnsFalseForEntriesWithoutAUidKey(): void
    {
        $this->subject->add(['title' => 'no uid here']);

        self::assertFalse($this->subject->hasItem(0));
    }

    #[Test]
    public function getPersistableDataArrayReturnsTitleDescriptionAndCommaSeparatedItemUidList(): void
    {
        $this->subject->add(['uid' => 5]);
        $this->subject->add(['uid' => 10]);

        $method = new \ReflectionMethod($this->subject, 'getPersistableDataArray');
        $result = $method->invoke($this->subject);

        self::assertSame('Test Collection', $result['title']);
        self::assertSame('Restriction Collection', $result['description']);
        self::assertSame('tt_content_5,tt_content_10', $result['items']);
    }
}
