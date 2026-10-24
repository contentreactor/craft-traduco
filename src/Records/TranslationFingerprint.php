<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Records;

use ContentReactor\Traduco\Migrations\Install;
use craft\db\ActiveRecord;

/**
 * A digest of what was translated and what was written, see Services\Fingerprints
 *
 * @property int $id
 * @property int $elementId
 * @property string $elementType
 * @property int $siteId
 * @property int $targetSiteId
 * @property string $scope
 * @property string $attribute
 * @property string $sourceHash
 * @property string $optionsHash
 * @property string|null $targetHash
 * @property int $version
 * @property string $kind
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TranslationFingerprint extends ActiveRecord
{
	public static function tableName(): string
	{
		return Install::FINGERPRINTS;
	}
}
