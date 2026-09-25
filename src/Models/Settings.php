<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
	public int $defaultSite = 1;
	private ?UserSettings $userSettings = null;

	public function getUserSettings(): ?UserSettings
	{
		return $this->userSettings;
	}

	public function setUserSettings(UserSettings $userSettings): void
	{
		$this->userSettings = $userSettings;
	}

	public function getPluginName(): string
	{
		return $this->getUserSettings()->pluginName ?? Craft::t('traduco', 'Traduco');
	}
}
