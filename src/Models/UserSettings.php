<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use ContentReactor\Traduco\Base\TranslatorInterface;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\Model;
use MarcusGaius\FieldValueParser\Settings\ConfiglessSettings;

class UserSettings extends Model implements ConfiglessSettings
{
	public const SIDEBAR_TOP = 'top';

	public const SIDEBAR_BOTTOM = 'bottom';

	/** Translate the text around nested entries and the entries themselves, where the target site has its own */
	public const RICH_TEXT_ENTRIES_TRANSLATE = 'translate';

	/** Translate the rendered text, nested entries included, and save it without them */
	public const RICH_TEXT_ENTRIES_RENDER = 'render';

	/** Queued translations send no email */
	public const QUEUE_NOTIFICATIONS_NONE = 'none';

	/** Only a queued translation that failed sends one */
	public const QUEUE_NOTIFICATIONS_FAILURES = 'failures';

	/** Every queued translation sends one, however it went */
	public const QUEUE_NOTIFICATIONS_ALL = 'all';

	public const SCENARIO_GENERAL = 'general';

	public const SCENARIO_SYSTEM = 'system';

	public string $pluginName = 'Traduco';

	/** @var class-string<TranslatorInterface>|null $translator */
	public ?string $translator = null;
	public ?string $translatorApi = null;
	public string $sidebarPosition = self::SIDEBAR_TOP;
	/**
	 * Each translator's own settings, by its class. Ones belonging to a translator that isn't registered are kept, so
	 * switching away from a translator and back doesn't lose them.
	 *
	 * @var array<class-string<TranslatorInterface>, array<string, mixed>>
	 */
	public array $translatorSettings = [];
	/** How rich text fields with nested entries are translated, see the RICH_TEXT_ENTRIES_* constants */
	public string $richTextEntries = self::RICH_TEXT_ENTRIES_TRANSLATE;
	/** How many days translation history is kept for. Zero keeps it until it's deleted by hand. */
	public int $historyRetentionDays = 30;
	/** Which queued translations email the user who asked for them, see the QUEUE_NOTIFICATIONS_* constants */
	public string $queueNotifications = self::QUEUE_NOTIFICATIONS_FAILURES;

	/**
	 * @return array<int|string, mixed>
	 */
	public function getTranslatorOptions(): array
	{
		$options = [
			[
				'value' => '',
				'label' => Craft::t('traduco', 'Select a translator...'),
				'selected' => empty($this->translator),
				'hidden' => true,
				'disabled' => true,
			],
		];
		foreach (Plugin::getInstance()->getTranslations()->getTranslators() as $translatorClass) {
			$options[$translatorClass] = $translatorClass::displayName();
		}

		return $options;
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getRichTextEntriesOptions(): array
	{
		return [
			[
				'value' => self::RICH_TEXT_ENTRIES_TRANSLATE,
				'label' => Craft::t('traduco', 'Translate nested entries where the target site has its own, render them into the text otherwise'),
			],
			[
				'value' => self::RICH_TEXT_ENTRIES_RENDER,
				'label' => Craft::t('traduco', 'Always render nested entries into the translated text'),
			],
		];
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getSidebarPositionOptions(): array
	{
		return [
			['value' => self::SIDEBAR_TOP, 'label' => Craft::t('traduco', 'Above the rest of the sidebar')],
			['value' => self::SIDEBAR_BOTTOM, 'label' => Craft::t('traduco', 'Below the rest of the sidebar')],
		];
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	public function getQueueNotificationsOptions(): array
	{
		return [
			['value' => self::QUEUE_NOTIFICATIONS_NONE, 'label' => Craft::t('traduco', 'Never')],
			['value' => self::QUEUE_NOTIFICATIONS_FAILURES, 'label' => Craft::t('traduco', 'Only when a translation fails')],
			['value' => self::QUEUE_NOTIFICATIONS_ALL, 'label' => Craft::t('traduco', 'Every translation')],
		];
	}

	/**
	 * @param class-string<TranslatorInterface>|null $translatorClass the selected translator by default
	 * @return array<string, mixed>
	 */
	public function getTranslatorSettings(?string $translatorClass = null): array
	{
		return $this->translatorSettings[$translatorClass ?? (string)$this->translator] ?? [];
	}

	/**
	 * Settings given a translator at a time are merged into the bag rather than replacing it: the whole model is
	 * stored as one value, so a translator left out of an assignment would lose its own settings. The system settings
	 * screen posts them outside this model's namespace instead, see Translations::SETTINGS_PARAM.
	 *
	 * @param array<string, mixed> $values
	 * @param bool $safeOnly
	 */
	public function setAttributes($values, $safeOnly = true): void
	{
		if (isset($values['translatorSettings']) && is_array($values['translatorSettings'])) {
			$values['translatorSettings'] += $this->translatorSettings;
		}

		parent::setAttributes($values, $safeOnly);
	}

	public function attributeLabels(): array
	{
		return [
			'sidebarPosition' => Craft::t('traduco', 'Sidebar Position'),
			'richTextEntries' => Craft::t('traduco', 'Rich Text with Nested Entries'),
			'historyRetentionDays' => Craft::t('traduco', 'Keep Translation History For'),
			'queueNotifications' => Craft::t('traduco', 'Email Notifications'),
		];
	}

	public function attributeHints(): array
	{
		return [
			'sidebarPosition' => Craft::t('traduco', 'Where the translation dropdown sits in an element’s sidebar.'),
			'richTextEntries' => Craft::t('traduco', 'How CKEditor fields with nested entries are translated. Rendered nested entries become part of the translated text, and the target site’s value no longer has nested entries.'),
			'translatorApi' => Craft::t('traduco', 'The API key for the selected translator.'),
			'historyRetentionDays' => Craft::t('traduco', 'How many days a translation stays in the history. Enter 0 to keep them until they’re deleted by hand. Old runs are deleted on garbage collection.'),
			'queueNotifications' => Craft::t('traduco', 'Emails the user who asked for a translation once the queue has run it. Only whole elements are translated in the queue. Each message’s subject and body are editable under Utilities → System Messages.'),
		];
	}

	public function scenarios(): array
	{
		$scenarios = parent::scenarios();
		$scenarios[self::SCENARIO_GENERAL] = ['pluginName', 'sidebarPosition', 'richTextEntries', 'historyRetentionDays', 'queueNotifications'];
		// The translator can only be switched where admin changes are allowed, while its settings go with the API key,
		// which is editable on any environment
		$scenarios[self::SCENARIO_SYSTEM] = Craft::$app->getConfig()->getGeneral()->allowAdminChanges
			? ['translator', 'translatorApi', 'translatorSettings']
			: ['translatorApi', 'translatorSettings'];

		return $scenarios;
	}

	public function validateTranslatorApi(string $attribute): void
	{
		if (!empty($this->translatorApi) || !is_a((string)$this->translator, TranslatorInterface::class, true)) return;

		$this->addError($attribute, Craft::t('traduco', '{translatorType} requires an API key.', [
			'translatorType' => $this->translator::displayName(),
		]));
	}

	/**
	 * @return array<int, array<int|string, mixed>>
	 */
	protected function defineRules(): array
	{
		return [
			[['pluginName'], 'safe'],
			[
				'sidebarPosition',
				'in',
				'range' => [self::SIDEBAR_TOP, self::SIDEBAR_BOTTOM],
				'strict' => true,
				// Without this an empty post would be assigned and then skipped, leaving a value that matches
				// neither and puts the dropdown nowhere
				'skipOnEmpty' => false,
			],
			[
				'richTextEntries',
				'in',
				'range' => [self::RICH_TEXT_ENTRIES_TRANSLATE, self::RICH_TEXT_ENTRIES_RENDER],
				'strict' => true,
			],
			[
				'translator',
				'in',
				'range' => fn(): array => Plugin::getInstance()->getTranslations()->getTranslators(),
				'strict' => true,
			],
			[
				'translatorApi',
				'validateTranslatorApi',
				'skipOnEmpty' => false,
			],
			['historyRetentionDays', 'integer', 'min' => 0],
			[
				'queueNotifications',
				'in',
				'range' => [self::QUEUE_NOTIFICATIONS_NONE, self::QUEUE_NOTIFICATIONS_FAILURES, self::QUEUE_NOTIFICATIONS_ALL],
				'strict' => true,
				// Without this an empty post would be assigned and then skipped by the rule, leaving a value that
				// matches none of the three and quietly turns notifications off
				'skipOnEmpty' => false,
			],
			// Safe in every scenario, as the settings store loads the whole model without one
			[['translatorSettings'], 'safe'],
			['translatorSettings', 'filter', 'filter' => $this->normalizeTranslatorSettings(...)],
		];
	}

	/**
	 * @return array<class-string<TranslatorInterface>, array<string, mixed>>
	 */
	private function normalizeTranslatorSettings(mixed $value): array
	{
		$settings = [];
		foreach ((array)$value as $translatorClass => $translatorSettings) {
			if (!is_string($translatorClass) || !is_a($translatorClass, TranslatorInterface::class, true)) continue;

			$settings[$translatorClass] = (array)$translatorSettings;
		}

		return $settings;
	}
}