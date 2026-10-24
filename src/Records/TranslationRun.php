<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Records;

use ContentReactor\Traduco\Migrations\Install;
use craft\db\ActiveRecord;

/**
 * One translation, as it was asked for and as it went, see Services\History
 *
 * @property int $id
 * @property int $elementId
 * @property string $elementType
 * @property int $siteId
 * @property int $targetSiteId
 * @property string $kind
 * @property string|null $attribute
 * @property string $status
 * @property string|null $translator
 * @property string|null $error
 * @property int|null $characters
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TranslationRun extends ActiveRecord
{
	public static function tableName(): string
	{
		return Install::RUNS;
	}
}
