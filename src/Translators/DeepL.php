<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\{
	BaseTranslator,
	RephraserInterface,
	TranslatorSettings,
};
use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use DeepL\{
	DeepLClient,
	DeepLException,
	GlossaryUtils,
	Language,
	LanguageCode,
	LanguageFeature,
	LanguageResource,
	MultilingualGlossaryDictionaryEntries,
	MultilingualGlossaryDictionaryInfo,
	TranslateTextOptions,
};
use yii\base\InvalidConfigException;

class DeepL extends BaseTranslator implements RephraserInterface
{
	/**
	 * Target languages DeepL only accepts with a regional variant
	 */
	public const LANGUAGE_REGIONAL_VARIANTS = [
		'en' => 'en-GB',
		'pt' => 'pt-PT',
	];

	/**
	 * How long DeepL's languages and their features are kept. They change a few times a year, and a new API key
	 * reaches a cache entry of its own, so nothing has to be cleared to pick up a plan change.
	 */
	public const LANGUAGE_CACHE_DURATION = 86_400;

	/**
	 * How long the account's glossaries are kept. Shorter than the languages, as an editor who just made a glossary in
	 * DeepL comes straight here to pick it, while a queue run of many elements still reads them once.
	 */
	public const GLOSSARY_CACHE_DURATION = 300;

	/**
	 * How long the character usage is kept. Every translation moves it, so this is short enough that a key which has
	 * just run out stops being offered, and long enough that queueing ten elements in a row reads it once.
	 */
	public const USAGE_CACHE_DURATION = 60;

	/** How long the account's style rules and translation memories are kept, the same reasoning as the glossaries */
	public const RESOURCE_CACHE_DURATION = 300;

	private ?DeepLClient $client = null;

	/** @var string[]|null uppercase DeepL codes, or null until they're looked up */
	private ?array $_targetLanguages = null;

	/** @var string[]|null uppercase DeepL codes, or null until they're looked up */
	private ?array $_sourceLanguages = null;

	/** @var array<string, array<string, string[]>> feature names by lowercase language code, by DeepL resource */
	private array $_languageFeatures = [];

	/** @var array<string, array{name: string, dictionaries: array<int, array{source: string, target: string, entries: int}>}>|null */
	private ?array $_glossaries = null;

	/** @var array<string, array{name: string, language: string, instructions: array<string, array{label: string, prompt: string}>}>|null */
	private ?array $_styleRules = null;

	/** @var array<string, array{name: string, source: string, targets: string[], segments: int}>|null */
	private ?array $_translationMemories = null;

	public function __construct(private readonly string $apiKey, array $settings = [])
	{
		parent::__construct($apiKey, $settings);

		if (trim($apiKey) === '') {
			throw new InvalidConfigException('DeepL needs an API key.');
		}
	}

	public static function displayName(): string
	{
		return Craft::t('traduco', 'DeepL');
	}

	public static function createSettings(array $values = []): ?TranslatorSettings
	{
		return DeepLSettings::create($values);
	}

	/**
	 * The multilingual client, which is the Translator the SDK has always had plus the v3 endpoints: glossaries,
	 * languages and their features, style rules and translation memories
	 */
	public function getClient(): DeepLClient
	{
		if ($this->client !== null) return $this->client;

		try {
			return $this->client = new DeepLClient($this->apiKey);
		} catch (DeepLException $e) {
			throw new InvalidConfigException('Couldn’t set up the DeepL client: ' . $e->getMessage(), previous: $e);
		}
	}

	public function setClient(?DeepLClient $client): void
	{
		$this->client = $client;
	}

	/**
	 * @param FieldInterface|null $field the field the text belongs to, null for an attribute like a title or a slug
	 * @return array<string, mixed>
	 */
	public function getTranslateOptions(ElementInterface $source, string $targetLanguage, ?FieldInterface $field = null): array
	{
		$options = [];

		// The field decides this, not the value: sniffing the value sends a Plain Text value holding a bare `<` as
		// markup, and a CKEditor value that happens to have none as plain text
		$isRichText = $field !== null && Parser::isRichTextField($field);
		if ($isRichText) {
			$options[TranslateTextOptions::TAG_HANDLING] = 'html';
		}

		$settings = $this->getDeepLSettings();
		if ($settings === null) return $options;

		if ($settings->context !== '') {
			$options[TranslateTextOptions::CONTEXT] = $settings->context;
		}
		if ($settings->splitSentences !== DeepLSettings::DEFAULT) {
			// Splitting on line breaks is for plain text. Markup carries line breaks that aren't sentence ends, which
			// is why DeepL's own default with tag handling on is not to split on them, so a value's own markup can't
			// be split mid-sentence by a setting meant for the plain text fields.
			$options[TranslateTextOptions::SPLIT_SENTENCES] = $isRichText && $settings->splitSentences === DeepLSettings::SPLIT_SENTENCES_ALL
				? DeepLSettings::SPLIT_SENTENCES_NO_NEWLINES
				: $settings->splitSentences;
		}
		// DeepL rejects formality for the languages that don't have it, so it only goes out where it reports support
		if ($settings->formality !== DeepLSettings::DEFAULT && $this->supportsFeature($targetLanguage, LanguageFeature::FEATURE_FORMALITY)) {
			$options[TranslateTextOptions::FORMALITY] = $settings->formality;
		}

		$glossaryIds = $this->getGlossaryIdsFor($settings->glossaries, $source->getLanguage(), $targetLanguage);
		if ($glossaryIds !== []) {
			$options[TranslateTextOptions::GLOSSARY_IDS] = $glossaryIds;
		}

		$styleId = $this->getStyleIdFor($settings->styleRules, $targetLanguage);
		if ($styleId !== null) {
			$options[TranslateTextOptions::STYLE_ID] = $styleId;
		}

		$memoryId = $this->getTranslationMemoryIdFor($settings->translationMemories, $source->getLanguage(), $targetLanguage);
		if ($memoryId !== null) {
			$options[TranslateTextOptions::TRANSLATION_MEMORY_ID] = $memoryId;
			$options[TranslateTextOptions::TRANSLATION_MEMORY_THRESHOLD] = $settings->translationMemoryThreshold;
		}

		return $options;
	}

	/**
	 * The account's glossaries, by ID, with the language pairs each one has a dictionary for. An empty array where
	 * they can't be read, so a key without glossary access translates on without them.
	 *
	 * @return array<string, array{name: string, dictionaries: array<int, array{source: string, target: string, entries: int}>}>
	 */
	public function getGlossaries(): array
	{
		if ($this->_glossaries !== null) return $this->_glossaries;

		try {
			$glossaries = $this->remember('glossaries', function (): array {
				$glossaries = [];
				foreach ($this->getClient()->listMultilingualGlossaries() as $glossary) {
					$glossaries[$glossary->glossaryId] = [
						'name' => (string)$glossary->name,
						'dictionaries' => array_map(fn(MultilingualGlossaryDictionaryInfo $dictionary): array => [
							'source' => $dictionary->sourceLang,
							'target' => $dictionary->targetLang,
							'entries' => $dictionary->entryCount,
						], $glossary->dictionaries),
					];
				}

				return $glossaries;
			}, self::GLOSSARY_CACHE_DURATION);
		} catch (DeepLException $e) {
			// Caught rather than cached, the way the language features are, see getLanguageFeatures()
			Craft::warning('Couldn’t read DeepL’s glossaries: ' . $e->getMessage(), 'traduco');
			$glossaries = [];
		}

		return $this->_glossaries = $glossaries;
	}

	/**
	 * The account's style rules, by ID, each with the custom instructions it carries. Empty where they can't be read.
	 *
	 * @return array<string, array{name: string, language: string, instructions: array<string, array{label: string, prompt: string}>}>
	 */
	public function getStyleRules(): array
	{
		if ($this->_styleRules !== null) return $this->_styleRules;

		try {
			$rules = $this->remember('style-rules', function (): array {
				$rules = [];
				foreach ($this->getClient()->getAllStyleRules(detailed: true) as $rule) {
					$instructions = [];
					foreach ((array)$rule->customInstructions as $instruction) {
						$instructions[(string)$instruction->id] = [
							'label' => (string)$instruction->label,
							'prompt' => (string)$instruction->prompt,
						];
					}

					$rules[(string)$rule->styleId] = [
						'name' => (string)$rule->name,
						'language' => (string)$rule->language,
						'instructions' => $instructions,
					];
				}

				return $rules;
			}, self::RESOURCE_CACHE_DURATION);
		} catch (DeepLException $e) {
			Craft::warning('Couldn’t read DeepL’s style rules: ' . $e->getMessage(), 'traduco');
			$rules = [];
		}

		return $this->_styleRules = $rules;
	}

	public function createStyleRule(string $name, string $language): string
	{
		$rule = $this->getClient()->createStyleRule($name, $language);
		$this->forget('style-rules', '_styleRules');

		return (string)$rule->styleId;
	}

	public function renameStyleRule(string $styleId, string $name): void
	{
		$this->getClient()->updateStyleRuleName($styleId, $name);
		$this->forget('style-rules', '_styleRules');
	}

	public function deleteStyleRule(string $styleId): void
	{
		$this->getClient()->deleteStyleRule($styleId);
		$this->forget('style-rules', '_styleRules');
	}

	public function saveStyleRuleInstruction(string $styleId, string $label, string $prompt, ?string $instructionId = null): void
	{
		if ($instructionId === null) {
			$this->getClient()->createStyleRuleCustomInstruction($styleId, $label, $prompt);
		} else {
			$this->getClient()->updateStyleRuleCustomInstruction($styleId, $instructionId, $label, $prompt);
		}

		$this->forget('style-rules', '_styleRules');
	}

	public function deleteStyleRuleInstruction(string $styleId, string $instructionId): void
	{
		$this->getClient()->deleteStyleRuleCustomInstruction($styleId, $instructionId);
		$this->forget('style-rules', '_styleRules');
	}

	/**
	 * The account's translation memories, by ID. Empty where they can't be read.
	 *
	 * @return array<string, array{name: string, source: string, targets: string[], segments: int}>
	 */
	public function getTranslationMemories(): array
	{
		if ($this->_translationMemories !== null) return $this->_translationMemories;

		try {
			$memories = $this->remember('translation-memories', function (): array {
				$memories = [];
				foreach ($this->getClient()->listTranslationMemories() as $memory) {
					$memories[(string)$memory->translationMemoryId] = [
						'name' => (string)$memory->name,
						'source' => (string)$memory->sourceLanguage,
						'targets' => array_map('strval', (array)$memory->targetLanguages),
						'segments' => (int)$memory->segmentCount,
					];
				}

				return $memories;
			}, self::RESOURCE_CACHE_DURATION);
		} catch (DeepLException $e) {
			Craft::warning('Couldn’t read DeepL’s translation memories: ' . $e->getMessage(), 'traduco');
			$memories = [];
		}

		return $this->_translationMemories = $memories;
	}

	public function deleteTranslationMemory(string $memoryId): void
	{
		$this->getClient()->deleteTranslationMemory($memoryId);
		$this->forget('translation-memories', '_translationMemories');
	}

	/**
	 * The chosen style rule, where it's one the account still has and the pair can use it
	 *
	 * @param string[] $styleIds
	 */
	public function getStyleIdFor(array $styleIds, string $targetLocale): ?string
	{
		if ($styleIds === [] || !Plugin::getInstance()->isPro()) return null;

		$rules = $this->getStyleRules();
		$target = strtolower(LanguageCode::removeRegionalVariant($this->getTargetLanguageCode($targetLocale)));

		foreach ($styleIds as $styleId) {
			$rule = $rules[$styleId] ?? null;
			// A style rule is written for one language, so it only applies where that is the target
			if ($rule !== null && strtolower(LanguageCode::removeRegionalVariant($rule['language'])) === $target) return $styleId;
		}

		return null;
	}

	/**
	 * The chosen translation memory, where it's one the account still has and it covers the pair
	 *
	 * @param string[] $memoryIds
	 */
	public function getTranslationMemoryIdFor(array $memoryIds, string $sourceLocale, string $targetLocale): ?string
	{
		if ($memoryIds === [] || !Plugin::getInstance()->isPro()) return null;

		$source = $this->getSourceLanguageCode($sourceLocale);
		if ($source === null) return null;

		$memories = $this->getTranslationMemories();
		$target = strtolower($this->getTargetLanguageCode($targetLocale));

		foreach ($memoryIds as $memoryId) {
			$memory = $memories[$memoryId] ?? null;
			if ($memory === null) continue;

			$targets = array_map('strtolower', $memory['targets']);
			if (strtolower($memory['source']) === strtolower($source) && in_array($target, $targets, true)) return $memoryId;
		}

		return null;
	}

	/**
	 * Rewrites a value in the language it's already in, through DeepL Write
	 */
	public function rephrase(string $text, string $language): string
	{
		if (trim($text) === '') return $text;

		// DeepL Write bills the same characters translating does
		$this->countCharacters($text);

		$settings = $this->getDeepLSettings();
		$result = $this->getClient()->rephraseText(
			$text,
			// The variant matters: without it an en-GB value can come back in en-US
			$this->getTargetLanguageCode($language),
			$settings === null ? [] : $settings->getRephraseOptions(),
		);

		return (string)$result;
	}

	/**
	 * DeepL Write is the one thing here a Free key can't do. The key's own suffix says which it is, so this costs
	 * no request, which matters because the field action menu asks on every element edit screen.
	 */
	public function getRephraseUnavailableReason(): ?string
	{
		if (!DeepLClient::isAuthKeyFreeAccount($this->apiKey)) return null;

		return Craft::t('traduco', 'DeepL Write needs a paid DeepL key. This one is a Free key.');
	}

	/**
	 * How many characters the key has spent of its allowance this billing period, or null where that can't be read.
	 * Only the character allowance: DeepL's document limits say nothing about translating text, and Traduco doesn't
	 * translate documents.
	 *
	 * @return array{count: int, limit: int}|null
	 */
	public function getCharacterUsage(): ?array
	{
		// Inside remember(), so a key that can't be read caches as unknown rather than retrying with backoff on
		// every queue push
		return $this->remember('usage', function (): ?array {
			try {
				$character = $this->getClient()->getUsage()->character;
			} catch (DeepLException $e) {
				Craft::warning('Couldn’t read DeepL’s usage: ' . $e->getMessage(), 'traduco');

				return null;
			}

			// The SDK leaves the detail null unless DeepL sent both figures, and a limit of zero says nothing either
			if ($character === null || $character->limit <= 0) return null;

			return ['count' => (int)$character->count, 'limit' => (int)$character->limit];
		}, self::USAGE_CACHE_DURATION);
	}

	/**
	 * Says the key has nothing left to translate with, so a translation isn't queued only to fail. Null wherever
	 * that isn't known to be the case, usage that can't be read included: a failed lookup is no evidence of a quota.
	 */
	public function getQuotaMessage(): ?string
	{
		$usage = $this->getCharacterUsage();
		if ($usage === null || $usage['count'] < $usage['limit']) return null;

		return Craft::t('traduco', 'This DeepL key has used all {limit} characters of its allowance for this billing period.', [
			'limit' => Craft::$app->getFormatter()->asDecimal($usage['limit'], 0),
		]);
	}

	/**
	 * One glossary, or null where the account doesn't have it
	 *
	 * @return array{name: string, dictionaries: array<int, array{source: string, target: string, entries: int}>}|null
	 */
	public function getGlossary(string $glossaryId): ?array
	{
		return $this->getGlossaries()[$glossaryId] ?? null;
	}

	/**
	 * One dictionary's entries, by source term
	 *
	 * @return array<string, string>
	 */
	public function getGlossaryEntries(string $glossaryId, string $sourceLanguage, string $targetLanguage): array
	{
		$dictionaries = $this->getClient()->getMultilingualGlossaryEntries($glossaryId, $sourceLanguage, $targetLanguage);
		$dictionary = reset($dictionaries);

		return $dictionary === false ? [] : $dictionary->entries;
	}

	/**
	 * Creates a glossary with one dictionary and returns its ID
	 *
	 * @param array<string, string> $entries by source term
	 */
	public function createGlossary(string $name, string $sourceLanguage, string $targetLanguage, array $entries): string
	{
		$glossary = $this->getClient()->createMultilingualGlossary($name, [
			new MultilingualGlossaryDictionaryEntries($sourceLanguage, $targetLanguage, $entries),
		]);
		$this->forgetGlossaries();

		return $glossary->glossaryId;
	}

	public function renameGlossary(string $glossaryId, string $name): void
	{
		$this->getClient()->updateMultilingualGlossary($glossaryId, $name, null);
		$this->forgetGlossaries();
	}

	/**
	 * Replaces a dictionary's entries, or adds the dictionary where the glossary has none for the pair
	 *
	 * @param array<string, string> $entries by source term
	 */
	public function saveGlossaryDictionary(string $glossaryId, string $sourceLanguage, string $targetLanguage, array $entries): void
	{
		$this->getClient()->replaceMultilingualGlossaryDictionary(
			$glossaryId,
			new MultilingualGlossaryDictionaryEntries($sourceLanguage, $targetLanguage, $entries),
		);
		$this->forgetGlossaries();
	}

	public function deleteGlossary(string $glossaryId): void
	{
		$this->getClient()->deleteMultilingualGlossary($glossaryId);
		$this->forgetGlossaries();
	}

	public function deleteGlossaryDictionary(string $glossaryId, string $sourceLanguage, string $targetLanguage): void
	{
		$this->getClient()->deleteMultilingualGlossaryDictionary($glossaryId, null, $sourceLanguage, $targetLanguage);
		$this->forgetGlossaries();
	}

	/**
	 * Glossary entries as the TSV DeepL stores them, which is what the screens edit
	 *
	 * @param array<string, string> $entries by source term
	 */
	public static function glossaryEntriesToTsv(array $entries): string
	{
		return GlossaryUtils::convertToTsv($entries);
	}

	/**
	 * Reads glossary entries out of a TSV or CSV file's contents, the two formats DeepL's own exports come in
	 *
	 * @return array<string, string> by source term
	 */
	public static function parseGlossaryEntries(string $contents, bool $isCsv = false): array
	{
		if (!$isCsv) return GlossaryUtils::fromTsv($contents);

		// DeepL takes CSV only when creating a glossary, so it's turned into entries here and sent as TSV throughout
		$entries = [];
		foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
			if (trim($line) === '') continue;

			$columns = str_getcsv($line);
			if (count($columns) < 2 || trim((string)$columns[0]) === '') continue;

			$entries[trim((string)$columns[0])] = trim((string)$columns[1]);
		}
		GlossaryUtils::validateEntries($entries);

		return $entries;
	}

	/**
	 * The DeepL codes a glossary can be written for, by site language, for the screens that build one. Site languages
	 * DeepL would only detect are left out of the sources, see getUndetectableGlossarySources().
	 *
	 * @return array{sources: array<string, string>, targets: array<string, string>} DeepL codes by site language
	 */
	public function getGlossaryLanguageCodes(): array
	{
		$sources = [];
		$targets = [];
		foreach (Craft::$app->getSites()->getAllSites() as $site) {
			$source = $this->getSourceLanguageCode($site->language);
			if ($source !== null) $sources[$site->language] = $source;

			$targets[$site->language] = $this->getTargetLanguageCode($site->language);
		}

		return ['sources' => $sources, 'targets' => $targets];
	}

	/**
	 * Site languages DeepL would have to detect. A glossary needs an explicit source language, so nothing translated
	 * out of one of these can use one.
	 *
	 * @return string[] site languages
	 */
	public function getUndetectableGlossarySources(): array
	{
		$languages = [];
		foreach (Craft::$app->getSites()->getAllSites() as $site) {
			if ($this->getSourceLanguageCode($site->language) === null) $languages[$site->language] = $site->language;
		}

		return array_values($languages);
	}

	/**
	 * The glossaries among the chosen ones that have a dictionary for this pair. DeepL doesn't document which of two
	 * glossaries covering the same pair wins, so DeepLSettings refuses that and this answers one ID at most.
	 *
	 * @param string[] $glossaryIds the chosen glossaries, see DeepLSettings::$glossaries
	 * @return string[]
	 */
	private function getGlossaryIdsFor(array $glossaryIds, string $sourceLocale, string $targetLocale): array
	{
		if ($glossaryIds === [] || !Plugin::getInstance()->isPro()) return [];

		// DeepL refuses a glossary without an explicit source language, see translate()
		$source = $this->getSourceLanguageCode($sourceLocale);
		if ($source === null) return [];

		$glossaries = $this->getGlossaries();
		$target = $this->getTargetLanguageCode($targetLocale);

		$matching = array_filter(
			$glossaryIds,
			fn(string $glossaryId): bool => isset($glossaries[$glossaryId]) && self::hasDictionaryFor(
				$glossaries[$glossaryId]['dictionaries'],
				$source,
				$target,
			),
		);

		// DeepL takes five at most, which the settings make unreachable, so this is only ever belt and braces
		return array_slice(array_values($matching), 0, 5);
	}

	/**
	 * Whether one of the dictionaries covers the pair
	 *
	 * @param array<int, array{source: string, target: string, entries: int}> $dictionaries
	 */
	public static function hasDictionaryFor(array $dictionaries, string $source, string $target): bool
	{
		foreach ($dictionaries as $dictionary) {
			if (self::codesMatch($dictionary['source'], $source) && self::codesMatch($dictionary['target'], $target)) return true;
		}

		return false;
	}

	/**
	 * Whether a dictionary's language code covers one Traduco would translate with. A dictionary for a base language
	 * covers its regional variants, and one for a variant is covered by the base language, but two variants of the
	 * same language don't stand in for each other: en-GB and en-US are different targets to DeepL.
	 */
	private static function codesMatch(string $dictionaryCode, string $code): bool
	{
		$dictionaryCode = strtolower($dictionaryCode);
		$code = strtolower($code);
		if ($dictionaryCode === $code) return true;

		$dictionaryBase = LanguageCode::removeRegionalVariant($dictionaryCode);
		$base = LanguageCode::removeRegionalVariant($code);
		if ($dictionaryBase !== $base) return false;

		return $dictionaryCode === $dictionaryBase || $code === $base;
	}

	/**
	 * Whether DeepL reports a feature for a site language, for example a formality or a glossary. Languages it doesn't
	 * report, and a key its languages can't be read with, answer false: an option DeepL doesn't take fails the request.
	 *
	 * @param string $locale a Craft site language, or a DeepL language code
	 * @param string $feature one of DeepL's LanguageFeature::FEATURE_* constants
	 */
	public function supportsFeature(string $locale, string $feature, string $resource = LanguageResource::RESOURCE_TRANSLATE_TEXT): bool
	{
		$features = $this->getLanguageFeatures($resource);
		if ($features === []) return false;

		$code = strtolower($this->getTargetLanguageCode($locale));

		// e.g. en-GB has its own entry, while de-AT is reported as de
		foreach (array_unique([$code, LanguageCode::removeRegionalVariant($code)]) as $candidate) {
			if (isset($features[$candidate])) return in_array($feature, $features[$candidate], true);
		}

		return false;
	}

	/**
	 * @param string $text
	 * @param string $targetLanguage Craft locale or DeepL language code
	 * @param string|null $sourceLanguage Craft locale or DeepL language code, null to detect it
	 * @param array<string, mixed> $options DeepL's own TranslateTextOptions, see getTranslateOptions()
	 * @return string
	 */
	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string
	{
		if (trim($text) === '') return $text;

		$source = $sourceLanguage !== null ? $this->getSourceLanguageCode($sourceLanguage) : null;

		// DeepL refuses the whole request for a glossary without an explicit source language, so it's dropped instead
		if ($source === null && isset($options[TranslateTextOptions::GLOSSARY_IDS])) {
			Craft::warning("Left the glossary out of a translation into $targetLanguage, as DeepL has to detect the source language.", 'traduco');
			unset($options[TranslateTextOptions::GLOSSARY_IDS]);
		}

		// Counted before the call, so a request that fails halfway through an element still says what was billed
		$this->countCharacters($text);

		return (string)$this->getClient()->translateText(
			$text,
			$source,
			$this->getTargetLanguageCode($targetLanguage),
			$options,
		);
	}

	private function getDeepLSettings(): ?DeepLSettings
	{
		return $this->settingsModel instanceof DeepLSettings ? $this->settingsModel : null;
	}

	private function getTargetLanguageCode(string $locale): string
	{
		$code = strtoupper(str_replace('_', '-', $locale));
		if (in_array($code, $this->getTargetLanguages(), true)) return $code;

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

		return in_array(strtoupper($language), $this->getSourceLanguages(), true) ? $language : null;
	}

	/**
	 * @return string[] uppercase DeepL target codes
	 */
	private function getTargetLanguages(): array
	{
		return $this->_targetLanguages ??= $this->remember('target-languages', fn(): array => array_map(
			fn(Language $language): string => strtoupper($language->code),
			$this->getClient()->getTargetLanguages(),
		));
	}

	/**
	 * @return string[] uppercase DeepL source codes
	 */
	private function getSourceLanguages(): array
	{
		return $this->_sourceLanguages ??= $this->remember('source-languages', fn(): array => array_map(
			fn(Language $language): string => strtoupper($language->code),
			$this->getClient()->getSourceLanguages(),
		));
	}

	/**
	 * The features DeepL reports per language, from the v3 endpoint the older language lists don't have. An empty
	 * array where they can't be read, so every feature answers "not supported" and translating goes on without them.
	 *
	 * @return array<string, string[]> feature names by lowercase language code
	 */
	private function getLanguageFeatures(string $resource): array
	{
		if (isset($this->_languageFeatures[$resource])) return $this->_languageFeatures[$resource];

		try {
			$features = $this->remember("features-$resource", function () use ($resource): array {
				$features = [];
				foreach ($this->getClient()->getLanguagesForResource($resource) as $language) {
					$features[strtolower($language->code)] = array_keys($language->features);
				}

				return $features;
			});
		} catch (DeepLException $e) {
			// Caught rather than cached: a key that can't read them keeps translating, without the options they gate
			Craft::warning("Couldn’t read DeepL’s languages for $resource: " . $e->getMessage(), 'traduco');
			$features = [];
		}

		return $this->_languageFeatures[$resource] = $features;
	}

	/**
	 * Keeps what DeepL reports about its languages, per API key: a Free and a Pro key reach different hosts and come
	 * with different features.
	 *
	 * @template T
	 * @param callable(): T $build
	 * @return T
	 */
	private function remember(string $name, callable $build, int $duration = self::LANGUAGE_CACHE_DURATION): mixed
	{
		return Craft::$app->getCache()->getOrSet($this->getCacheKey($name), $build, $duration);
	}

	/**
	 * Drops what's kept about the account's glossaries, so a screen that just changed one lists it as it now is
	 */
	private function forgetGlossaries(): void
	{
		$this->forget('glossaries', '_glossaries');
	}

	/**
	 * Drops what's kept about one kind of thing the account holds, so a screen that just changed one lists it as it
	 * now is
	 */
	private function forget(string $name, string $property): void
	{
		$this->$property = null;
		Craft::$app->getCache()->delete($this->getCacheKey($name));
	}

	/**
	 * @return array<int, string>
	 */
	private function getCacheKey(string $name): array
	{
		return ['traduco', 'deepl', $name, substr(hash('sha256', $this->apiKey), 0, 16)];
	}
}
