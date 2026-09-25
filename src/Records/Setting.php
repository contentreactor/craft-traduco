<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Records;

use ContentReactor\Traduco\migrations\Install;
use MarcusGaius\FieldValueParser\Records\Setting as ParserSetting;

/**
 * Traduco's settings, stored the way Field Value Parser stores configless settings, in Traduco's own table
 */
class Setting extends ParserSetting
{
	public static function tableName(): string
	{
		return Install::SETTINGS;
	}
}