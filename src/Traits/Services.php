<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Traits;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Services\{
	Bundles,
	Fingerprints,
	History,
	Notifications,
	Traduco,
	Translations,
};

/**
 * @mixin Plugin
 *
 * @property-read Translations $translations
 * @property-read Bundles $bundles
 * @property-read Traduco $traduco
 * @property-read History $history
 * @property-read Fingerprints $fingerprints
 * @property-read Notifications $notifications
 */
trait Services
{
	/**
	 * @return array{components: array{bundles: class-string<Bundles>, translations: class-string<Translations>, traduco: class-string<Traduco>, history: class-string<History>, fingerprints: class-string<Fingerprints>, notifications: class-string<Notifications>}}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'bundles' => Bundles::class,
				'translations' => Translations::class,
				'traduco' => Traduco::class,
				'history' => History::class,
				'fingerprints' => Fingerprints::class,
				'notifications' => Notifications::class,
			],
		];
	}

	public function getTranslations(): Translations
	{
		return $this->get('translations');
	}

	public function getBundles(): Bundles
	{
		return $this->get('bundles');
	}

	public function getTraduco(): Traduco
	{
		return $this->get('traduco');
	}

	public function getHistory(): History
	{
		return $this->get('history');
	}

	public function getFingerprints(): Fingerprints
	{
		return $this->get('fingerprints');
	}

	public function getNotifications(): Notifications
	{
		return $this->get('notifications');
	}
}
