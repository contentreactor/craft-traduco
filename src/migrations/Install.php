<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\migrations;

use craft\db\Migration;
use craft\helpers\Db;

class Install extends Migration
{
	public const SETTINGS = '{{%contentreactor_traduco_settings}}';
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

	public function createTables(): void
	{
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
	}

	public function addIndexes(): void
	{
		Db::dropIndexIfExists(self::SETTINGS, ['plugin', 'key'], true);
		$this->createIndex(null, self::SETTINGS, ['plugin', 'key'], true);
	}

	public function dropTables(): void
	{
		$this->dropTableIfExists(self::SETTINGS);
	}
}
