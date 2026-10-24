<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Base;

use craft\base\Model;
use MarcusGaius\FieldValueParser\Helpers\SettingsHelper;

/**
 * A translator's own settings, stored under its class in UserSettings::$translatorSettings and handed to its
 * constructor. The model renders its own fields: the system settings screen draws a form for every registered
 * translator, and a translator can't be constructed just to draw one, as DeepL's and Google's constructors both
 * throw on a key they can't use.
 */
abstract class TranslatorSettings extends Model
{
	/**
	 * Final, so create() can build any of these models: a translator's settings are always constructed from stored or
	 * posted values alone, and anything a model does on top of that belongs in init().
	 *
	 * @param array<string, mixed> $config
	 */
	final public function __construct($config = [])
	{
		parent::__construct($config);
	}

	/**
	 * Builds the settings from stored or posted values, casting each one to its property's type. Values that aren't
	 * attributes of this model are left out, so settings a translator has dropped since don't break its screen.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function create(array $values): static
	{
		$settings = new static();
		$settings->setAttributes(SettingsHelper::castToPropertyTypes(static::class, $values), false);

		return $settings;
	}

	/**
	 * The fields for the system settings screen, which namespaces them and wraps them in a fieldset of its own
	 */
	abstract public function getSettingsHtml(): string;
}
