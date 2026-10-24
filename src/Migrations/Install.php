<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Migrations;

use craft\db\{
	Migration,
	Table,
};
use craft\helpers\Db;

class Install extends Migration
{
	public const SETTINGS = '{{%contentreactor_traduco_settings}}';

	/** A row per translation, see Services\History. Many of them per element and target site, never one status row. */
	public const RUNS = '{{%contentreactor_traduco_runs}}';

	/**
	 * A digest of what was translated and what was written, one row per element, site pair and attribute, upserted.
	 * Unlike the runs it's a current state rather than a log, it's never pruned, and it's written whatever the
	 * edition: it's what keeps a translation from being paid for or overwritten twice, see Services\Fingerprints.
	 */
	public const FINGERPRINTS = '{{%contentreactor_traduco_fingerprints}}';
	public function safeUp(): bool
	{
		$this->createTables();
		$this->addIndexes();
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown(): bool
	{
		$this->dropTables();

		return true;
	}

	/**
	 * The fingerprints table, on its own so the migration that adds it to an installed copy shares this one
	 * definition rather than repeating it, see m261024_140000_fingerprints
	 */
	public function createFingerprintsTable(): void
	{
		if (!$this->db->tableExists(self::FINGERPRINTS)) {
			$this->createTable(self::FINGERPRINTS, [
				'id' => $this->primaryKey(),
				'elementId' => $this->integer()->notNull(),
				'elementType' => $this->string()->notNull(),
				'siteId' => $this->integer()->notNull(),
				'targetSiteId' => $this->integer()->notNull(),
				// What the run did with the text: saved it, showed it to copy, or rephrased it, see Services\Fingerprints
				'scope' => $this->string(20)->notNull(),
				// Never null: a unique index treats nulls as distinct, which would stop the upsert finding its own row
				'attribute' => $this->string()->notNull(),
				'sourceHash' => $this->string(32)->notNull(),
				'optionsHash' => $this->string(32)->notNull(),
				// Null where the run saved nothing, so there's no translation of ours to be overwritten
				'targetHash' => $this->string(32)->null(),
				// The recipe the digests were made with, so changing it doesn't warn about every element at once
				'version' => $this->integer()->notNull(),
				'kind' => $this->string(20)->notNull(),
				'userId' => $this->integer()->null(),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
		}
	}

	public function addFingerprintIndexes(): void
	{
		Db::dropIndexIfExists(self::FINGERPRINTS, ['elementId', 'siteId', 'targetSiteId', 'scope', 'attribute'], true);
		$this->createIndex(null, self::FINGERPRINTS, ['elementId', 'siteId', 'targetSiteId', 'scope', 'attribute'], true);
		// The same keys as a run, so a fingerprint goes with the element and the sites it translated between
		$this->addForeignKey(null, self::FINGERPRINTS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::FINGERPRINTS, ['siteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::FINGERPRINTS, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::FINGERPRINTS, ['userId'], Table::USERS, ['id'], 'SET NULL');
	}

	public function createTables(): void
	{
		$this->createFingerprintsTable();

		if (!$this->db->tableExists(self::SETTINGS)) {
			$this->createTable(self::SETTINGS, [
				'id' => $this->primaryKey(),
				'plugin' => $this->string()->notNull(),
				'siteId' => $this->integer()->notNull(),
				'key' => $this->string()->notNull(),
				'value' => $this->text()->notNull(),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
		}

		if (!$this->db->tableExists(self::RUNS)) {
			$this->createTable(self::RUNS, [
				'id' => $this->primaryKey(),
				'elementId' => $this->integer()->notNull(),
				// Kept beside the ID so a run can be listed without loading the element it translated
				'elementType' => $this->string()->notNull(),
				'siteId' => $this->integer()->notNull(),
				'targetSiteId' => $this->integer()->notNull(),
				'kind' => $this->string(20)->notNull(),
				'attribute' => $this->string()->null(),
				'status' => $this->string(20)->notNull(),
				'translator' => $this->string()->null(),
				'error' => $this->text()->null(),
				// How much text went to the service, which is what it bills, see Services\History
				'characters' => $this->integer()->null(),
				'userId' => $this->integer()->null(),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
		}
	}

	public function addIndexes(): void
	{
		$this->addFingerprintIndexes();

		Db::dropIndexIfExists(self::SETTINGS, ['plugin', 'key'], true);
		$this->createIndex(null, self::SETTINGS, ['plugin', 'key'], true);

		$this->createIndex(null, self::RUNS, ['dateCreated']);
		$this->createIndex(null, self::RUNS, ['elementId', 'siteId', 'targetSiteId']);
		// A run goes with the element and the sites it translated between, and outlives the user who asked for it
		$this->addForeignKey(null, self::RUNS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::RUNS, ['siteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::RUNS, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::RUNS, ['userId'], Table::USERS, ['id'], 'SET NULL');
	}

	public function dropTables(): void
	{
		$this->dropTableIfExists(self::FINGERPRINTS);
		$this->dropTableIfExists(self::RUNS);
		$this->dropTableIfExists(self::SETTINGS);
	}
}
