<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use Craft;
use craft\helpers\Json;
use yii\base\{
	Exception,
	InvalidArgumentException,
	InvalidConfigException,
};

/**
 * Translates through Cloud Translation - Basic, the only edition that takes an API key, see
 * https://docs.cloud.google.com/translate/docs/authentication
 */
class GoogleTranslate extends BaseGoogleTranslator
{
	public const API_URL = 'https://translation.googleapis.com/language/translate/v2';
	public const LANGUAGES_URL = self::API_URL . '/languages';

	/** Basic translates with the NMT model, so only the languages that model supports are looked up */
	public const MODEL = 'nmt';

	/** Basic takes 100K bytes per request, see https://docs.cloud.google.com/translate/quotas */
	public const MAX_REQUEST_BYTES = 100_000;

	public function __construct(private readonly string $apiKey, array $settings = [])
	{
		parent::__construct($apiKey, $settings);

		if (trim($apiKey) === '') {
			throw new InvalidConfigException('Google Translate needs an API key.');
		}
	}

	public static function displayName(): string
	{
		return Craft::t('traduco', 'Google Translate');
	}

	/**
	 * @param string $text
	 * @param string $targetLanguage Craft site language, like de-AT, or a Google language code
	 * @param string|null $sourceLanguage Craft site language or Google language code, null to detect it
	 * @param array<string, mixed> $options further fields for the request, e.g. `format`
	 * @return string
	 */
	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		if (trim($text) === '') return $text;

		// Basic sends HTML unless it's told otherwise, so plain text has to say so or its punctuation comes back
		// escaped. Called without options, as a third-party translator may be, the safe default is plain text.
		$format = (string)($options['format'] ?? $this->getPlainFormat());
		$source = $sourceLanguage !== null ? $this->getSourceLanguageCode($sourceLanguage) : null;
		$target = $this->getTargetLanguageCode($targetLanguage);

		if (strlen($text) > self::MAX_REQUEST_BYTES) {
			throw new InvalidArgumentException(Craft::t('traduco', 'Google Translate takes up to {bytes} bytes of text per request. Translate this value in parts.', [
				'bytes' => self::MAX_REQUEST_BYTES,
			]));
		}

		// Counted before the call, so a request that fails halfway through an element still says what was billed
		$this->countCharacters($text);

		$response = $this->request('POST', self::API_URL, [
			'body' => Json::encode([
				...$options,
				'q' => $text,
				'target' => $target,
				'format' => $format,
			] + ($source !== null ? ['source' => $source] : [])),
		]);

		if ($response->getStatusCode() !== 200) {
			$this->throwForError($response, $target);
		}

		$data = Json::decodeIfJson((string)$response->getBody());
		$translated = is_array($data) ? ($data['data']['translations'][0]['translatedText'] ?? null) : null;
		if (!is_string($translated)) {
			throw new Exception(Craft::t('traduco', 'Google Translate answered with no translation.'));
		}

		// Plain text comes back with the entities the HTML default adds. Markup keeps its own, decoding it would break it.
		return $format === $this->getPlainFormat() ? html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $translated;
	}

	/**
	 * @return array<string, string>
	 */
	protected function getRequestHeaders(): array
	{
		// The documented way to pass a key, see https://docs.cloud.google.com/docs/authentication/api-keys-use
		return ['x-goog-api-key' => $this->apiKey];
	}

	protected function getCredentialName(): string
	{
		return Craft::t('traduco', 'API key');
	}

	protected function getFormatOption(): string
	{
		return 'format';
	}

	protected function getMarkupFormat(): string
	{
		return 'html';
	}

	protected function getPlainFormat(): string
	{
		return 'text';
	}

	/**
	 * @return array<string, true>
	 */
	protected function fetchLanguageCodes(): array
	{
		$response = $this->request('GET', self::LANGUAGES_URL, ['query' => ['model' => self::MODEL], 'timeout' => 10]);
		if ($response->getStatusCode() !== 200) {
			// Translating goes on with the mapped codes, and with Google detecting the source language
			Craft::warning('Couldn’t read Google Translate’s languages: ' . $response->getBody(), 'traduco');

			return [];
		}

		$data = Json::decodeIfJson((string)$response->getBody());
		$codes = [];
		foreach ((array)(is_array($data) ? $data['data']['languages'] ?? [] : []) as $language) {
			if (is_array($language) && is_string($language['language'] ?? null)) {
				$codes[strtolower($language['language'])] = true;
			}
		}

		return $codes;
	}
}
