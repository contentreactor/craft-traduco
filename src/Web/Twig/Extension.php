<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Web\Twig;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Web\Assets\Cp\CpAsset;
use Craft;
use Twig\Extension\{
	AbstractExtension,
	GlobalsInterface,
};
use Twig\TwigFunction;

class Extension extends AbstractExtension implements GlobalsInterface
{
	public function getGlobals(): array
	{
		$globals = [];
		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$globals['traducoAsset'] = CpAsset::class;
		}

		return $globals;
	}

	public function getFilters(): array
	{
		return [];
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('translatableSites', Plugin::getInstance()->getTraduco()->getTranslatableSites(...)),
		];
	}
}
