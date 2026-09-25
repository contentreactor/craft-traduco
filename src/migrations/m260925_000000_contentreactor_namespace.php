<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\migrations;

use craft\db\{
	Migration,
	Query,
};
use craft\helpers\Json;

/**
 * Traduco's classes moved from the `Traduco` namespace to `ContentReactor\Traduco`. Settings are stored by the class name
 * of their model, with the translator's class name among them, so both are renamed.
 */
class m260925_000000_contentreactor_namespace extends Migration
{
	/**
	 * The namespaces that moved, by their old name
	 */
	private const NAMESPACES = [
		'Traduco\\base\\' => 'ContentReactor\\Traduco\\Base\\',
		'Traduco\\models\\' => 'ContentReactor\\Traduco\\Models\\',
		'Traduco\\translators\\' => 'ContentReactor\\Traduco\\Translators\\',
	];

	public function safeUp(): bool
	{
		if (!$this->db->tableExists(Install::SETTINGS)) {
			return true;
		}

		$rows = (new Query())
			->select(['id', 'key', 'value'])
			->from(Install::SETTINGS)
			->where(['like', 'key', 'Traduco%', false])
			->all($this->db);

		foreach ($rows as $row) {
			$value = Json::decodeIfJson($row['value']);
			if (is_array($value)) {
				array_walk_recursive($value, function (mixed &$item): void {
					if (is_string($item)) {
						$item = self::rename($item);
					}
				});
			}

			$this->update(Install::SETTINGS, [
				'key' => self::rename($row['key']),
				'value' => is_array($value) ? Json::encode($value) : $row['value'],
			], ['id' => $row['id']], [], false);
		}

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260925_000000_contentreactor_namespace cannot be reverted.\n";
		return false;
	}

	private static function rename(string $className): string
	{
		foreach (self::NAMESPACES as $old => $new) {
			if (str_starts_with($className, $old)) {
				return $new . substr($className, strlen($old));
			}
		}

		return $className;
	}
}
