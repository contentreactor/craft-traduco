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

	public const SCENARIO_GENERAL = 'general';

	public const SCENARIO_SYSTEM = 'system';

	public string $pluginName = 'Traduco';

	/** @var class-string<TranslatorInterface>|null $translator */
	public ?string $translator = null;
	public ?string $translatorApi = null;
	public string $sidebarPosition = self::SIDEBAR_TOP;
	/** How rich text fields with nested entries are translated, see the RICH_TEXT_ENTRIES_* constants */
	public string $richTextEntries = self::RICH_TEXT_ENTRIES_TRANSLATE;

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

	public function attributeLabels(): array
	{
		return [
			'richTextEntries' => Craft::t('traduco', 'Rich Text with Nested Entries'),
		];
	}

	public function attributeHints(): array
	{
		return [
			'richTextEntries' => Craft::t('traduco', 'How CKEditor fields with nested entries are translated. Rendered nested entries become part of the translated text, and the target site’s value no longer has nested entries.'),
			'translatorApi' => Craft::t('traduco', 'The API key for the selected translator.'),
		];
	}

	public function scenarios(): array
	{
		$scenarios = parent::scenarios();
		$scenarios[self::SCENARIO_GENERAL] = ['pluginName', 'sidebarPosition', 'richTextEntries'];
		// The translator can only be switched where admin changes are allowed
		$scenarios[self::SCENARIO_SYSTEM] = Craft::$app->getConfig()->getGeneral()->allowAdminChanges
			? ['translator', 'translatorApi']
			: ['translatorApi'];

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
			[[
				'pluginName',
				'sidebarPosition',
			], 'safe'],
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
		];
	}
}