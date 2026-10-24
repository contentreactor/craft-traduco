<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\BaseTranslator;
use ContentReactor\Traduco\Helpers\Parser;
use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Locale;
use Psr\Http\Message\ResponseInterface;
use yii\base\{
	Exception,
	InvalidArgumentException,
	InvalidConfigException,
};

/**
 * What Cloud Translation's two editions share: the Guzzle seam tests swap, the mapping from a Craft site language onto
 * a Google language code, and turning Google's errors into something an editor can act on. What they don't share is
 * how a request is authenticated and shaped, which is each edition's own.
 */
abstract class BaseGoogleTranslator extends BaseTranslator
{
	/**
	 * Google's code for site languages it doesn't report under their current spelling, by lowercased locale prefix.
	 * The script is looked up before the region, so Simplified Chinese stays Simplified wherever it's written.
	 */
	public const LANGUAGE_CODES = [
		'zh-hans' => 'zh-CN',
		'zh-hant' => 'zh-TW',
		'zh-cn' => 'zh-CN',
		'zh-sg' => 'zh-CN',
		'zh-tw' => 'zh-TW',
		'zh-hk' => 'zh-TW',
		'zh-mo' => 'zh-TW',
		'zh' => 'zh-CN',
		// Google has one Norwegian, and reports Hebrew, Javanese and Filipino under their older codes
		'nb' => 'no',
		'nn' => 'no',
		'he' => 'iw',
		'jv' => 'jw',
		'fil' => 'tl',
	];

	public const TIMEOUT = 30;

	private ?Client $client = null;

	/** @var array<string, true>|null the codes Google reports, lowercased, or null until they're looked up */
	private ?array $_languageCodes = null;

	public function getClient(): Client
	{
		return $this->client ??= Craft::createGuzzleClient();
	}

	public function setClient(?Client $client): void
	{
		$this->client = $client;
	}

	/**
	 * Cloud Translation reads HTML unless it's told otherwise, so every request says which it is. The field decides,
	 * not the value: sniffing the value sends a Plain Text value holding a bare `<` as markup, and a CKEditor value
	 * that happens to have none as plain text.
	 *
	 * @param FieldInterface|null $field the field the text belongs to, null for an attribute like a title or a slug
	 * @return array<string, mixed>
	 */
	public function getTranslateOptions(ElementInterface $source, string $targetLanguage, ?FieldInterface $field = null): array
	{
		return [$this->getFormatOption() => $this->isMarkup($field) ? $this->getMarkupFormat() : $this->getPlainFormat()];
	}

	/**
	 * Google's code for a site language. Unlike DeepL's, the script and the region carry meaning and are kept:
	 * zh-Hant-TW is Traditional Chinese, not Chinese.
	 */
	protected function getTargetLanguageCode(string $locale): string
	{
		[$language, $script, $region] = self::parseLocale($locale);

		// The script decides before the region, so zh-Hans-HK is Simplified while zh-HK is Traditional
		foreach (array_filter(["$language-$script", "$language-$region", $language]) as $prefix) {
			if (isset(self::LANGUAGE_CODES[strtolower($prefix)])) return self::LANGUAGE_CODES[strtolower($prefix)];
		}

		$supported = $this->getLanguageCodes();
		if ($supported === []) return $language;

		// The longest code Google reports, e.g. pa-Arab and fr-CA, before the language on its own
		foreach (array_filter(["$language-$script-$region", "$language-$script", "$language-$region", $language]) as $code) {
			if (isset($supported[strtolower($code)])) return $code;
		}

		return $language;
	}

	/**
	 * Source languages Google doesn't report are left to its own detection, which it does when the source is left out
	 */
	protected function getSourceLanguageCode(string $locale): ?string
	{
		$code = $this->getTargetLanguageCode($locale);
		$supported = $this->getLanguageCodes();

		return $supported === [] || isset($supported[strtolower($code)]) ? $code : null;
	}

	/**
	 * @return array<string, true> the codes Google reports, lowercased, or an empty array where they can't be read
	 */
	protected function getLanguageCodes(): array
	{
		return $this->_languageCodes ??= $this->fetchLanguageCodes();
	}

	/**
	 * @return array<string, true> lowercased codes, or an empty array where they can't be read
	 */
	abstract protected function fetchLanguageCodes(): array;

	/**
	 * The headers this edition authenticates with
	 *
	 * @return array<string, string>
	 */
	abstract protected function getRequestHeaders(): array;

	/**
	 * The request field that says whether the text is markup, and the two values it takes
	 */
	abstract protected function getFormatOption(): string;

	abstract protected function getMarkupFormat(): string;

	abstract protected function getPlainFormat(): string;

	/**
	 * What this edition authenticates with, named the way an editor would look for it. Basic takes an API key and
	 * Advanced a service account, and an error message that says the wrong one sends people to the wrong screen.
	 */
	abstract protected function getCredentialName(): string;

	/**
	 * @param array<string, mixed> $options Guzzle request options
	 */
	protected function request(string $method, string $url, array $options = []): ResponseInterface
	{
		try {
			return $this->getClient()->request($method, $url, [
				'headers' => [
					...$this->getRequestHeaders(),
					'Content-Type' => 'application/json; charset=utf-8',
				],
				// Error responses are read and reported, rather than left to Guzzle, which the project's config decides for
				'http_errors' => false,
				'timeout' => static::TIMEOUT,
				...$options,
			]);
		} catch (GuzzleException $e) {
			throw new Exception('Couldn’t reach ' . static::displayName() . ': ' . $e->getMessage(), previous: $e);
		}
	}

	protected function isMarkup(?FieldInterface $field): bool
	{
		return $field !== null && Parser::isRichTextField($field);
	}

	/**
	 * @return array{string, string, string} the locale's language, script and region, e.g. ['zh', 'Hant', 'TW']
	 */
	protected static function parseLocale(string $locale): array
	{
		$locale = str_replace('_', '-', trim($locale));

		return [
			strtolower(Locale::getPrimaryLanguage($locale) ?: $locale),
			(string)Locale::getScript($locale),
			strtoupper((string)Locale::getRegion($locale)),
		];
	}

	/**
	 * Turns Google's error responses into something an editor can act on, see TranslationsController::translationFailure()
	 */
	protected function throwForError(ResponseInterface $response, string $target): never
	{
		$status = $response->getStatusCode();
		$body = Json::decodeIfJson((string)$response->getBody());
		$error = (array)(is_array($body) ? $body['error'] ?? [] : []);
		$message = (string)($error['message'] ?? $response->getReasonPhrase());
		$service = static::displayName();

		$reasons = array_map('strval', array_filter([
			$error['status'] ?? null,
			...array_column((array)($error['errors'] ?? []), 'reason'),
			...array_column((array)($error['details'] ?? []), 'reason'),
		], 'is_scalar'));
		$matches = fn(string ...$needles): bool => (bool)array_intersect($needles, $reasons);

		// The credential itself, which retrying never fixes
		if ($status === 401 || $matches(
			'API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED',
			'API_KEY_ANDROID_APP_BLOCKED', 'API_KEY_IOS_APP_BLOCKED', 'SERVICE_DISABLED', 'UNAUTHENTICATED',
			'keyInvalid', 'keyExpired', 'authError',
		)) {
			throw new InvalidConfigException(Craft::t('traduco', '{service} rejected the {credential}: {message}', [
				'service' => $service,
				'credential' => $this->getCredentialName(),
				'message' => $message,
			]));
		}

		// Quota and rate limits, which Basic answers 403 to, see https://docs.cloud.google.com/translate/quotas
		if ($status === 429 || $matches(
			'RESOURCE_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'rateLimitExceeded', 'userRateLimitExceeded',
			'dailyLimitExceeded', 'quotaExceeded',
		)) {
			throw new Exception(Craft::t('traduco', '{service}’s quota is used up, try again later: {message}', [
				'service' => $service,
				'message' => $message,
			]));
		}

		if ($status === 403 || $status === 404) {
			throw new InvalidConfigException(Craft::t('traduco', '{service} refused the request. Check that the {credential}’s project has the Cloud Translation API enabled: {message}', [
				'service' => $service,
				'credential' => $this->getCredentialName(),
				'message' => $message,
			]));
		}

		// Language pairs it doesn't support, and requests that are too long, come back as 400
		if ($status === 400) {
			throw new InvalidArgumentException(Craft::t('traduco', '{service} couldn’t translate into “{language}”: {message}', [
				'service' => $service,
				'language' => $target,
				'message' => $message,
			]));
		}

		throw new Exception(Craft::t('traduco', '{service} failed with {status}: {message}', [
			'service' => $service,
			'status' => $status,
			'message' => $message,
		]));
	}
}
