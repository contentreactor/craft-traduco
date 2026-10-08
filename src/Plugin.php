<?php
declare(strict_types=1);

namespace ContentReactor\Traduco;

use ContentReactor\Traduco\Helpers\PluginHelper;
use ContentReactor\Traduco\Models\{
	Settings,
	UserSettings,
};
use ContentReactor\Traduco\Traits\Services;
use ContentReactor\Traduco\Web\Assets\Cp\CpAsset;
use ContentReactor\Traduco\Web\Twig\Extension;
use Craft;
use craft\base\{
	Element,
	Model,
	Plugin as BasePlugin,
};
use craft\fieldlayoutelements\BaseField;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\{
	UrlManager,
	View,
};
use craft\web\twig\variables\CraftVariable;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Traits\Editions;
use yii\base\Event;
use yii\web\Response;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author ContentReactor <support@contentreactor.com>
 * @copyright ContentReactor
 * @license MIT
 *
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
	// Lite: translating fields from their action menus. Pro: translating whole elements into other sites, in the background.
	use Editions;
	use Services;

	public string $schemaVersion = '2.2.0';
	public bool $hasCpSettings = true;

	public array $extensions = [
		Extension::class,
	];

	public function init(): void
	{
		$this->controllerNamespace = __NAMESPACE__ . '\\Controllers';
		parent::init();
		Craft::setAlias('@traduco', __DIR__);
		// Settings are stored and fields parsed with Field Value Parser, whether or not its own plugin is installed
		FieldValueParser::boot();
		foreach ($this->extensions as $extensionClass) {
			Craft::$app->getView()->registerTwigExtension(new $extensionClass());
		}

		// The translation UI only exists in the control panel
		if (!Craft::$app->getRequest()->getIsCpRequest()) return;

		Craft::$app->getView()->registerAssetBundle(CpAsset::class);
		Craft::$app->onInit(function () {
			$this->attachEventHandlers();
		});
	}

	public function getSettingsResponse(): Response
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('traduco/settings'));
	}

	protected function createSettingsModel(): ?Model
	{
		$settings = new Settings();
		$settings->setUserSettings(new UserSettings());
		if ($this->isInstalled) PluginHelper::loadSettings($this, $settings->getUserSettings());
		return $settings;
	}

	private function attachEventHandlers(): void
	{
		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, $this->getTraduco()->registerCraftVariable(...));
		Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $this->getTraduco()->registerCpTemplateRoots(...));
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $this->getTraduco()->registerCpUrls(...));
		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $this->getTraduco()->registerPermissions(...));
		Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML, $this->getTraduco()->defineElementSidebar(...));
		Event::on(BaseField::class, BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS, $this->getTraduco()->registerActionMenuItems(...));
	}
}
