<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Web\Twig;

use ContentReactor\Traduco\Models\Settings;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\elements\User;

class Variable
{
	/**
	 * @param string $bundleClass
	 * @param string[]|string $assetPath
	 * @param array<string, mixed> $options options for every file, see View::registerCssFile() and View::registerJsFile()
	 * @return void
	 */
	public function registerAsset(string $bundleClass, array|string $assetPath, array $options = []): void
	{
		Plugin::getInstance()->getBundles()->registerAssetFile($bundleClass, $assetPath, $options);
	}

	public function settings(): Settings
	{
		return Plugin::getInstance()->getSettings();
	}

	public function getPluginName(): string
	{
		return Plugin::getInstance()->getSettings()->getPluginName();
	}

	/**
	 * @return array<string, array{title: string, iconSvg: string}>
	 */
	public function getSettingsNavItems(): array
	{
		/** @var User $currentUser */
		$currentUser = Craft::$app->getUser()->getIdentity();
		$navs = [];

		if ($currentUser->can('traduco:settings:admin')) {
			$navs['settings'] = ['title' => Craft::t('traduco', 'Admin Settings'), 'iconSvg' => '@traduco/icons/settings.svg'];
		}
		if ($currentUser->can('traduco:settings:system')) {
			$navs['system'] = [
				'title' => Craft::t('traduco', 'System Settings'),
				'iconSvg' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges ? '@traduco/icons/settings.svg' : '@traduco/icons/settings-slash.svg',
			];
		}

		return $navs;
	}
}
