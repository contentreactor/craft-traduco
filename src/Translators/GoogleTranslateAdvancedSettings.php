<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Translators;

use ContentReactor\Traduco\Base\TranslatorSettings;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\web\View;

/**
 * What Advanced needs beyond the credential: where to call, and which of Google's general models to translate with.
 * Custom and AutoML models are left out on purpose, see getModelOptions().
 */
class GoogleTranslateAdvancedSettings extends TranslatorSettings
{
	/** The multi-region endpoint, which every general model is available in */
	public const LOCATION_GLOBAL = 'global';

	/** Google's own default, the neural machine translation model */
	public const MODEL_DEFAULT = '';

	public const MODEL_NMT = 'general/nmt';

	public const MODEL_LLM = 'general/translation-llm';

	/**
	 * The Google Cloud location requests are sent to. `global` unless data residency says otherwise, in which case a
	 * region like `europe-west1`, which has to be one the chosen model is available in.
	 */
	public string $location = self::LOCATION_GLOBAL;

	/** Which general model translates, see the MODEL_* constants */
	public string $model = self::MODEL_DEFAULT;

	/**
	 * The glossaries to translate with, by their short ID. Each one is only used for the language pair it covers, so
	 * several can be chosen, and Google is only ever sent the one that matches.
	 *
	 * @var string[]
	 */
	public array $glossaries = [];

	/** Whether a glossary's terms match whatever case they're written in */
	public bool $glossaryIgnoreCase = false;

	public function getSettingsHtml(): string
	{
		return Craft::$app->getView()->renderTemplate('traduco/settings/_system/google-advanced.twig', [
			'settings' => $this,
			'plugin' => Plugin::getInstance(),
		], View::TEMPLATE_MODE_CP);
	}

	/**
	 * Google's general models only. A custom or AutoML model is trained for one language pair and lives in one
	 * region, while this setting is sent to every target site, so offering one here would translate most sites with
	 * a model built for another. Picking a model per site pair is a feature of its own, not a wider dropdown.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getModelOptions(): array
	{
		return [
			['value' => self::MODEL_DEFAULT, 'label' => Craft::t('traduco', 'Google’s default')],
			['value' => self::MODEL_NMT, 'label' => Craft::t('traduco', 'Neural machine translation')],
			['value' => self::MODEL_LLM, 'label' => Craft::t('traduco', 'Translation LLM')],
		];
	}

	/**
	 * The model as Advanced names it in a request, or null for Google's default
	 */
	public function getModelPath(?string $projectId): ?string
	{
		if ($this->model === self::MODEL_DEFAULT || $projectId === null) return null;

		return sprintf('projects/%s/locations/%s/models/%s', $projectId, $this->location ?: self::LOCATION_GLOBAL, $this->model);
	}

	/**
	 * The project's glossaries as checkbox options, each labelled with the languages it covers
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getGlossaryOptions(): array
	{
		$options = [];
		foreach ($this->getTranslator()?->getGlossaries() ?? [] as $glossaryId => $glossary) {
			$languages = $glossary['set'] !== []
				? implode(', ', $glossary['set'])
				: "$glossary[source] → $glossary[target]";

			$options[] = [
				'value' => $glossaryId,
				'label' => Craft::t('traduco', '{name} ({languages})', ['name' => $glossary['name'], 'languages' => $languages]),
			];
		}

		return $options;
	}

	/**
	 * Why the glossaries can't be chosen, or null where they can
	 */
	public function getGlossaryWarning(): ?string
	{
		$translator = $this->getTranslator();
		if ($translator === null) {
			return Craft::t('traduco', 'Select Google Translate (Advanced) and save a service account to choose glossaries.');
		}
		if (!$translator->isGlossaryLocation()) {
			return Craft::t('traduco', 'Google keeps glossaries per region, so the “global” location has none. Set a region above to use them.');
		}
		if ($translator->getGlossaries() === []) {
			return Craft::t('traduco', 'This project reports no glossaries. Create one under Glossaries, from a file in your own Cloud Storage bucket.');
		}

		return null;
	}

	/**
	 * Why Advanced can't translate as it's set up, or null where it can
	 */
	public function getCredentialWarning(): ?string
	{
		$translator = $this->getTranslator();
		if ($translator === null) {
			return Craft::t('traduco', 'Select Google Translate (Advanced) and save the path to a service account key file.');
		}

		return $translator->getCredentialWarning();
	}

	/**
	 * The project the credential belongs to, for the screen to show, or null where it can't be read
	 */
	public function getProjectId(): ?string
	{
		return $this->getTranslator()?->getProjectId();
	}

	/**
	 * The configured translator, where it's Advanced: these settings are drawn whichever translator is selected, and
	 * only Advanced itself can say what the project holds
	 */
	public function getTranslator(): ?GoogleTranslateAdvanced
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof GoogleTranslateAdvanced ? $translator : null;
	}

	public function attributeLabels(): array
	{
		return [
			'location' => Craft::t('traduco', 'Location'),
			'model' => Craft::t('traduco', 'Model'),
			'glossaries' => Craft::t('traduco', 'Glossaries'),
			'glossaryIgnoreCase' => Craft::t('traduco', 'Match Terms Whatever Their Case'),
		];
	}

	public function attributeHints(): array
	{
		return [
			'location' => Craft::t('traduco', 'The Google Cloud location to call. Leave it as “global” unless data residency requires a region, which has to be one the chosen model is available in.'),
			'model' => Craft::t('traduco', 'Which of Google’s general models translates. Custom and AutoML models aren’t offered: one is trained for a single language pair, and this setting is used for every site.'),
			'glossaries' => Craft::t('traduco', 'The glossaries to translate with. Google matches language codes exactly, so a glossary written for “en” isn’t used for an “en-GB” site.'),
			'glossaryIgnoreCase' => Craft::t('traduco', 'Looks a term up however it’s capitalised in the value.'),
		];
	}

	/**
	 * @return array<int, array<int|string, mixed>>
	 */
	protected function defineRules(): array
	{
		return [
			[['location'], 'safe'],
			[['location'], 'trim'],
			[['location'], 'required'],
			// Google's location ids are lowercase letters, digits and hyphens
			[['location'], 'match', 'pattern' => '/^[a-z0-9\-]+$/'],
			[['glossaries', 'glossaryIgnoreCase'], 'safe'],
			[
				'model',
				'in',
				'range' => array_column($this->getModelOptions(), 'value'),
				'strict' => true,
				'skipOnEmpty' => false,
			],
		];
	}
}
