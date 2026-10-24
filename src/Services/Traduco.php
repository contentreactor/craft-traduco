<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Base\RephraserInterface;
use ContentReactor\Traduco\Helpers\{
	Integrations,
	Parser,
};
use ContentReactor\Traduco\Models\SiteLanguage;
use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Web\Twig\Variable;
use Craft;
use craft\base\{
	ElementInterface,
	Field,
	FieldInterface,
};
use craft\elements\Entry;
use craft\enums\MenuItemType;
use craft\events\{
	DefineFieldActionsEvent,
	DefineHtmlEvent,
	RegisterComponentTypesEvent,
	RegisterTemplateRootsEvent,
	RegisterUrlRulesEvent,
	RegisterUserPermissionsEvent,
};
use craft\fieldlayoutelements\{
	BaseField,
	CustomField,
};
use craft\fields\{
	Link as LinkField,
	PlainText,
};
use craft\helpers\ElementHelper;
use craft\htmlfield\HtmlField;
use craft\web\twig\variables\CraftVariable;
use yii\base\{
	Component,
	Event,
	InvalidConfigException,
};

class Traduco extends Component
{
	public const EVENT_REGISTER_TRANSLATABLE_FIELDS = 'registerTranslatableFieldsEvent';

	/** @var array<class-string<FieldInterface>> */
	private array $defaultTranslatableFields = [
		HtmlField::class,
		PlainText::class,
	];

	/** @var array<string, SiteLanguage[]> */
	private array $_translatableSites = [];

	public function registerCpUrls(RegisterUrlRulesEvent $event): void
	{
		// The section itself, which sends the user on to the first screen they can open
		$event->rules['traduco'] = 'traduco/cp/index';

		foreach (Plugin::SCREENS as $screen) {
			$event->rules[$screen['url']] = $screen['action'];
		}

		$event->rules['traduco/settings/system'] = 'traduco/settings/system';
		$event->rules['traduco/styles/<styleId:[\w\-]+>'] = 'traduco/styles/edit';
		$event->rules['traduco/glossaries/google'] = 'traduco/google-glossaries/index';
		$event->rules['traduco/glossaries/google/new'] = 'traduco/google-glossaries/edit';
		$event->rules['traduco/glossaries/google/<glossaryId:[\w\-]+>'] = 'traduco/google-glossaries/edit';
		$event->rules['traduco/glossaries/new'] = 'traduco/glossaries/edit';
		// DeepL's glossary IDs are UUIDs
		$event->rules['traduco/glossaries/<glossaryId:[\w\-]+>'] = 'traduco/glossaries/edit';
	}

	public function defineElementSidebar(DefineHtmlEvent $event): void
	{
		$element = $event->sender;
		if (!$element instanceof ElementInterface || !$element->id) return;

		$sites = $this->getTranslatableSites($element);
		if (empty($sites)) return;

		$sidebarHtml = Craft::$app->getView()->renderTemplate('@traduco/components/element-sidebar.twig', [
			'element' => $element,
			'sites' => $sites,
			'disabled' => !$this->canTranslate($element),
			'isPro' => Plugin::getInstance()->isPro(),
		]);

		if (Plugin::getInstance()->getSettings()->getUserSettings()->sidebarPosition === 'top') $event->html = $sidebarHtml . $event->html;
		else $event->html .= $sidebarHtml;
	}

	public function registerPermissions(RegisterUserPermissionsEvent $event): void
	{
		$pluginName = Plugin::getInstance()->getPluginName();
		$permissions = [
			Plugin::PERMISSION_HISTORY => [
				'label' => Craft::t('traduco', 'View translation history'),
				'info' => Craft::t('traduco', 'What was translated into which site, and how each translation went.'),
			],
			Plugin::PERMISSION_GLOSSARIES => [
				'label' => Craft::t('traduco', 'Manage glossaries'),
				'info' => Craft::t('traduco', 'The terms a translator has to translate a certain way.'),
			],
			Plugin::PERMISSION_STYLES => [
				'label' => Craft::t('traduco', 'Manage style rules'),
				'info' => Craft::t('traduco', 'How DeepL sounds in a language, and the instructions it follows.'),
			],
			Plugin::PERMISSION_MEMORIES => [
				'label' => Craft::t('traduco', 'Manage translation memories'),
				'info' => Craft::t('traduco', 'The translations DeepL reuses instead of translating again.'),
			],
			Plugin::PERMISSION_SETTINGS => [
				'label' => Craft::t('traduco', 'Manage Settings'),
				'nested' => [
					Plugin::PERMISSION_SETTINGS_SYSTEM => ['label' => Craft::t('traduco', 'Manage System Settings')],
					Plugin::PERMISSION_SETTINGS_ADMIN => ['label' => Craft::t('traduco', 'Manage Admin Settings')],
				],
			],
		];

		// Craft's permission to access the plugin is what shows its section, so the plugin's own are nested under it
		$accessPermission = Plugin::getAccessPermission();
		foreach (array_keys($event->permissions) as $group) {
			if (!isset($event->permissions[$group]['permissions'][$accessPermission])) continue;

			$event->permissions[$group]['permissions'][$accessPermission] = [
				...$event->permissions[$group]['permissions'][$accessPermission],
				'label' => Craft::t('app', 'Access {plugin}', ['plugin' => $pluginName]),
				'nested' => [
					...($event->permissions[$group]['permissions'][$accessPermission]['nested'] ?? []),
					...$permissions,
				],
			];

			return;
		}

		// Editions of Craft that don't give other users control panel permissions
		$event->permissions[] = [
			'heading' => $pluginName,
			'permissions' => $permissions,
		];
	}

	/**
	 * @param BaseField|Field $field
	 * @param string $action
	 * @param SiteLanguage|null $site the site the action translates into
	 * @param bool $showsTranslation whether the action shows the translation to copy, instead of saving it
	 * @return array{id: string, icon: string, label: string, attribute: string, action: string}
	 */
	public function getTranslationMenuAction(BaseField|Field $field, string $action = 'replace', ?SiteLanguage $site = null, bool $showsTranslation = false): ?array
	{
		if ($field instanceof Field) {
			$field = $field->layoutElement;
		}

		$actionLabel = match ($action) {
			'replace' => Craft::t('traduco', 'Replace'),
			'append' => Craft::t('traduco', 'Append'),
			'translate' => Craft::t('traduco', 'Translate'),
			default => throw new InvalidConfigException(Craft::t('traduco', 'The translation action label can only be "replace", "append" or "translate".')),
		};

		$labelParams = [
			'action' => $actionLabel,
			'attributeType' => $this->isCustomField($field) ? 'Field' : 'Attribute',
		];
		$label = match (true) {
			// The ellipsis marks actions that open a dialog
			$site && $showsTranslation => Craft::t('traduco', '{action} {attributeType} into {site}…', $labelParams + ['site' => $site->name]),
			(bool)$site => Craft::t('traduco', '{action} {attributeType} into {site}', $labelParams + ['site' => $site->name]),
			default => Craft::t('traduco', '{action} {attributeType}', $labelParams),
		};

		return [
			'id' => sprintf('action-%s-translation-%s', $action, mt_rand()),
			'icon' => Craft::getAlias("@traduco/icons/$action-translation.svg"),
			'label' => $label,
			'attribute' => $field->attribute(),
			'action' => $action,
		];
	}

	public function registerActionMenuItems(DefineFieldActionsEvent $event): void
	{
		if (!$event->sender instanceof BaseField) return;
		$element = $event->element;
		if (!$element?->id || $event->static) return;
		if (!$this->isFieldTranslatable($event->sender) || !Parser::isLayoutFieldAllowed($element, $event->sender)) return;

		$rootOwner = $element->getRootOwner();
		$nested = $element->id !== $rootOwner->id;

		// Nested elements are translated into the sites of the element they belong to.
		// Only offer sites with their own value, translating into the others would overwrite this one.
		$sites = array_values(array_filter(
			$this->getTranslatableSites($rootOwner),
			fn(SiteLanguage $site): bool => !$this->isValueSharedWithSite($element, $event->sender, $site->siteId),
		));
		if (empty($sites)) return;

		$disabled = !$this->canTranslate($element);
		$isCustomField = $this->isCustomField($event->sender);
		$isRichText = $isCustomField && Parser::isRichTextField($event->sender->getField());
		$view = Craft::$app->getView();

		$event->items[] = ['type' => MenuItemType::HR];

		foreach ($sites as $site) {
			// A nested element kept in the other site gets the translation saved, like other elements. Otherwise it's shown to copy.
			$mode = !$nested || $this->existsInSite($element, $site->siteId) ? 'save' : 'copy';
			$config = $this->getTranslationMenuAction($event->sender, 'translate', $site, $mode === 'copy');

			if (!$disabled) {
				$view->registerJsWithVars(fn($id, $action, $config) => <<<JS
(() => {
	$('#' + $id).on('activate', function() {
		Traduco.translateField($action, $config)
	})
})()
JS, [
					$view->namespaceInputId($config['id']),
					$config['action'],
					[
						'attribute' => $config['attribute'],
						'attributeType' => $isCustomField ? 'field' : 'attribute',
						'elementId' => $element->id,
						'siteId' => $element->siteId,
						'targetSiteId' => $site->siteId,
						'mode' => $mode,
						'richText' => $isRichText,
					],
				]);
			}

			$event->items[] = [
				'id' => $config['id'],
				'icon' => $config['icon'],
				'label' => $config['label'],
				'disabled' => $disabled,
			];
		}

		$this->addRephraseMenuItem($event, $isCustomField, $isRichText, $disabled);

		$event->items[] = ['type' => MenuItemType::HR];
	}

	/**
	 * Offers rewriting the value in its own language, where the translator can do that. The result is shown to copy
	 * rather than saved: it would go into the site the editor has open, whose own form would overwrite it on submit.
	 */
	private function addRephraseMenuItem(DefineFieldActionsEvent $event, bool $isCustomField, bool $isRichText, bool $disabled): void
	{
		$element = $event->element;
		if ($element === null || !Plugin::getInstance()->isPro()) return;

		// Rich text is left out: DeepL Write takes no tag handling, so it would rewrite the markup as prose
		if ($isRichText) return;

		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();
		if (!$translator instanceof RephraserInterface || $translator->getRephraseUnavailableReason() !== null) return;

		$id = sprintf('action-rephrase-%s', mt_rand());
		$label = $isCustomField
			? Craft::t('traduco', 'Rephrase Field…')
			: Craft::t('traduco', 'Rephrase Attribute…');

		if (!$disabled) {
			// The same entry point as translating, which posts to rephrase-text and shows the result to copy
			Craft::$app->getView()->registerJsWithVars(fn($id, $config) => <<<JS
(() => {
	$('#' + $id).on('activate', function() {
		Traduco.translateField('rephrase', $config)
	})
})()
JS, [
				Craft::$app->getView()->namespaceInputId($id),
				[
					'attribute' => $event->sender instanceof BaseField ? $event->sender->attribute() : null,
					'attributeType' => $isCustomField ? 'field' : 'attribute',
					'elementId' => $element->id,
					'siteId' => $element->siteId,
					// Rephrasing stays in the element's own site, and the request model wants a target either way
					'targetSiteId' => $element->siteId,
					'mode' => 'copy',
					'richText' => false,
				],
			]);
		}

		$event->items[] = [
			'id' => $id,
			'icon' => 'pen-to-square',
			'label' => $label,
			'disabled' => $disabled,
		];
	}

	/**
	 * Whether the field layout element holds text Traduco can translate
	 */
	public function isFieldTranslatable(BaseField $fieldLayoutField): bool
	{
		if (!$fieldLayoutField instanceof CustomField) return true;

		return $this->isFieldTypeTranslatable($fieldLayoutField->getField());
	}

	/**
	 * Whether the field holds text the translator can translate, including links' labels and descriptions
	 */
	public function isFieldTypeTranslatable(FieldInterface $field): bool
	{
		if (Integrations::isTypedLinkField($field)) return $field->allowCustomText;
		if ($field instanceof LinkField) return $field->showLabelField || (bool)array_intersect(['title', 'ariaLabel'], $field->advancedFields);

		foreach ($this->getTranslatableFields() as $translatableFieldClass) {
			if ($field instanceof $translatableFieldClass) return true;
		}

		return false;
	}

	/**
	 * Translations start from saved content, so drafts (unsaved changes included), revisions and their nested elements can't be translated
	 */
	public function canTranslate(ElementInterface $element): bool
	{
		foreach ([$element, $element->getRootOwner()] as $checkedElement) {
			if ($checkedElement->getIsDraft() || $checkedElement->getIsRevision()) return false;
		}

		return true;
	}

	/**
	 * @return array<class-string<FieldInterface>>
	 */
	public function getTranslatableFields(): array
	{
		$event = new RegisterComponentTypesEvent([
			'types' => $this->defaultTranslatableFields,
		]);

		$this->trigger(self::EVENT_REGISTER_TRANSLATABLE_FIELDS, $event);

		return $event->types;
	}

	/**
	 * Returns the other sites of the element's section (or navigation…) that the current user can translate it into,
	 * whether the element exists in them yet or not
	 *
	 * @param ElementInterface $element
	 * @return SiteLanguage[]
	 */
	public function getTranslatableSites(ElementInterface $element): array
	{
		if (!$element::isLocalized()) return [];

		$cacheKey = "$element->id-$element->siteId";
		if (isset($this->_translatableSites[$cacheKey])) return $this->_translatableSites[$cacheKey];

		$sitesService = Craft::$app->getSites();
		$siteIds = array_intersect($this->getContainerSiteIds($element), $sitesService->getEditableSiteIds());

		$sites = [];
		foreach ($siteIds as $siteId) {
			$site = $sitesService->getSiteById((int)$siteId, true);
			if (!$site || $site->id === $element->siteId) continue;

			$sites[] = new SiteLanguage(
				sprintf('%s (%s)', $site->getName(), $site->getLocale()->getDisplayName(Craft::$app->language)),
				$site->id,
			);
		}

		return $this->_translatableSites[$cacheKey] = $sites;
	}

	/**
	 * Returns the IDs of the sites the element's section, or navigation for nodes, is enabled for.
	 * Nested and other elements use the sites Craft lets them exist in.
	 *
	 * @return int[]
	 */
	public function getContainerSiteIds(ElementInterface $element): array
	{
		if ($element instanceof Entry && $element->sectionId) {
			return array_map('intval', array_keys($element->getSection()->getSiteSettings()));
		}

		if (Integrations::isNavigationNode($element)) {
			$siteSettings = array_filter($element->getNav()->getSiteSettings(), fn($settings): bool => (bool)$settings->enabled);

			return array_map('intval', array_keys($siteSettings));
		}

		return array_column(ElementHelper::supportedSitesForElement($element, true), 'siteId');
	}

	/**
	 * Whether the element itself can be saved in the site. Otherwise, e.g. in sections that don't propagate entries, only a separate copy can.
	 */
	public function canExistInSite(ElementInterface $element, int $siteId): bool
	{
		return in_array($siteId, array_column(ElementHelper::supportedSitesForElement($element, true), 'siteId'), true);
	}

	/**
	 * Returns the element to translate into the target site: the element saved in that site, an unsaved new version for that site,
	 * or, where the element can't exist in that site, an unsaved stand-in without an ID for a separate copy
	 */
	public function getTargetElement(ElementInterface $source, int $targetSiteId): ElementInterface
	{
		return Craft::$app->getElements()->getElementById($source->id, $source::class, $targetSiteId)
			?? $this->getTargetStandIn($source, $targetSiteId);
	}

	/**
	 * The unsaved element Traduco translates into where the element isn't saved in the target site yet: the element itself,
	 * marked new for the site, or, where it can't exist in that site, a stand-in without an ID for a separate copy
	 */
	public function getTargetStandIn(ElementInterface $source, int $targetSiteId): ElementInterface
	{
		$target = clone $source;
		$target->siteId = $targetSiteId;
		$target->isNewForSite = true;

		if (!$this->canExistInSite($source, $targetSiteId)) {
			$target->id = null;
		}

		return $target;
	}

	public function registerCpTemplateRoots(RegisterTemplateRootsEvent $event): void
	{
		$event->roots['@traduco'] = dirname(__DIR__) . '/templates';
	}

	public function registerCraftVariable(Event $event): void
	{
		if (!$event->sender instanceof CraftVariable) return;
		$event->sender->set('traduco', Variable::class);
	}

	/**
	 * @param BaseField $field
	 * @return bool
	 * @psalm-assert-if-true CustomField $field
	 * @phpstan-assert-if-true CustomField $field
	 */
	private function isCustomField(BaseField $field): bool
	{
		return $field instanceof CustomField;
	}

	private function existsInSite(ElementInterface $element, int $siteId): bool
	{
		return $element::find()->id($element->id)->siteId($siteId)->status(null)->exists();
	}

	private function isValueSharedWithSite(ElementInterface $element, BaseField $fieldLayoutField, int $siteId): bool
	{
		// The stand-in, not a clone in the other site: where only a separate copy can be translated into the site, it has
		// values of its own and shares none of them. Unlike getTargetElement() it loads nothing, though sections propagating
		// to custom sites resolve theirs with a query of their own.
		$target = $this->getTargetStandIn($element, $siteId);

		if ($this->isCustomField($fieldLayoutField)) {
			return Parser::isFieldValueShared($element, $target, $fieldLayoutField->getField());
		}

		return !Parser::isAttributeTranslatable($element, $target, $fieldLayoutField->attribute());
	}
}