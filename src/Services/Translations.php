<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Base\TranslatorInterface;
use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Translators\DeepL;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use Exception;
use yii\base\{
	Component,
	InvalidConfigException,
};

class Translations extends Component
{
	public const EVENT_REGISTER_TRANSLATOR = 'registerTranslatorEvent';

	private TranslatorInterface $_translator;

	/** @return array<class-string<TranslatorInterface>> */
	public function getTranslators(): array
	{
		$translators = [
			DeepL::class,
			// GoogleTranslate::class,
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

			$this->_translator = new $translatorClass(App::parseEnv($translatorApi));
		}
	}
}
