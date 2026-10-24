<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Web\Assets\Cp;

use craft\web\{
	AssetBundle,
	View,
};

class CpAsset extends AssetBundle
{
	public $sourcePath = __DIR__ . '/dist';

	public $js = [
		[
			'js/app.js',
			'position' => View::POS_HEAD,
		],
	];

	public $css = [
		[
			'css/app.css',
			'as' => 'style',
			'rel' => 'stylesheet preload',
		],
	];
}
