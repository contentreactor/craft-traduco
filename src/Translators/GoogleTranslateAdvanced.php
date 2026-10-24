<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\TranslatorSettings;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\helpers\{
	App,
	Json,
};
use Throwable;
use yii\base\{
	Exception,
	InvalidConfigException,
};

/**
 * Translates through Cloud Translation - Advanced, which takes no API keys: every request carries an access token
 * minted from a service account's private key, and names a project and a location. The key file's path is held as an
 * environment variable reference, so the key itself never reaches the settings row, and the project id is read out of
 * the file rather than typed again.
 *
 * Google's own client libraries are not a dependency here, for the same reason Basic doesn't use one: the two calls
 * Traduco makes are plain REST, and the credential flow is a signed assertion PHP can make on its own.
 */
class GoogleTranslateAdvanced extends BaseGoogleTranslator
{
	public const API_URL = 'https://translate.googleapis.com/v3';

	public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	public const SCOPE = 'https://www.googleapis.com/auth/cloud-translation';

	/** Advanced takes 30K codepoints per request, see https://docs.cloud.google.com/translate/quotas */
	public const MAX_REQUEST_CHARACTERS = 30_000;

	/**
	 * How long the languages a project reports are kept. The call is authenticated and billed against the customer's
	 * own project, unlike Basic's, so it isn't made per request.
	 */
	public const LANGUAGE_CACHE_DURATION = 86_400;

	/** An assertion is good for an hour; the token it buys is cached a minute short of what Google says it lasts */
	public const TOKEN_LIFETIME = 3_600;

	/**
	 * How long the project's glossaries are kept. Short, like DeepL's, because an editor who just made one in Google
	 * comes straight here to pick it.
	 */
	public const GLOSSARY_CACHE_DURATION = 300;

	/** Google keeps glossaries per region, so the multi-region endpoint has none */
	public const GLOSSARY_LOCATIONS_EXCLUDED = [GoogleTranslateAdvancedSettings::LOCATION_GLOBAL];

	/** @var array<string, array{name: string, source: string, target: string, entries: int, set: string[]}>|null */
	private ?array $_glossaries = null;

	public function __construct(private readonly string $apiKey, array $settings = [])
	{
		parent::__construct($apiKey, $settings);

		if (trim($apiKey) === '') {
			throw new InvalidConfigException('Google Translate (Advanced) needs the path to a service account key file.');
		}
	}

	public static function displayName(): string
	{
		return Craft::t('traduco', 'Google Translate (Advanced)');
	}

	public static function createSettings(array $values = []): ?TranslatorSettings
	{
		return GoogleTranslateAdvancedSettings::create($values);
	}

	/**
	 * The service account the key file describes, or null where it can't be read. Null rather than an exception: the
	 * settings screen draws this translator's pane whatever state the credential is in, and it's the screen that
	 * fixes it.
	 *
	 * @return array{client_email: string, private_key: string, project_id: string}|null
	 */
	public function getCredentials(): ?array
	{
		try {
			$path = App::parseEnv($this->apiKey);
			if (!is_string($path) || !is_file($path)) {
				throw new Exception("No service account key file at “{$path}”.");
			}

			$contents = file_get_contents($path);
			$key = is_string($contents) ? Json::decodeIfJson($contents) : null;
			if (!is_array($key)) {
				throw new Exception('The service account key file isn’t JSON.');
			}

			foreach (['client_email', 'private_key', 'project_id'] as $field) {
				if (!is_string($key[$field] ?? null) || trim($key[$field]) === '') {
					throw new Exception("The service account key file has no $field.");
				}
			}

			return [
				'client_email' => $key['client_email'],
				'private_key' => $key['private_key'],
				'project_id' => $key['project_id'],
			];
		} catch (Throwable $e) {
			Craft::warning('Couldn’t read the Google service account: ' . $e->getMessage(), 'traduco');

			return null;
		}
	}

	/**
	 * The project the credential belongs to, which every Advanced request names
	 */
	public function getProjectId(): ?string
	{
		return $this->getCredentials()['project_id'] ?? null;
	}

	/**
	 * Why this translator can't translate as it's set up, for the settings screen, or null where it can
	 */
	public function getCredentialWarning(): ?string
	{
		if ($this->getCredentials() !== null) return null;

		return Craft::t('traduco', 'Couldn’t read the service account key file. Check the environment variable and the file it points at.');
	}

	/**
	 * Whether glossaries can be used at all as this is set up. Google keeps them per region, so asking for them on
	 * the multi-region endpoint answers an error rather than an empty list.
	 */
	public function isGlossaryLocation(): bool
	{
		return !in_array($this->getLocation(), self::GLOSSARY_LOCATIONS_EXCLUDED, true);
	}

	/**
	 * The project's glossaries, by their short ID. Empty where they can't be read, or where the location can't have
	 * any, so a screen draws nothing rather than failing.
	 *
	 * @return array<string, array{name: string, source: string, target: string, entries: int, set: string[]}>
	 */
	public function getGlossaries(): array
	{
		if (!$this->isGlossaryLocation()) return [];
		if ($this->_glossaries !== null) return $this->_glossaries;

		try {
			$glossaries = $this->remember('glossaries', function (): array {
				$glossaries = [];
				$pageToken = null;

				do {
					$page = $this->glossaryRequest('GET', $this->getLocationUrl() . '/glossaries', [
						'query' => array_filter(['pageToken' => $pageToken]),
					]);

					foreach ((array)($page['glossaries'] ?? []) as $glossary) {
						$id = self::getResourceId((string)($glossary['name'] ?? ''));
						if ($id === '') continue;

						$glossaries[$id] = [
							'name' => (string)($glossary['displayName'] ?? $id),
							'source' => (string)($glossary['languagePair']['sourceLanguageCode'] ?? ''),
							'target' => (string)($glossary['languagePair']['targetLanguageCode'] ?? ''),
							'entries' => (int)($glossary['entryCount'] ?? 0),
							// An equivalent term set covers several languages at once and has no one pair
							'set' => array_map('strval', (array)($glossary['languageCodesSet']['languageCodes'] ?? [])),
						];
					}

					$pageToken = $page['nextPageToken'] ?? null;
				} while (is_string($pageToken) && $pageToken !== '');

				return $glossaries;
			}, self::GLOSSARY_CACHE_DURATION);
		} catch (Throwable $e) {
			// Caught rather than cached, so one unreachable moment isn't five minutes of an empty screen
			Craft::warning('Couldn’t read Google’s glossaries: ' . $e->getMessage(), 'traduco');
			$glossaries = [];
		}

		return $this->_glossaries = $glossaries;
	}

	/**
	 * The glossary among the chosen ones that covers this pair, as Google's own resource name, or null. The codes
	 * have to match exactly: Google treats `en` and `en-GB` as different glossary languages, so falling back to a
	 * base language would send the wrong terms.
	 *
	 * @param string[] $glossaryIds the chosen glossaries, see GoogleTranslateAdvancedSettings::$glossaries
	 */
	public function getGlossaryFor(array $glossaryIds, string $sourceLocale, string $targetLocale): ?string
	{
		if ($glossaryIds === [] || !Plugin::getInstance()->isPro() || !$this->isGlossaryLocation()) return null;

		$source = $this->getSourceLanguageCode($sourceLocale);
		if ($source === null) return null;

		$target = $this->getTargetLanguageCode($targetLocale);
		$glossaries = $this->getGlossaries();

		foreach ($glossaryIds as $glossaryId) {
			$glossary = $glossaries[$glossaryId] ?? null;
			if ($glossary === null) continue;

			$covers = ($glossary['source'] === $source && $glossary['target'] === $target)
				|| (in_array($source, $glossary['set'], true) && in_array($target, $glossary['set'], true));
			if ($covers) return $this->getGlossaryName($glossaryId);
		}

		return null;
	}

	/**
	 * Creates a glossary from a file the customer already keeps in Cloud Storage. Traduco never writes that file:
	 * uploading it would need the Cloud Storage API enabled and a write grant on the bucket, on top of the read
	 * grant Google itself needs to read it with.
	 *
	 * @param string[] $languageCodes two codes for a one-way glossary, or several for an equivalent term set
	 * @return array<string, mixed> the long-running operation Google answers with
	 */
	public function createGlossary(string $glossaryId, array $languageCodes, string $inputUri, bool $isTermSet = false): array
	{
		$pair = $isTermSet
			? ['languageCodesSet' => ['languageCodes' => array_values($languageCodes)]]
			: ['languagePair' => ['sourceLanguageCode' => $languageCodes[0] ?? '', 'targetLanguageCode' => $languageCodes[1] ?? '']];

		$operation = $this->glossaryRequest('POST', $this->getLocationUrl() . '/glossaries', [
			'body' => Json::encode([
				'name' => $this->getGlossaryName($glossaryId),
				'displayName' => $glossaryId,
				'inputConfig' => ['gcsSource' => ['inputUri' => $inputUri]],
			] + $pair),
		]);
		$this->forgetGlossaries();

		return $operation;
	}

	public function deleteGlossary(string $glossaryId): void
	{
		$this->glossaryRequest('DELETE', $this->getGlossaryUrl($glossaryId));
		$this->forgetGlossaries();
	}

	/**
	 * A glossary's terms, by the entry's own ID
	 *
	 * @return array<string, array{source: string, target: string}>
	 */
	public function getGlossaryEntries(string $glossaryId): array
	{
		$entries = [];
		$pageToken = null;

		do {
			$page = $this->glossaryRequest('GET', $this->getGlossaryUrl($glossaryId) . '/glossaryEntries', [
				'query' => array_filter(['pageToken' => $pageToken]),
			]);

			foreach ((array)($page['glossaryEntries'] ?? []) as $entry) {
				$id = self::getResourceId((string)($entry['name'] ?? ''));
				if ($id === '') continue;

				$entries[$id] = [
					'source' => (string)($entry['termsPair']['sourceTerm']['text'] ?? ''),
					'target' => (string)($entry['termsPair']['targetTerm']['text'] ?? ''),
				];
			}

			$pageToken = $page['nextPageToken'] ?? null;
		} while (is_string($pageToken) && $pageToken !== '');

		return $entries;
	}

	public function saveGlossaryEntry(string $glossaryId, string $source, string $target, ?string $entryId = null): void
	{
		$glossary = $this->getGlossaries()[$glossaryId] ?? null;
		$terms = [
			'termsPair' => [
				'sourceTerm' => ['languageCode' => $glossary['source'] ?? '', 'text' => $source],
				'targetTerm' => ['languageCode' => $glossary['target'] ?? '', 'text' => $target],
			],
		];

		if ($entryId === null) {
			$this->glossaryRequest('POST', $this->getGlossaryUrl($glossaryId) . '/glossaryEntries', ['body' => Json::encode($terms)]);
		} else {
			$url = $this->getGlossaryUrl($glossaryId) . '/glossaryEntries/' . $entryId;
			$this->glossaryRequest('PATCH', $url, ['body' => Json::encode(['name' => $url] + $terms)]);
		}

		$this->forgetGlossaries();
	}

	public function deleteGlossaryEntry(string $glossaryId, string $entryId): void
	{
		$this->glossaryRequest('DELETE', $this->getGlossaryUrl($glossaryId) . '/glossaryEntries/' . $entryId);
		$this->forgetGlossaries();
	}

	/**
	 * Google's code for each site language, for the screen that builds a glossary
	 *
	 * @return array<string, string> Google codes by site language
	 */
	public function getGlossaryLanguageCodes(): array
	{
		$codes = [];
		foreach (Craft::$app->getSites()->getAllSites() as $site) {
			$codes[$site->language] = $this->getTargetLanguageCode($site->language);
		}

		return $codes;
	}

	/**
	 * @param string $text
	 * @param string $targetLanguage Craft site language, like de-AT, or a Google language code
	 * @param string|null $sourceLanguage Craft site language or Google language code, null to detect it
	 * @param array<string, mixed> $options further fields for the request, e.g. `mimeType`
	 * @return string
	 */
	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		if (trim($text) === '') return $text;

		if (mb_strlen($text) > self::MAX_REQUEST_CHARACTERS) {
			throw new Exception(Craft::t('traduco', '{service} takes up to {characters} characters of text per request. Translate this value in parts.', [
				'service' => self::displayName(),
				'characters' => self::MAX_REQUEST_CHARACTERS,
			]));
		}

		$source = $sourceLanguage !== null ? $this->getSourceLanguageCode($sourceLanguage) : null;
		$target = $this->getTargetLanguageCode($targetLanguage);
		$settings = $this->getAdvancedSettings();
		$model = $settings?->getModelPath($this->getProjectId());

		// Google needs the source language named for a glossary, as DeepL does
		$glossary = $source === null || $settings === null
			? null
			: $this->getGlossaryFor($settings->glossaries, (string)$sourceLanguage, $targetLanguage);

		// Counted before the call, so a request that fails halfway through an element still says what was billed
		$this->countCharacters($text);

		$response = $this->request('POST', $this->getLocationUrl() . ':translateText', [
			'body' => Json::encode([
				...$options,
				'contents' => [$text],
				'targetLanguageCode' => $target,
				'mimeType' => $options['mimeType'] ?? $this->getPlainFormat(),
			]
				+ ($source !== null ? ['sourceLanguageCode' => $source] : [])
				+ ($model !== null ? ['model' => $model] : [])
				+ ($glossary !== null ? ['glossaryConfig' => [
					'glossary' => $glossary,
					'ignoreCase' => $settings->glossaryIgnoreCase,
				]] : [])),
		]);

		if ($response->getStatusCode() !== 200) {
			$this->throwForError($response, $target);
		}

		$data = Json::decodeIfJson((string)$response->getBody());
		$translated = is_array($data) ? ($data['translations'][0]['translatedText'] ?? null) : null;
		if (!is_string($translated)) {
			throw new Exception(Craft::t('traduco', '{service} answered with no translation.', ['service' => self::displayName()]));
		}

		// Advanced answers plain text as it was sent, so there are no HTML entities to undo as there are with Basic
		return $translated;
	}

	/**
	 * @return array<string, string>
	 */
	protected function getRequestHeaders(): array
	{
		return ['Authorization' => 'Bearer ' . $this->getAccessToken()];
	}

	protected function getCredentialName(): string
	{
		return Craft::t('traduco', 'service account');
	}

	protected function getFormatOption(): string
	{
		return 'mimeType';
	}

	protected function getMarkupFormat(): string
	{
		return 'text/html';
	}

	protected function getPlainFormat(): string
	{
		return 'text/plain';
	}

	/**
	 * @return array<string, true>
	 */
	protected function fetchLanguageCodes(): array
	{
		try {
			// Only a reading is kept: caching a failure would turn one unreachable moment into a day of fallbacks
			return $this->remember('languages', function (): array {
				$response = $this->request('GET', $this->getLocationUrl() . '/supportedLanguages', ['timeout' => 10]);
				if ($response->getStatusCode() !== 200) {
					throw new Exception((string)$response->getBody());
				}

				$data = Json::decodeIfJson((string)$response->getBody());
				$codes = [];
				foreach ((array)(is_array($data) ? $data['languages'] ?? [] : []) as $language) {
					if (is_array($language) && is_string($language['languageCode'] ?? null)) {
						$codes[strtolower($language['languageCode'])] = true;
					}
				}

				return $codes;
			});
		} catch (Throwable $e) {
			// Translating goes on with the mapped codes, and with Google detecting the source language
			Craft::warning('Couldn’t read Google Translate (Advanced)’s languages: ' . $e->getMessage(), 'traduco');

			return [];
		}
	}

	/**
	 * Google's glossary endpoints answer an empty body on a delete, so a body that isn't JSON is a success with
	 * nothing in it rather than a failure
	 *
	 * @param array<string, mixed> $options Guzzle request options
	 * @return array<string, mixed>
	 */
	private function glossaryRequest(string $method, string $url, array $options = []): array
	{
		$response = $this->request($method, $url, $options);
		if ($response->getStatusCode() >= 300) {
			$this->throwForError($response, $this->getLocation());
		}

		$data = Json::decodeIfJson((string)$response->getBody());

		return is_array($data) ? $data : [];
	}

	private function getGlossaryName(string $glossaryId): string
	{
		return sprintf('projects/%s/locations/%s/glossaries/%s', (string)$this->getProjectId(), $this->getLocation(), $glossaryId);
	}

	private function getGlossaryUrl(string $glossaryId): string
	{
		return $this->getLocationUrl() . '/glossaries/' . $glossaryId;
	}

	/**
	 * The last segment of one of Google's resource names, which is the only part a screen or a setting holds
	 */
	private static function getResourceId(string $resourceName): string
	{
		$parts = explode('/', $resourceName);

		return (string)end($parts);
	}

	private function forgetGlossaries(): void
	{
		$this->_glossaries = null;
		Craft::$app->getCache()->delete([
			'traduco',
			'google-advanced',
			'glossaries',
			$this->getLocation(),
			substr(hash('sha256', $this->apiKey), 0, 16),
		]);
	}

	private function getAdvancedSettings(): ?GoogleTranslateAdvancedSettings
	{
		return $this->settingsModel instanceof GoogleTranslateAdvancedSettings ? $this->settingsModel : null;
	}

	private function getLocation(): string
	{
		return $this->getAdvancedSettings()?->location ?: GoogleTranslateAdvancedSettings::LOCATION_GLOBAL;
	}

	private function getLocationUrl(): string
	{
		$project = $this->getProjectId()
			?? throw new InvalidConfigException('Couldn’t read the project from the Google service account key file.');

		return sprintf('%s/projects/%s/locations/%s', self::API_URL, $project, $this->getLocation());
	}

	/**
	 * Exchanges a signed assertion for an access token, the flow Google documents for a service account with no user
	 * in the room, see https://docs.cloud.google.com/identity/protocols/oauth2/service-account
	 */
	private function getAccessToken(): string
	{
		$credentials = $this->getCredentials()
			?? throw new InvalidConfigException('Couldn’t read the Google service account key file.');

		$key = ['traduco', 'google-advanced', 'token', substr(hash('sha256', $credentials['client_email'] . $this->apiKey), 0, 16)];
		$cache = Craft::$app->getCache();
		$token = $cache->get($key);
		if (is_string($token) && $token !== '') return $token;

		$issued = time();
		$assertion = self::signAssertion([
			'iss' => $credentials['client_email'],
			'scope' => self::SCOPE,
			'aud' => self::TOKEN_URL,
			'iat' => $issued,
			'exp' => $issued + self::TOKEN_LIFETIME,
		], $credentials['private_key']);

		// Not through request(), which would ask for the token this call is fetching
		$response = $this->getClient()->request('POST', self::TOKEN_URL, [
			'form_params' => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion' => $assertion,
			],
			'http_errors' => false,
			'timeout' => static::TIMEOUT,
		]);

		$data = Json::decodeIfJson((string)$response->getBody());
		$token = is_array($data) ? ($data['access_token'] ?? null) : null;
		if ($response->getStatusCode() !== 200 || !is_string($token)) {
			throw new InvalidConfigException(Craft::t('traduco', 'Google refused the service account: {message}', [
				'message' => is_array($data) ? (string)($data['error_description'] ?? $data['error'] ?? $response->getReasonPhrase()) : $response->getReasonPhrase(),
			]));
		}

		// A minute short of what Google says, so a token can't expire between being read here and reaching Google
		$expires = is_array($data) && is_numeric($data['expires_in'] ?? null) ? (int)$data['expires_in'] : self::TOKEN_LIFETIME;
		$cache->set($key, $token, max($expires - 60, 60));

		return $token;
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	private static function signAssertion(array $claims, string $privateKey): string
	{
		$encode = static fn(array $part): string => rtrim(strtr(base64_encode(Json::encode($part)), '+/', '-_'), '=');
		$input = $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($claims);

		if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
			throw new InvalidConfigException('Couldn’t sign the Google service account assertion. Check the private key in the key file.');
		}

		return $input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
	}

	/**
	 * Keeps what a project reports, per credential
	 *
	 * @template T
	 * @param callable(): T $build
	 * @return T
	 */
	private function remember(string $name, callable $build, int $duration = self::LANGUAGE_CACHE_DURATION): mixed
	{
		$key = ['traduco', 'google-advanced', $name, $this->getLocation(), substr(hash('sha256', $this->apiKey), 0, 16)];

		return Craft::$app->getCache()->getOrSet($key, $build, $duration);
	}
}
