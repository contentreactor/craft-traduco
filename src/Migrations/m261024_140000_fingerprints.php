<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Migrations;

use craft\db\Migration;

/**
 * Adds the fingerprints of what was translated, see Services\Fingerprints. Install creates the table along with the
 * rest, so this is only ever for the installs that already have them, and it shares Install's own definition rather
 * than repeating it.
 */
class m261024_140000_fingerprints extends Migration
{
	public function safeUp(): bool
	{
		if ($this->db->tableExists(Install::FINGERPRINTS)) return true;

		$install = new Install();
		$install->db = $this->db;
		$install->createFingerprintsTable();
		$install->addFingerprintIndexes();

		return true;
	}

	/**
	 * Dropping the table takes its own indexes and foreign keys with it
	 */
	public function safeDown(): bool
	{
		$this->dropTableIfExists(Install::FINGERPRINTS);

		return true;
	}
}
