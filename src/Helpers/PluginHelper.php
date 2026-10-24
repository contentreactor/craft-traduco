<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Helpers;

use ContentReactor\Traduco\Records\Setting;
use Craft;
use craft\base\{
	Model,
	Plugin,
};
use MarcusGaius\FieldValueParser\Settings\{
	ConfiglessSettings,
	SettingsStore,
};

/**
 * Traduco's configless settings, stored in its own table through Field Value Parser's settings store
 */
class PluginHelper
{
	/**
	 * Saves the settings the request posts, once they validate. Settings apply to the whole install,
	 * one record per plugin and settings model.
	 */
	public static function saveSettings(Plugin $plugin, ConfiglessSettings&Model $settings): bool
	{
		$settings->load(Craft::$app->getRequest()->getBodyParams());

		return self::getStore()->save($plugin, $settings);
	}

	public static function loadSettings(Plugin $plugin, ConfiglessSettings&Model $settings): void
	{
		self::getStore()->load($plugin, $settings);
	}

	private static function getStore(): SettingsStore
	{
		return new SettingsStore(['recordClass' => Setting::class]);
	}
}
