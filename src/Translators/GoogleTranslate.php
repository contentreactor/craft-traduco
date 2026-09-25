<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\BaseTranslator;
use Craft;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use yii\base\NotSupportedException;

/**
 * Not implemented yet, so it isn't registered as a translator
 */
class GoogleTranslate extends BaseTranslator
{
	/** @phpstan-ignore property.uninitializedReadonly (the stub doesn't create its client yet) */
	public readonly TranslationServiceClient $client;

	public function __construct(string $apiKey)
	{
		// TODO: create the TranslationServiceClient
	}

	public static function displayName(): string
	{
		return Craft::t('traduco', 'Google Translate');
	}

	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		// TODO: Implement translate() method.
		throw new NotSupportedException('Google Translate isn’t implemented yet.');
	}
}
