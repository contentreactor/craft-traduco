<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\migrations;

use craft\db\Migration;
use craft\helpers\Db;

/**
 * Adds a primary key to the settings table, so saved settings can be updated, and keys settings by plugin rather than site
 */
class m260911_000000_settings_primary_key extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->columnExists(Install::SETTINGS, 'id')) {
			$this->addColumn(Install::SETTINGS, 'id', (string)$this->primaryKey()->first());
		}

		Db::dropIndexIfExists(Install::SETTINGS, ['key'], true);
		Db::dropIndexIfExists(Install::SETTINGS, ['siteId', 'key']);
		Db::dropIndexIfExists(Install::SETTINGS, ['plugin', 'key'], true);
		$this->createIndex(null, Install::SETTINGS, ['plugin', 'key'], true);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260911_000000_settings_primary_key cannot be reverted.\n";
		return false;
	}
}
