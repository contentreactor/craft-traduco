<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Helpers;

use ContentReactor\Traduco\Base\AttributeType;
use ContentReactor\Traduco\Models\{
	TranslatableField,
	UserSettings,
};
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\{
	ElementInterface,
	Field,
	FieldInterface,
};
use craft\ckeditor\data\FieldData as CKEditorFieldData;
use craft\elements\Entry;
use craft\fieldlayoutelements\{
	BaseField,
	CustomField,
};
use craft\fields\{
	Addresses as AddressesField,
	Link as LinkField,
	Matrix,
};
use craft\htmlfield\HtmlField;
use Exception;
use Generator;
use lenz\linkfield\fields\LinkField as TypedLinkField;
use MarcusGaius\FieldValueParser\Enums\{
	NodeType,
	Omit,
	Purpose,
};
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Helpers\RichText;
use MarcusGaius\FieldValueParser\Models\{
	ParseContext,
	ParseOptions,
};
use MarcusGaius\FieldValueParser\Schema\Node;
use MarcusGaius\FieldValueParser\Texts\ValueTexts;
use nystudio107\seomatic\fields\SeoSettings;
use Stringable;

class Parser
{
	/** @var array<string, Node> Fields' Field Value Parser nodes, by UID */
	private static array $nodes = [];

	/**
	 * @param ElementInterface $source
	 * @param ElementInterface $target the element to translate into, a separate copy of the source or the source in another site
	 * @param string $language
	 * @return Generator<int, TranslatableField>
	 */
	public static function getFields(ElementInterface $source, ElementInterface $target, string $language): Generator
	{
		// Navigation nodes only get their label translated, never their links or the elements they link to
		if (Integrations::isNavigationNode($source)) {
			if (!$source->isElement()) {
				$field = self::buildAttributeField($source, $target, 'title', $language);
				if (self::isFieldKept($field)) yield $field;
			}

			return;
		}

		$fieldLayout = $source->getFieldLayout();
		$hasTitleElement = false;

		foreach ($fieldLayout->getAllElements() as $fieldLayoutElement) {
			if (!$fieldLayoutElement instanceof BaseField) continue;
			if (!$fieldLayoutElement instanceof CustomField && $fieldLayoutElement->attribute() === 'title') $hasTitleElement = true;

			$field = self::buildTranslatableField($source, $target, $fieldLayoutElement, $language);
			if (!self::isFieldKept($field)) continue;

			yield $field;
		}

		// Layouts built in code can leave out the title field
		if (!$hasTitleElement && self::hasEditableTitle($source)) {
			$field = self::buildAttributeField($source, $target, 'title', $language);
			if (self::isFieldKept($field)) yield $field;
		}
	}

	/**
	 * Whether the field layout element may be translated on the element at all. Navigation nodes only allow their label,
	 * and only when they don't link to an element.
	 */
	public static function isLayoutFieldAllowed(ElementInterface $element, BaseField $fieldLayoutField): bool
	{
		if (!Integrations::isNavigationNode($element)) return true;

		return !$fieldLayoutField instanceof CustomField && $fieldLayoutField->attribute() === 'title' && !$element->isElement();
	}

	private static function hasEditableTitle(ElementInterface $element): bool
	{
		if (!$element::hasTitles()) return false;

		// Entry types without a title field generate their titles when saved
		return !$element instanceof Entry || $element->getType()->hasTitleField;
	}

	private static function buildAttributeField(ElementInterface $source, ElementInterface $target, string $attribute, string $language): ?TranslatableField
	{
		if (!self::isAttributeTranslatable($source, $target, $attribute)) return null;

		$value = self::translateValue($source, $source->$attribute, $language);
		if (!$value) return null;

		return new TranslatableField(AttributeType::ATTRIBUTE, $attribute, $value);
	}

	/**
	 * Sets the translated values on the target element, without saving it
	 *
	 * @param ElementInterface $source
	 * @param ElementInterface $target
	 * @param string $language
	 * @return string[] handles of nested element fields to translate in place once the target element is saved
	 */
	public static function applyFields(ElementInterface $source, ElementInterface $target, string $language): array
	{
		$nestedFieldHandles = [];

		foreach (self::getFields($source, $target, $language) as $field) {
			if ($field->attributeType === AttributeType::NESTED) {
				$nestedFieldHandles[] = $field->attributeName;
			} elseif ($field->attributeType === AttributeType::ATTRIBUTE) {
				$target->{$field->attributeName} = $field->value;
			} else {
				$target->setFieldValue($field->attributeName, $field->value);

				// Rich text that kept the target's nested entries gets them translated too
				if (RichText::hasNestedEntries($field->value)) {
					$nestedFieldHandles[] = $field->attributeName;
				}
			}
		}

		return $nestedFieldHandles;
	}

	/**
	 * Translates the nested elements whose structure is kept in every site, in the target site, without adding, removing or reordering them.
	 * Replacing them instead would replace them in every site they're shared with, the source included.
	 *
	 * @param ElementInterface $source
	 * @param ElementInterface $target
	 * @param string[] $fieldHandles
	 * @param string $language
	 * @throws Exception
	 */
	public static function translateSharedNestedElements(ElementInterface $source, ElementInterface $target, array $fieldHandles, string $language): void
	{
		$elementsService = Craft::$app->getElements();
		$isSameElement = $source->id === $target->id;

		foreach ($fieldHandles as $fieldHandle) {
			// A separate copy has its own nested elements, duplicated in the same order, and so do rich text fields in every site
			$pairsByIndex = !$isSameElement || Integrations::isCKEditorField($source->getFieldLayout()?->getFieldByHandle($fieldHandle));
			$targetNestedElements = $pairsByIndex ? self::getNestedElements($target, $fieldHandle) : [];

			foreach (self::getNestedElements($source, $fieldHandle) as $index => $nestedElement) {
				$nestedTarget = $pairsByIndex
					? ($targetNestedElements[$index] ?? null)
					: $elementsService->getElementById($nestedElement->id, $nestedElement::class, $target->siteId);
				// Not propagated to the target site
				if (!$nestedTarget) continue;

				$nestedFieldHandles = self::applyFields($nestedElement, $nestedTarget, $language);
				if (!$elementsService->saveElement($nestedTarget, propagate: false)) {
					throw new Exception('Couldn’t save the translated nested element: ' . implode(', ', $nestedTarget->getFirstErrors()));
				}
				self::translateSharedNestedElements($nestedElement, $nestedTarget, $nestedFieldHandles, $language);
			}
		}
	}

	private static function buildTranslatableField(ElementInterface $source, ElementInterface $target, BaseField $fieldLayoutField, string $language): ?TranslatableField
	{
		if (!$fieldLayoutField instanceof CustomField) {
			return self::buildAttributeField($source, $target, $fieldLayoutField->attribute(), $language);
		}
		$field = $fieldLayoutField->getField();

		if (self::hasNestedElements($field)) {
			if (self::isFieldValueShared($source, $target, $field)) {
				return new TranslatableField(AttributeType::NESTED, $field->handle);
			}

			return new TranslatableField(
				AttributeType::STRUCTURED,
				$field->handle,
				self::getInitialValueForStore($source, $field, $language),
			);
		}

		// Both sites read the same stored value, so the field isn't translatable between them
		if (self::isFieldValueShared($source, $target, $field)) {
			return null;
		}

		// Rich text keeps the target's own nested entries where it can, otherwise it's rendered, see getFieldTexts()
		$entryPairs = self::getRichTextEntryPairs($source, $target, $field);
		if ($entryPairs !== null) {
			return new TranslatableField(
				AttributeType::FIELD,
				$field->handle,
				self::translateRichTextWithEntries($source, $field, $entryPairs, $language),
			);
		}

		if (self::isFieldStructured($field)) {
			return new TranslatableField(
				AttributeType::STRUCTURED,
				$field->handle,
				self::getInitialValueForStore($source, $field, $language),
			);
		}

		// Text is translated, other values are copied, see normalizeFieldValueForCopy()
		return new TranslatableField(
			AttributeType::FIELD,
			$field->handle,
			self::getInitialValueForStore($source, $field, $language),
		);
	}

	private static function isFieldKept(?TranslatableField $field): bool
	{
		if (in_array($field?->attributeType, [AttributeType::STRUCTURED, AttributeType::NESTED], true)) return true;
		return $field?->value !== null;
	}

	/**
	 * Whether the field holds text the translator can translate
	 */
	public static function isFieldTranslatable(FieldInterface $field): bool
	{
		return Plugin::getInstance()->getTraduco()->isFieldTypeTranslatable($field);
	}

	/**
	 * Whether the field's value is translated through its structure rather than as text: fields with nested elements,
	 * SEOmatic SEO Settings fields, and CKEditor fields with nested entries
	 */
	public static function isFieldStructured(FieldInterface $field): bool
	{
		if (self::hasNestedElements($field)) return true;
		if (class_exists(SeoSettings::class) && $field instanceof SeoSettings) return true;
		if (self::isNestedCKEditorField($field)) return true;

		return false;
	}

	/**
	 * Whether the attribute can be translated into the target element without changing the source element's value
	 */
	public static function isAttributeTranslatable(ElementInterface $source, ElementInterface $target, string $attribute): bool
	{
		if (!$source::isLocalized()) return false;

		// e.g. getTitleTranslationKey(), attributes without one aren't translated
		if (!method_exists($source, 'get' . ucfirst($attribute) . 'TranslationKey')) return false;

		$node = new Node(NodeType::ATTRIBUTE, $attribute, $attribute);

		return !FieldValueParser::getInstance()->getSites()->isValueShared($source, $node, (int)$target->siteId);
	}

	/**
	 * Whether the source and target elements share a single stored value (or set of nested elements) for the field
	 */
	public static function isFieldValueShared(ElementInterface $source, ElementInterface $target, FieldInterface $field): bool
	{
		if (!$source::isLocalized()) return true;

		// The field's node knows how it's stored per site, following nested element fields' propagation, and content blocks
		// being one nested element saved to every site
		return FieldValueParser::getInstance()->getSites()->isValueShared($source, self::getNode($field), (int)$target->siteId);
	}

	/**
	 * Fields holding nested elements with fields of their own, like Matrix, Neo and Content Block fields. Addresses fields
	 * hold nested elements too, but relate to them, so they're copied.
	 */
	private static function hasNestedElements(FieldInterface $field): bool
	{
		return self::getNode($field)->type === NodeType::NESTED && !$field instanceof AddressesField;
	}

	/**
	 * The field's Field Value Parser node, built once per field
	 */
	private static function getNode(FieldInterface $field): Node
	{
		// Unsaved fields have no UID, and object hashes are reused once objects are gone, so their nodes aren't kept
		if ($field->uid === null) {
			return FieldValueParser::getInstance()->getSchemas()->getFieldNode($field);
		}

		return self::$nodes[$field->uid] ??= FieldValueParser::getInstance()->getSchemas()->getFieldNode($field);
	}

	/**
	 * Get the value of the field used to clone the field from the origin into the newly translated element
	 *
	 * @param ElementInterface $element
	 * @param FieldInterface $field
	 * @param string $language
	 * @return mixed
	 */
	public static function getInitialValueForStore(ElementInterface $element, FieldInterface $field, string $language): mixed
	{
		// Relations (disabled related elements included), nested elements, SEO Settings and everything else, see normalizeFieldValueForCopy()
		return self::normalizeFieldValueForCopy($element, $field, $language);
	}

	private static function isNestedCKEditorField(FieldInterface $field): bool
	{
		if (!Integrations::isCKEditorField($field)) return false;

		if (count($field->getFieldLayoutProviders()) > 0) return true;

		return false;
	}

	/**
	 * Returns the source's nested entries in a rich text field paired with the target's, by position, when they can be translated
	 * in place: the setting allows it, and the target has its own entries of the same types. Where nested entries propagate
	 * to the target site, the value is shared, so the field isn't translated at all.
	 *
	 * @return array<int, array{0: Entry, 1: Entry}>|null null where the text has to be rendered instead
	 */
	public static function getRichTextEntryPairs(ElementInterface $source, ElementInterface $target, FieldInterface $field): ?array
	{
		if (Plugin::getInstance()->getSettings()->getUserSettings()?->richTextEntries !== UserSettings::RICH_TEXT_ENTRIES_TRANSLATE) return null;
		if (!Integrations::isCKEditorField($field) || !$target->id || $target->isNewForSite) return null;

		return RichText::pairEntries($source->getFieldValue($field->handle), $target->getFieldValue($field->handle), (int)$target->siteId);
	}

	/**
	 * Returns the source's rich text with its text translated and the target's nested entries in place of the source's,
	 * see getRichTextEntryPairs(). The entries themselves are translated once the target is saved.
	 *
	 * @param array<int, array{0: Entry, 1: Entry}> $entryPairs
	 */
	public static function translateRichTextWithEntries(ElementInterface $source, FieldInterface $field, array $entryPairs, string $language): string
	{
		/** @var CKEditorFieldData $value */
		$value = $source->getFieldValue($field->handle);

		// The raw HTML, so reference tags keep linking to the elements in the target site
		return RichText::withEntries(
			$value,
			array_column($entryPairs, 1),
			fn(string $html): string => self::translateValue($source, $html, $language) ?? $html,
		);
	}

	/**
	 * Returns the texts in a field value that Traduco translates, by key, see Field Value Parser's texts: the text itself (`value`),
	 * a typed link's custom label, a link's label, title text and ARIA label, or an SEO Settings field's titles and descriptions.
	 * Empty texts are left out.
	 *
	 * @return array<string, string>
	 */
	public static function getFieldTexts(FieldInterface $field, mixed $value): array
	{
		$texts = FieldValueParser::getInstance()->getTexts();

		// Field types registered as translatable hold text as a whole
		return $texts->hasTexts($field)
			? $texts->getTexts($field, $value)
			: array_filter((new ValueTexts())->getTexts($field, $value), fn(?string $text): bool => $text !== null && trim($text) !== '');
	}

	/**
	 * Returns the field value with the texts replaced, in a format the field accepts. Links keep their link and only get the new texts.
	 *
	 * @param array<string, string> $texts by key, like getFieldTexts() returns them
	 */
	public static function withFieldTexts(ElementInterface $element, FieldInterface $field, mixed $value, array $texts): mixed
	{
		$fieldTexts = FieldValueParser::getInstance()->getTexts();

		return $fieldTexts->hasTexts($field) ? $fieldTexts->withTexts($field, $value, $element, $texts) : ($texts['value'] ?? null);
	}

	/**
	 * Returns the name of a text getFieldTexts() returns, to tell the texts of fields with more than one apart
	 */
	public static function getFieldTextLabel(FieldInterface $field, string $attribute): string
	{
		return FieldValueParser::getInstance()->getTexts()->getTextLabel($field, $attribute);
	}

	/**
	 * Whether the field holds a link, which is copied even without text to translate
	 */
	public static function isLinkField(FieldInterface $field): bool
	{
		return $field instanceof LinkField || Integrations::isTypedLinkField($field);
	}

	/**
	 * Whether the field holds HTML, so its translation is previewed rendered
	 */
	public static function isRichTextField(FieldInterface $field): bool
	{
		return $field instanceof HtmlField;
	}

	/**
	 * The field's value in the format it accepts back, with its text translated: Field Value Parser's WRITE value, with the
	 * text of translatable fields translated, nested ones included. Nested elements keep their type, status, fields and
	 * translated title. Everything else is copied as it is, relations keep pointing to the same elements.
	 */
	public static function normalizeFieldValueForCopy(ElementInterface $element, FieldInterface $field, string $language): mixed
	{
		$value = FieldValueParser::getInstance()->getValues()->parseField($element, (string)$field->handle, Purpose::WRITE, self::getCopyOptions($language));

		return $value === Omit::VALUE ? null : $value;
	}

	/**
	 * Field Value Parser options translating the text of translatable fields, and keeping only nested elements' titles, translated
	 */
	private static function getCopyOptions(string $language): ParseOptions
	{
		$translate = function (FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context) use ($language): mixed {
			// Link fields only have text to translate with some settings
			if (!self::isFieldTranslatable($field) && !Integrations::isSeoSettingsField($field)) {
				return FieldValueParser::getInstance()->getHandlers()->getHandler($field, $context->purpose)($field, $value, $element, $context);
			}

			$translatedTexts = self::translateTexts($element, self::getFieldTexts($field, $value), $language);

			// Links and SEO settings are copied with whatever text they have translated, text fields without text stay empty
			if (!$translatedTexts) {
				return self::isLinkField($field) || Integrations::isSeoSettingsField($field) ? $field->serializeValue($value, $element) : null;
			}

			return self::withFieldTexts($element, $field, $value, $translatedTexts);
		};

		$types = [...Plugin::getInstance()->getTraduco()->getTranslatableFields(), LinkField::class];
		if (class_exists(TypedLinkField::class)) {
			$types[] = TypedLinkField::class;
		}
		if (class_exists(SeoSettings::class)) {
			$types[] = SeoSettings::class;
		}

		return new ParseOptions(
			fieldHandlers: array_fill_keys($types, $translate),
			attributeFilter: function (Node $node, mixed $value, ElementInterface $element) use ($language): mixed {
				// Entry types without a title field generate their titles when saved
				if ($node->handle !== 'title' || ($element instanceof Entry && !$element->getType()->hasTitleField)) {
					return Omit::VALUE;
				}

				return self::translateValue($element, $value, $language) ?: Omit::VALUE;
			},
		);
	}

	/**
	 * Translates text, from the source element's language. Empty text becomes null, other values are returned as they are.
	 */
	private static function translateValue(ElementInterface $source, mixed $value, string $language): mixed
	{
		if (!is_string($value) && !$value instanceof Stringable) return $value;

		$text = (string)$value;
		if (trim($text) === '') return null;

		return Plugin::getInstance()->getTranslations()->getTranslator()->translate($text, $language, $source->getLanguage());
	}

	/**
	 * @param array<string, string> $texts by attribute
	 * @return array<string, string> the non-empty translations, by attribute
	 */
	private static function translateTexts(ElementInterface $source, array $texts, string $language): array
	{
		$translatedTexts = array_map(fn(string $text): mixed => self::translateValue($source, $text, $language), $texts);

		return array_filter($translatedTexts, fn(mixed $text): bool => is_string($text));
	}

	/**
	 * @param ElementInterface $owner
	 * @param string $fieldHandle
	 * @return ElementInterface[] disabled ones included
	 */
	private static function getNestedElements(ElementInterface $owner, string $fieldHandle): array
	{
		$value = $owner->getFieldValue($fieldHandle);

		if (Integrations::isCKEditorField($owner->getFieldLayout()?->getFieldByHandle($fieldHandle))) {
			return RichText::getEntries($value) ?? [];
		}

		// Disabled blocks too, as they keep their enabled state
		return FieldValueParser::getInstance()->getValues()->getElements($value, withDisabled: true);
	}
}