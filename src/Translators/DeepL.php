<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\BaseTranslator;
use Craft;
use DeepL\{
	DeepLException,
	Language,
	LanguageCode,
	TranslateTextOptions,
	Translator,
};
use yii\base\InvalidConfigException;

class DeepL extends BaseTranslator
{
	/**
	 * Target languages DeepL only accepts with a regional variant
	 */
	public const LANGUAGE_REGIONAL_VARIANTS = [
		'en' => 'en-GB',
		'pt' => 'pt-PT',
	];

	public readonly Translator $client;

	/**
	 * @var Language[]
	 */
	private array $_targetLanguages = [];

	/**
	 * @var Language[]
	 */
	private array $_sourceLanguages = [];

	// TODO: consider implementing a tracker of performed translations
	private array $_translations = [];

	public function __construct(string $apiKey)
	{
		try {
			$this->client = new Translator($apiKey);
		} catch (DeepLException $e) {
			throw new InvalidConfigException('Couldn’t set up the DeepL client: ' . $e->getMessage(), previous: $e);
		}
	}

	public static function displayName(): string
	{
		return Craft::t('traduco', 'DeepL');
	}

	/**
	 * @param string $text
	 * @param string $targetLanguage Craft locale or DeepL language code
	 * @param string|null $sourceLanguage Craft locale or DeepL language code, null to detect it
	 * @param array<TranslateTextOptions::*, string> $options
	 * @return string
	 */
	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		if (trim($text) === '') return $text;

		// Keep markup, e.g. from CKEditor fields, intact
		if (!isset($options[TranslateTextOptions::TAG_HANDLING]) && $text !== strip_tags($text)) {
			$options[TranslateTextOptions::TAG_HANDLING] = 'html';
		}

		// TODO: add tracker of translations, element (somehow), current user, etc
		return (string)$this->client->translateText(
			$text,
			$sourceLanguage !== null ? $this->getSourceLanguageCode($sourceLanguage) : null,
			$this->getTargetLanguageCode($targetLanguage),
			$options,
		);
	}

	private function getTargetLanguageCode(string $locale): string
	{
		$code = strtoupper(str_replace('_', '-', $locale));
		if (in_array($code, $this->getLanguageCodes($this->getTargetLanguages()), true)) return $code;

		// e.g. de-AT isn't a DeepL target, de is
		$language = LanguageCode::removeRegionalVariant($code);

		return self::LANGUAGE_REGIONAL_VARIANTS[$language] ?? $language;
	}

	/**
	 * Source languages DeepL doesn't support are left to its language detection
	 */
	private function getSourceLanguageCode(string $locale): ?string
	{
		$language = LanguageCode::removeRegionalVariant(str_replace('_', '-', $locale));

		return in_array(strtoupper($language), $this->getLanguageCodes($this->getSourceLanguages()), true) ? $language : null;
	}

	/**
	 * @param Language[] $languages
	 * @return string[]
	 */
	private function getLanguageCodes(array $languages): array
	{
		return array_map(fn(Language $language): string => strtoupper($language->code), $languages);
	}

	/**
	 * @return Language[]
	 */
	private function getTargetLanguages(): array
	{
		if (empty($this->_targetLanguages)) {
			$this->_targetLanguages = $this->client->getTargetLanguages();
		}

		return $this->_targetLanguages;
	}

	/**
	 * @return Language[]
	 */
	private function getSourceLanguages(): array
	{
		if (empty($this->_sourceLanguages)) {
			$this->_sourceLanguages = $this->client->getSourceLanguages();
		}

		return $this->_sourceLanguages;
	}
}
