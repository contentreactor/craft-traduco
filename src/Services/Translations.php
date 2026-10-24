<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Base\{
	BaseTranslator,
	TranslatorInterface,
	TranslatorSettings,
};
use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Translators\{
	DeepL,
	GoogleTranslate,
	GoogleTranslateAdvanced,
};
use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\{
	App,
	Html,
};
use Exception;
use Throwable;
use yii\base\{
	Component,
	InvalidConfigException,
};

class Translations extends Component
{
	public const EVENT_REGISTER_TRANSLATOR = 'registerTranslatorEvent';

	/**
	 * The body param the translators' own fields are posted under, outside the UserSettings namespace: a form name
	 * can't carry a class name, so each translator's fields sit under its class as an HTML ID
	 */
	public const SETTINGS_PARAM = 'translatorSettings';

	private TranslatorInterface $_translator;

	/** @return array<class-string<TranslatorInterface>> */
	public function getTranslators(): array
	{
		$translators = [
			DeepL::class,
			GoogleTranslate::class,
			GoogleTranslateAdvanced::class,
		];

		if ($this->hasEventHandlers(self::EVENT_REGISTER_TRANSLATOR)) {
			$event = new RegisterComponentTypesEvent([
				'types' => $translators,
			]);
			$this->trigger(self::EVENT_REGISTER_TRANSLATOR, $event);
			$translators = $event->types;
		}

		foreach ($translators as $type) {
			if (!$this->validateTranslator($type)) {
				throw new Exception('Invalid translator type: ' . $type);
			}
		}

		return $translators;
	}

	public function getTranslator(): TranslatorInterface
	{
		$this->ensureTranslator();
		return $this->_translator;
	}

	/**
	 * The configured translator, or null where none is configured or its key can't be used. For the screens that draw
	 * whatever a translator can tell them, unlike getTranslator(), which the translating paths want to fail loudly.
	 */
	public function getConfiguredTranslator(): ?TranslatorInterface
	{
		try {
			return $this->getTranslator();
		} catch (Throwable $e) {
			Craft::warning('Couldn’t set up the translator: ' . $e->getMessage(), 'traduco');

			return null;
		}
	}

	/**
	 * Every registered translator's own settings, filled from what's stored. Translators with no settings of their
	 * own are left out, so nothing draws them an empty fieldset.
	 *
	 * @return array<class-string<TranslatorInterface>, TranslatorSettings>
	 */
	public function getSettingsModels(): array
	{
		$settings = Plugin::getInstance()->getSettings()->getUserSettings();

		return $this->createSettingsModels(fn(string $translatorClass): array => $settings->getTranslatorSettings($translatorClass));
	}

	/**
	 * The translators' own settings as the request posts them, see SETTINGS_PARAM. A translator the request says
	 * nothing about is left out rather than reset, so a screen that doesn't draw its fields can't wipe them.
	 *
	 * @return array<class-string<TranslatorInterface>, TranslatorSettings>
	 */
	public function getPostedSettingsModels(): array
	{
		$posted = (array)Craft::$app->getRequest()->getBodyParam(self::SETTINGS_PARAM, []);
		$stored = Plugin::getInstance()->getSettings()->getUserSettings();

		return $this->createSettingsModels(function (string $translatorClass) use ($posted, $stored): ?array {
			$values = $posted[Html::id($translatorClass)] ?? null;
			if (!is_array($values)) return null;

			// A setting the screen leaves out, because the field can't be shown, keeps what it had rather than
			// falling back to the model's default: createSettings() builds a model from these values alone
			return $values + $stored->getTranslatorSettings($translatorClass);
		});
	}

	/**
	 * @param callable(class-string<TranslatorInterface>): (array<string, mixed>|null) $values null to leave a translator out
	 * @return array<class-string<TranslatorInterface>, TranslatorSettings>
	 */
	private function createSettingsModels(callable $values): array
	{
		$models = [];
		foreach ($this->getTranslators() as $translatorClass) {
			// Settings models come with BaseTranslator, not with the interface, see BaseTranslator::createSettings()
			if (!is_a($translatorClass, BaseTranslator::class, true)) continue;

			$translatorValues = $values($translatorClass);
			if ($translatorValues === null) continue;

			$settings = $translatorClass::createSettings($translatorValues);
			if ($settings !== null) $models[$translatorClass] = $settings;
		}

		return $models;
	}

	private function validateTranslator(string $translatorClass): bool
	{
		return is_a($translatorClass, TranslatorInterface::class, true);
	}

	private function ensureTranslator(): void
	{
		if (!isset($this->_translator)) {
			if (empty($translatorClass = Plugin::getInstance()->getSettings()->getUserSettings()->translator)) {
				throw new InvalidConfigException('Translator not configured!');
			}
			if (empty($translatorApi = Plugin::getInstance()->getSettings()->getUserSettings()->translatorApi)) {
				throw new InvalidConfigException('Translator API is missing!');
			}
			if (!in_array($translatorClass, $this->getTranslators(), true)) {
				throw new InvalidConfigException("Invalid translator: $translatorClass");
			}

			$this->_translator = new $translatorClass(
				App::parseEnv($translatorApi),
				Plugin::getInstance()->getSettings()->getUserSettings()->getTranslatorSettings($translatorClass),
			);
		}
	}
}
