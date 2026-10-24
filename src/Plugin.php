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
use craft\db\MigrationManager;
use craft\fieldlayoutelements\BaseField;
use craft\helpers\UrlHelper;
use craft\services\{
	Gc,
	SystemMessages,
	UserPermissions,
};
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
 * @license https://craftcms.github.io/license/ Craft License
 *
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
	// Lite: translating fields from their action menus. Pro: translating whole elements into other sites, in the background.
	use Editions;
	use Services;

	public const HANDLE = 'traduco';

	/** Granted with either of the two nested settings permissions, which is what the settings subnav item goes by */
	public const PERMISSION_SETTINGS = 'traduco:settings';

	public const PERMISSION_SETTINGS_SYSTEM = 'traduco:settings:system';

	public const PERMISSION_SETTINGS_ADMIN = 'traduco:settings:admin';

	public const PERMISSION_HISTORY = 'traduco:history';

	public const PERMISSION_GLOSSARIES = 'traduco:glossaries';

	public const PERMISSION_STYLES = 'traduco:styles';

	public const PERMISSION_MEMORIES = 'traduco:memories';

	/**
	 * The screens of the control panel section, in the order they're listed. Each one is a URL rule and a subnav item,
	 * see getNavItems() and Traduco::registerCpUrls().
	 */
	public const SCREENS = [
		'history' => [
			'label' => 'History',
			'icon' => 'clock-rotate-left',
			'url' => 'traduco/history',
			'action' => 'traduco/history/index',
			'permission' => self::PERMISSION_HISTORY,
			'pro' => true,
		],
		'styles' => [
			'label' => 'Styles',
			'icon' => 'pen-nib',
			'url' => 'traduco/styles',
			'action' => 'traduco/styles/index',
			'permission' => self::PERMISSION_STYLES,
			'pro' => true,
		],
		'memories' => [
			'label' => 'Memories',
			'icon' => 'database',
			'url' => 'traduco/memories',
			'action' => 'traduco/memories/index',
			'permission' => self::PERMISSION_MEMORIES,
			'pro' => true,
		],
		'glossaries' => [
			'label' => 'Glossaries',
			'icon' => 'book',
			'url' => 'traduco/glossaries',
			'action' => 'traduco/glossaries/index',
			'permission' => self::PERMISSION_GLOSSARIES,
			'pro' => true,
		],
		'settings' => [
			'label' => 'Settings',
			'icon' => 'sliders',
			'url' => 'traduco/settings',
			'action' => 'traduco/settings/index',
			'permission' => self::PERMISSION_SETTINGS,
		],
	];

	/** Bumped with every migration beside Install, so Craft knows an installed copy has one to run */
	public string $schemaVersion = '1.0.2';
	public bool $hasCpSection = true;
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

		// The asset bundle is the one thing that has to be registered this early, and only a control panel request
		// renders anything that needs it
		if (Craft::$app->getRequest()->getIsCpRequest()) {
			Craft::$app->getView()->registerAssetBundle(CpAsset::class);
		}

		Craft::$app->onInit(function (): void {
			$this->attachEventHandlers();
		});
	}

	public function getSettingsResponse(): Response
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('traduco/settings'));
	}

	/**
	 * The name of the control panel section, which the settings rename
	 */
	public function getPluginName(): string
	{
		return $this->getSettings()->getPluginName();
	}

	/**
	 * Craft's permission to access the plugin's control panel section, which the plugin's own permissions are nested under
	 */
	public static function getAccessPermission(): string
	{
		return 'accessPlugin-' . self::HANDLE;
	}

	/**
	 * The screens the current user can reach, which are the section's subnav
	 *
	 * @return array<string, array{label: string, url: string, icon: string}> by screen handle
	 */
	public function getNavItems(): array
	{
		$user = Craft::$app->getUser();
		$items = [];

		foreach (self::SCREENS as $handle => $screen) {
			if (!$user->checkPermission($screen['permission'])) continue;
			if (($screen['pro'] ?? false) && !$this->isPro()) continue;

			$items[$handle] = [
				'label' => Craft::t(self::HANDLE, $screen['label']),
				'url' => $screen['url'],
				'icon' => $screen['icon'],
			];
		}

		// The settings screens have a permission each, and the subnav links to whichever of them the user can open
		if (isset($items['settings']) && !$user->checkPermission(self::PERMISSION_SETTINGS_ADMIN)) {
			$items['settings']['url'] = 'traduco/settings/system';
		}

		return $items;
	}

	public function getCpNavItem(): ?array
	{
		$subnav = $this->getNavItems();
		if ($subnav === []) return null;

		$firstItem = reset($subnav);

		return [
			...parent::getCpNavItem(),
			'label' => $this->getPluginName(),
			'url' => $firstItem['url'],
			'subnav' => $subnav,
		];
	}

	/**
	 * Craft builds the migrator from a lowercase `migrations` directory, the one directory name here that wouldn't be
	 * PascalCase, so it's pointed at `Migrations`. Only the two paths change, the component stays Craft's own.
	 */
	public function getMigrator(): MigrationManager
	{
		$migrator = parent::getMigrator();
		$migrator->migrationNamespace = __NAMESPACE__ . '\\Migrations';
		$migrator->migrationPath = $this->getBasePath() . DIRECTORY_SEPARATOR . 'Migrations';

		return $migrator;
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
		// The section, its screens and the plugin's permissions. These three events only fire for control panel
		// requests as it is, while init() runs for every request, so they're registered whatever the request is:
		// behind a control panel guard they'd be missing from the request that bootstrapped the application.
		Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $this->getTraduco()->registerCpTemplateRoots(...));
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $this->getTraduco()->registerCpUrls(...));
		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $this->getTraduco()->registerPermissions(...));

		// Translation history is pruned on garbage collection, which runs in the queue and on the console
		Event::on(Gc::class, Gc::EVENT_RUN, fn() => $this->getHistory()->prune());

		// The messages queued translations are reported with, registered here for the same reason: the worker that
		// sends them is a console request, which never passes the guard below
		Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, $this->getNotifications()->registerMessages(...));

		// The rest is the translation UI, which only the control panel has
		if (!Craft::$app->getRequest()->getIsCpRequest()) return;

		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, $this->getTraduco()->registerCraftVariable(...));
		Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML, $this->getTraduco()->defineElementSidebar(...));
		Event::on(BaseField::class, BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS, $this->getTraduco()->registerActionMenuItems(...));
	}
}
