<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Helpers\PluginHelper;
use ContentReactor\Traduco\Models\UserSettings;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class SettingsController extends Controller
{
	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(match ($action->id) {
			'system', 'save-system' => 'traduco:settings:system',
			default => 'traduco:settings:admin',
		});

		return true;
	}

	public function actionIndex(): Response
	{
		return $this->renderTemplate('traduco/settings/index.twig', [
			'plugin' => Plugin::getInstance(),
			'settings' => Plugin::getInstance()->getSettings()->getUserSettings(),
		]);
	}

	public function actionSystem(): Response
	{
		return $this->renderTemplate('traduco/settings/system.twig', [
			'plugin' => Plugin::getInstance(),
			'settings' => Plugin::getInstance()->getSettings()->getUserSettings(),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$settings = Plugin::getInstance()->getSettings()->getUserSettings();
		$settings->setScenario(UserSettings::SCENARIO_GENERAL);
		$success = PluginHelper::saveSettings(Plugin::getInstance(), $settings);

		return $success ?
			$this->asSuccess(Craft::t('traduco', 'Settings saved.')) :
			$this->asFailure(
				Craft::t('traduco', 'Couldn’t save settings.'),
				routeParams: ['settings' => $settings],
			);
	}

	public function actionSaveSystem(): ?Response
	{
		$this->requirePostRequest();

		$settings = Plugin::getInstance()->getSettings()->getUserSettings();
		$settings->setScenario(UserSettings::SCENARIO_SYSTEM);
		$success = PluginHelper::saveSettings(Plugin::getInstance(), $settings);

		return $success ?
			$this->asSuccess(Craft::t('traduco', 'Settings saved.')) :
			$this->asFailure(
				Craft::t('traduco', 'Couldn’t save settings.'),
				routeParams: ['settings' => $settings],
			);
	}
}