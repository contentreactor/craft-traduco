<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use Craft;
use craft\web\AssetBundle;
use Exception;
use yii\base\Component;

class Bundles extends Component
{
	/**
	 * Registers CSS and JS files from an asset bundle
	 *
	 * @param class-string<AssetBundle> $bundleClass
	 * @param string|string[] $assetPath paths relative to the bundle's source path
	 * @param array<string, mixed> $options options for every file, see View::registerCssFile() and View::registerJsFile()
	 * @throws Exception if a file isn't CSS or JS, or can't be published
	 */
	public function registerAssetFile(string $bundleClass, array|string $assetPath, array $options = []): void
	{
		if (empty($assetPath)) return;

		$view = Craft::$app->getView();
		$assetManager = Craft::$app->getAssetManager();
		$bundleClass::register($view);
		$sourcePath = $assetManager->getBundle($bundleClass)->sourcePath;

		foreach ((array)$assetPath as $path) {
			$url = $assetManager->getPublishedUrl($sourcePath, false, $path);
			if ($url === false) {
				throw new Exception("Couldn’t publish $path.");
			}

			match (pathinfo($path, PATHINFO_EXTENSION)) {
				'css' => $view->registerCssFile($url, $options),
				'js' => $view->registerJsFile($url, $options),
				default => throw new Exception('Provided path is not a js or css file.'),
			};
		}
	}
}
