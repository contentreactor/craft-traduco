<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\TranslatorSettings;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\web\View;
use DeepL\RephraseTextOptions;

/**
 * DeepL's cheap per-request options: the ones that cost nothing and need no management screen of their own.
 * Tag handling isn't among them, as it follows the field being translated rather than a setting, see
 * DeepL::getTranslateOptions().
 */
class DeepLSettings extends TranslatorSettings
{
	/** Whatever DeepL does by default, which depends on the rest of the request: the option isn't sent at all */
	public const DEFAULT = '';

	public const FORMALITY_MORE = 'prefer_more';

	public const FORMALITY_LESS = 'prefer_less';

	/** Split on punctuation and on line breaks */
	public const SPLIT_SENTENCES_ALL = '1';

	/** Split on punctuation only, so a value's own line breaks stay inside a sentence */
	public const SPLIT_SENTENCES_NO_NEWLINES = 'nonewlines';

	/** Translate each value as a single sentence */
	public const SPLIT_SENTENCES_NONE = '0';

	/**
	 * DeepL Write takes a writing style or a tone, never both, so the two are one setting here and the prefix says
	 * which of the API's two options a value belongs to
	 */
	public const REPHRASE_STYLES = ['academic', 'business', 'casual', 'simple'];

	public const REPHRASE_TONES = ['confident', 'diplomatic', 'enthusiastic', 'friendly'];

	/**
	 * What the content is about, sent with every translation and never translated itself. DeepL doesn't bill it, and
	 * it's what tells a short value's sense apart where the value has no sentence around it to go by.
	 */
	public string $context = '';

	/** How formally DeepL addresses the reader, see the FORMALITY_* constants */
	public string $formality = self::DEFAULT;

	/** Where DeepL splits sentences, see the SPLIT_SENTENCES_* constants */
	public string $splitSentences = self::DEFAULT;

	/**
	 * How DeepL Write rewrites a value: `style:<name>` or `tone:<name>`, see getRephraseStyleOptions(). One setting
	 * rather than two, because DeepL refuses a request carrying both.
	 */
	public string $rephraseStyle = self::DEFAULT;

	/**
	 * The glossaries to translate with, by DeepL glossary ID. Each one covers the language pairs it has a dictionary
	 * for, so several can be chosen and DeepL is only ever sent the one that covers the pair being translated.
	 *
	 * @var string[]
	 */
	public array $glossaries = [];

	/**
	 * The style rules to translate with, by DeepL style ID. A rule is written for one language, so only the one whose
	 * language is the target is ever sent.
	 *
	 * @var string[]
	 */
	public array $styleRules = [];

	/**
	 * The translation memories to translate with, by DeepL memory ID. Only the one covering the pair is sent.
	 *
	 * @var string[]
	 */
	public array $translationMemories = [];

	/** How close a stored segment has to be before DeepL reuses it, as a percentage */
	public int $translationMemoryThreshold = 100;

	public function getSettingsHtml(): string
	{
		return Craft::$app->getView()->renderTemplate('traduco/settings/_system/deepl.twig', [
			'settings' => $this,
			'plugin' => Plugin::getInstance(),
		], View::TEMPLATE_MODE_CP);
	}

	/**
	 * The account's glossaries as checkbox options, each labelled with the language pairs it covers. Empty where
	 * DeepL isn't the selected translator yet, or its key can't read them.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getGlossaryOptions(): array
	{
		$options = [];
		foreach ($this->getGlossaries() as $glossaryId => $glossary) {
			$pairs = array_map(
				fn(array $dictionary): string => "$dictionary[source] → $dictionary[target]",
				$glossary['dictionaries'],
			);
			$options[] = [
				'value' => $glossaryId,
				'label' => $pairs === []
					? $glossary['name']
					: Craft::t('traduco', '{name} ({pairs})', ['name' => $glossary['name'], 'pairs' => implode(', ', $pairs)]),
			];
		}

		return $options;
	}

	/**
	 * What the key has spent of its character allowance, for the settings screen, or null where that can't be read
	 *
	 * @return array{count: int, limit: int}|null
	 */
	public function getCharacterUsage(): ?array
	{
		return $this->getTranslator()?->getCharacterUsage();
	}

	/**
	 * The account's style rules as checkbox options, each labelled with the language it's written for
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getStyleRuleOptions(): array
	{
		$options = [];
		foreach ($this->getTranslator()?->getStyleRules() ?? [] as $styleId => $rule) {
			$options[] = [
				'value' => $styleId,
				'label' => Craft::t('traduco', '{name} ({language})', ['name' => $rule['name'], 'language' => $rule['language']]),
			];
		}

		return $options;
	}

	/**
	 * The account's translation memories as checkbox options, each labelled with the languages it covers
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getTranslationMemoryOptions(): array
	{
		$options = [];
		foreach ($this->getTranslator()?->getTranslationMemories() ?? [] as $memoryId => $memory) {
			$options[] = [
				'value' => $memoryId,
				'label' => Craft::t('traduco', '{name} ({source} → {targets})', [
					'name' => $memory['name'],
					'source' => $memory['source'],
					'targets' => implode(', ', $memory['targets']),
				]),
			];
		}

		return $options;
	}

	/**
	 * Why the glossaries can't be chosen, or null where they can
	 */
	public function getGlossaryWarning(): ?string
	{
		if ($this->getGlossaries() !== []) return null;

		return $this->getTranslator() === null
			? Craft::t('traduco', 'Select DeepL and save its API key to choose from your glossaries.')
			: Craft::t('traduco', 'This key reports no glossaries. Create one in your DeepL account, or check that the key may read glossaries.');
	}

	/**
	 * Site languages nothing can be translated out of with a glossary, as DeepL would have to detect them
	 *
	 * @return string[]
	 */
	public function getUndetectableGlossarySources(): array
	{
		return $this->getTranslator()?->getUndetectableGlossarySources() ?? [];
	}

	/**
	 * Refuses glossaries the account no longer has, and two that cover the same language pair: DeepL doesn't document
	 * which of those wins, and Traduco only ever sends one, so the choice has to be made here.
	 */
	public function validateGlossaries(string $attribute): void
	{
		$glossaries = $this->getGlossaries();
		// Nothing is refused on what couldn't be read, so an unreachable DeepL doesn't block saving the other settings
		if ($glossaries === []) return;

		$pairs = [];
		foreach ($this->glossaries as $glossaryId) {
			if (!isset($glossaries[$glossaryId])) {
				$this->addError($attribute, Craft::t('traduco', 'One of the chosen glossaries isn’t in your DeepL account any more.'));

				return;
			}

			foreach ($glossaries[$glossaryId]['dictionaries'] as $dictionary) {
				$pair = strtolower("$dictionary[source]|$dictionary[target]");
				if (isset($pairs[$pair])) {
					$this->addError($attribute, Craft::t('traduco', '“{first}” and “{second}” both translate {source} into {target}. Choose one of them.', [
						'first' => $glossaries[$pairs[$pair]]['name'],
						'second' => $glossaries[$glossaryId]['name'],
						'source' => $dictionary['source'],
						'target' => $dictionary['target'],
					]));

					return;
				}
				$pairs[$pair] = $glossaryId;
			}
		}
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getFormalityOptions(): array
	{
		return [
			['value' => self::DEFAULT, 'label' => Craft::t('traduco', 'DeepL’s default')],
			['value' => self::FORMALITY_MORE, 'label' => Craft::t('traduco', 'More formal')],
			['value' => self::FORMALITY_LESS, 'label' => Craft::t('traduco', 'Less formal')],
		];
	}

	/**
	 * @return array<int, array{value: string, label: string}|array{optgroup: string}>
	 */
	public function getRephraseStyleOptions(): array
	{
		$labels = [
			'academic' => Craft::t('traduco', 'Academic'),
			'business' => Craft::t('traduco', 'Business'),
			'casual' => Craft::t('traduco', 'Casual'),
			'simple' => Craft::t('traduco', 'Simple'),
			'confident' => Craft::t('traduco', 'Confident'),
			'diplomatic' => Craft::t('traduco', 'Diplomatic'),
			'enthusiastic' => Craft::t('traduco', 'Enthusiastic'),
			'friendly' => Craft::t('traduco', 'Friendly'),
		];

		$options = [['value' => self::DEFAULT, 'label' => Craft::t('traduco', 'DeepL’s default')]];
		$options[] = ['optgroup' => Craft::t('traduco', 'Writing style')];
		foreach (self::REPHRASE_STYLES as $style) {
			$options[] = ['value' => "style:$style", 'label' => $labels[$style]];
		}
		$options[] = ['optgroup' => Craft::t('traduco', 'Tone')];
		foreach (self::REPHRASE_TONES as $tone) {
			$options[] = ['value' => "tone:$tone", 'label' => $labels[$tone]];
		}

		return $options;
	}

	/**
	 * The chosen style or tone as DeepL Write's own option, empty for its default
	 *
	 * @return array<string, string>
	 */
	public function getRephraseOptions(): array
	{
		if ($this->rephraseStyle === self::DEFAULT) return [];

		[$kind, $value] = explode(':', $this->rephraseStyle, 2) + [1 => ''];

		// `prefer_` so DeepL falls back to its default where a language doesn't have the style, rather than failing
		return match ($kind) {
			'style' => [RephraseTextOptions::WRITING_STYLE => "prefer_$value"],
			'tone' => [RephraseTextOptions::TONE => "prefer_$value"],
			default => [],
		};
	}

	/**
	 * Why values can't be rephrased as things stand, or null where they can
	 */
	public function getRephraseWarning(): ?string
	{
		$translator = $this->getTranslator();
		if ($translator === null) {
			return Craft::t('traduco', 'Select DeepL and save its API key to rephrase values.');
		}

		return $translator->getRephraseUnavailableReason();
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getSplitSentencesOptions(): array
	{
		return [
			['value' => self::DEFAULT, 'label' => Craft::t('traduco', 'DeepL’s default')],
			['value' => self::SPLIT_SENTENCES_ALL, 'label' => Craft::t('traduco', 'On punctuation and on line breaks')],
			['value' => self::SPLIT_SENTENCES_NO_NEWLINES, 'label' => Craft::t('traduco', 'On punctuation only')],
			['value' => self::SPLIT_SENTENCES_NONE, 'label' => Craft::t('traduco', 'Never: translate each value as one sentence')],
		];
	}

	public function attributeLabels(): array
	{
		return [
			'context' => Craft::t('traduco', 'Context'),
			'formality' => Craft::t('traduco', 'Formality'),
			'splitSentences' => Craft::t('traduco', 'Sentence Splitting'),
			'glossaries' => Craft::t('traduco', 'Glossaries'),
			'rephraseStyle' => Craft::t('traduco', 'Rephrasing Style'),
			'styleRules' => Craft::t('traduco', 'Style Rules'),
			'translationMemories' => Craft::t('traduco', 'Translation Memories'),
			'translationMemoryThreshold' => Craft::t('traduco', 'Reuse Threshold'),
		];
	}

	public function attributeHints(): array
	{
		return [
			'context' => Craft::t('traduco', 'What this site is about, in any language. DeepL reads it to tell a word’s sense apart but never translates it, and doesn’t count it towards your character usage.'),
			'formality' => Craft::t('traduco', 'Only sent to languages DeepL reports formality for, and ignored for the rest.'),
			'splitSentences' => Craft::t('traduco', 'Where DeepL may split a value into sentences before translating it. Rich text is never split on line breaks, whichever of these is chosen, as its markup carries line breaks that aren’t sentence ends.'),
			'glossaries' => Craft::t('traduco', 'The glossaries to translate with. Each one is only used for the language pairs it has a dictionary for, so choosing several is fine as long as no two cover the same pair.'),
			'rephraseStyle' => Craft::t('traduco', 'How DeepL Write rewrites a value when a field is rephrased from its action menu. A style or a tone, as DeepL takes one or the other.'),
			'styleRules' => Craft::t('traduco', 'The style rules to translate with. Each one is written for a single language, so only the rule whose language is being translated into is used. Manage them under Styles.'),
			'translationMemories' => Craft::t('traduco', 'The translation memories to reuse translations from. Only the one covering the language pair being translated is used. Manage them under Memories.'),
			'translationMemoryThreshold' => Craft::t('traduco', 'How close a stored segment has to be, as a percentage, before DeepL reuses it instead of translating again. 100 reuses only exact matches.'),
		];
	}

	/**
	 * @return array<int, array<int|string, mixed>>
	 */
	protected function defineRules(): array
	{
		return [
			[['context'], 'safe'],
			[['context'], 'string'],
			[['context'], 'trim'],
			['formality', 'in', 'range' => array_column($this->getFormalityOptions(), 'value'), 'strict' => true],
			['splitSentences', 'in', 'range' => array_column($this->getSplitSentencesOptions(), 'value'), 'strict' => true],
			[
				'rephraseStyle',
				'in',
				'range' => fn(): array => array_column($this->getRephraseStyleOptions(), 'value'),
				'strict' => true,
				'skipOnEmpty' => false,
			],
			[['glossaries', 'styleRules', 'translationMemories'], 'safe'],
			['translationMemoryThreshold', 'integer', 'min' => 50, 'max' => 100],
			['glossaries', 'validateGlossaries', 'skipOnEmpty' => true],
		];
	}

	/**
	 * @return array<string, array{name: string, dictionaries: array<int, array{source: string, target: string, entries: int}>}>
	 */
	private function getGlossaries(): array
	{
		return $this->getTranslator()?->getGlossaries() ?? [];
	}

	/**
	 * The configured translator, where it's DeepL: these settings are drawn and saved whichever translator is
	 * selected, and only DeepL itself can say what the account holds
	 */
	private function getTranslator(): ?DeepL
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof DeepL ? $translator : null;
	}
}
