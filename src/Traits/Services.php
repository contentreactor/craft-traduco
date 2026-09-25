<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Traits;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Services\{
	Bundles,
	Traduco,
	Translations,
};

/**
 * @mixin Plugin
 *
 * @property-read Translations $translations
 * @property-read Bundles $bundles
 * @property-read Traduco $traduco
 */
trait Services
{
	/**
	 * @return array{components: array{bundles: class-string<Bundles>, translations: class-string<Translations>, traduco: class-string<Traduco>}}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'bundles' => Bundles::class,
				'translations' => Translations::class,
				'traduco' => Traduco::class,
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
}
