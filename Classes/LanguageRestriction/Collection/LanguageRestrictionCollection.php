<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\LanguageRestriction\Collection;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Localizationteam\L10nmgr\Constants;
use PDO;
use SplDoublyLinkedList;
use TYPO3\CMS\Core\Collection\AbstractRecordCollection;
use TYPO3\CMS\Core\Collection\CollectionInterface;
use TYPO3\CMS\Core\Collection\EditableCollectionInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Language Restriction Collection to handle records attached to a language
 */
class LanguageRestrictionCollection extends AbstractRecordCollection implements EditableCollectionInterface
{
    /**
     * The table name collections are stored to, must be defined in the subclass
     *
     * @var string
     */
    protected static $storageTableName = 'pages';

    /**
     * Contrary to the originally idea of collections, we do not load a record from the database here.
     * Instead we get the language by its ID. This is the key for our restriction collection
     *
     * @param int $languageId Id of the language to be loaded
     * @param bool $fillItems Populates the entries directly on load, might be bad for memory on large collections
     * @param string $tableName Name of table from which entries should be loaded
     * @param string $pageId ID of the page
     * @return CollectionInterface
     * @throws \Doctrine\DBAL\DBALException
     */
    public static function load($languageId, $fillItems = false, string $tableName = '', int $pageId = 0): CollectionInterface
    {
        try {
            $language = self::getLanguage($pageId, $languageId);
            $collectionRecord['uid'] = $language->getLanguageId();
            $collectionRecord['title'] = $language->getTitle();
        } catch (\RuntimeException $exception) {
            $collectionRecord['uid'] = 0;
            $collectionRecord['title'] = '';
        }

        $collectionRecord['description'] = 'Restriction Collection';
        $collectionRecord['table_name'] = $tableName;

        return self::create($collectionRecord, $fillItems);
    }

    /**
     * Populates the content-entries of the storage
     * Queries the underlying storage for entries of the collection
     * and adds them to the collection data.
     * If the content entries of the storage had not been loaded on creation
     * ($fillItems = false) this function is to be used for loading the contents
     * afterwards.
     *
     * @throws DBALException|DBALDriverException
     */
    public function loadContents()
    {
        $entries = $this->getCollectedRecords();
        $this->removeAll();
        foreach ($entries as $entry) {
            $this->add($entry);
        }
    }

    /**
     * Gets the collected records in this collection
     *
     * @return array
     * @throws DBALException|DBALDriverException
     */
    protected function getCollectedRecords(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::getCollectionDatabaseTable());
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $result = $queryBuilder->select('*')
            ->from(self::getCollectionDatabaseTable())
            ->where(
                $queryBuilder->expr()->inSet(
                    Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME,
                    $queryBuilder->createNamedParameter($this->uid, PDO::PARAM_INT)
                )
            )->execute();

        return $result->fetchAllAssociative();
    }

    /**
     * Removes all entries from the collection
     * collection will be empty afterwards
     */
    public function removeAll()
    {
        $this->storage = new SplDoublyLinkedList();
    }

    /**
     * Adds on entry to the collection
     *
     * @param mixed $data
     */
    public function add($data)
    {
        $this->storage->push($data);
    }

    /**
     * Adds a set of entries to the collection
     *
     * @param CollectionInterface $other
     */
    public function addAll(CollectionInterface $other)
    {
        foreach ($other as $value) {
            $this->add($value);
        }
    }

    /**
     * Removes the given entry from collection
     * Note: not the given "index"
     *
     * @param mixed $data
     */
    public function remove($data)
    {
        $offset = 0;
        foreach ($this->storage as $value) {
            if ($value == $data) {
                break;
            }
            $offset++;
        }
        $this->storage->offsetUnset($offset);
    }

    /**
     * Gets the current available items.
     *
     * @return array
     */
    public function getItems(): array
    {
        $itemArray = [];
        foreach ($this->storage as $item) {
            $itemArray[] = $item;
        }
        return $itemArray;
    }

    /**
     * Gets the current available items.
     *
     * @param int $uid
     * @return bool
     */
    public function hasItem(int $uid): bool
    {
        foreach ($this->storage as $item) {
            if (!empty($item['uid']) && $item['uid'] === $uid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns an array of the persistable properties and contents
     * which are processable by DataHandler.
     * for internal usage in persist only.
     *
     * @return array
     */
    protected function getPersistableDataArray(): array
    {
        return [
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'items' => $this->getItemUidList(),
        ];
    }

    /**
     * @param int $pageId
     * @return Site|NullSite
     */
    protected static function getSiteByPageId(int $pageId)
    {
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId);
        } catch (SiteNotFoundException $exception) {
            $site = new NullSite();
        }

        return $site;
    }

    protected static function getLanguage(int $pageId, $languageId): SiteLanguage
    {
        $site = self::getSiteByPageId($pageId);
        if ($site instanceof Site) {
            return $site->getLanguageById($languageId);
        }

        throw new \RuntimeException();
    }
}
