<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Migrations;

use craft\db\Migration;

/**
 * Adds the character count to the translation history, see Services\History. Install creates it with the table, so
 * this is only ever for the installs that already had one.
 */
class m261024_120000_run_characters extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->columnExists(Install::RUNS, 'characters')) {
			$this->addColumn(Install::RUNS, 'characters', $this->integer()->null()->after('error'));
		}

		return true;
	}

	public function safeDown(): bool
	{
		if ($this->db->columnExists(Install::RUNS, 'characters')) {
			$this->dropColumn(Install::RUNS, 'characters');
		}

		return true;
	}
}
