<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Helpers;

use ContentReactor\Traduco\Base\{
	AttributeType,
	BaseTranslator,
};
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
use craft\helpers\Json;
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
	 * Every attribute getFields() would translate, named rather than translated, for telling whether an element's
	 * text has changed since it was last translated, see Services\Fingerprints.
	 *
	 * A second walk rather than a refactor of getFields(), which builds each value as it selects it and so can't be
	 * asked what it would translate without paying for it. It's deliberately kept a superset: an attribute this
	 * returns and getFields() doesn't only ever makes the guard warn less often.
	 *
	 * @param ElementInterface $target the element that would be translated into, see Traduco::getTargetElement()
	 * @return array<int, array{type: AttributeType, name: string}>
	 */
	public static function getTranslatableAttributes(ElementInterface $source, ElementInterface $target): array
	{
		if (Integrations::isNavigationNode($source)) {
			return !$source->isElement() && self::isAttributeTranslatable($source, $target, 'title')
				? [['type' => AttributeType::ATTRIBUTE, 'name' => 'title']]
				: [];
		}

		$attributes = [];
		$hasTitleElement = false;

		foreach ($source->getFieldLayout()?->getAllElements() ?? [] as $fieldLayoutElement) {
			if (!$fieldLayoutElement instanceof BaseField) continue;

			if (!$fieldLayoutElement instanceof CustomField) {
				$attribute = $fieldLayoutElement->attribute();
				if ($attribute === 'title') $hasTitleElement = true;
				if (self::isAttributeTranslatable($source, $target, $attribute)) {
					$attributes[] = ['type' => AttributeType::ATTRIBUTE, 'name' => $attribute];
				}

				continue;
			}

			$field = $fieldLayoutElement->getField();
			// A structured field is translated through its nested elements, which have values of their own whatever
			// the owner shares, so it's kept without asking, the way buildTranslatableField() does
			if (!self::isFieldStructured($field) && self::isFieldValueShared($source, $target, $field)) continue;

			$attributes[] = ['type' => AttributeType::FIELD, 'name' => $field->handle];
		}

		if (!$hasTitleElement && self::hasEditableTitle($source) && self::isAttributeTranslatable($source, $target, 'title')) {
			$attributes[] = ['type' => AttributeType::ATTRIBUTE, 'name' => 'title'];
		}

		return $attributes;
	}

	/**
	 * A digest of the text an attribute holds, for telling whether it's the same text as last time, or null where it
	 * holds no text at all. It's the text the translator would be given, so it's the text that costs money.
	 *
	 * Only text: a changed relation, date or number leaves the digest alone, which is why the guard it feeds warns
	 * rather than refuses.
	 */
	public static function getAttributeTextDigest(ElementInterface $element, AttributeType $type, string $name): ?string
	{
		$texts = self::collectTexts($element, $type, $name);
		if ($texts === []) return null;

		// A change detector, not a signature: the attribute's name is in it only so a row read for the wrong
		// attribute can't match by accident
		return md5((string)Json::encode([$name, $texts]));
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

		// An empty value is kept, so an empty source empties the target's attribute, see isFieldKept()
		$value = self::translateValue($source, $source->$attribute, $language);

		return new TranslatableField(AttributeType::ATTRIBUTE, $attribute, $value);
	}

	/**
	 * Sets the translated values on the target element, without saving it
	 *
	 * @param ElementInterface $source
	 * @param ElementInterface $target
	 * @param string $language
	 * @param callable(string): void|null $onProgress called with each attribute's name once it's translated
	 * @return string[] handles of nested element fields to translate in place once the target element is saved
	 */
	public static function applyFields(ElementInterface $source, ElementInterface $target, string $language, ?callable $onProgress = null): array
	{
		$nestedFieldHandles = [];

		// getFields() is a generator that translates as it yields, so this reports after each one rather than up front
		foreach (self::getFields($source, $target, $language) as $field) {
			if ($onProgress !== null) $onProgress((string)$field->attributeName);

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
			if (self::keepsNestedElements($source, $target, $field)) {
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

	/**
	 * Whether the field is written at all. Only a value the source shares with the target, and a field the element doesn't
	 * have, are left out; an empty value is written, so an empty source empties the target.
	 */
	private static function isFieldKept(?TranslatableField $field): bool
	{
		return $field !== null && $field->value !== Omit::VALUE;
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

		return !FieldValueParser::getInstance()->getSites()->isValueShared($source, $node, $target);
	}

	/**
	 * Whether the source and the target read a single stored value for the field, so translating it into the target would
	 * overwrite the source's own value. Only the source in another site does; a separate copy has values of its own.
	 */
	public static function isFieldValueShared(ElementInterface $source, ElementInterface $target, FieldInterface $field): bool
	{
		if (!$source::isLocalized()) return true;

		// The field's node knows how it's stored per site, following nested element fields' propagation, and content blocks
		// being one nested element saved to every site
		return FieldValueParser::getInstance()->getSites()->isValueShared($source, self::getNode($field), $target);
	}

	/**
	 * Whether the target already holds the nested elements to translate in place, rather than a set to build from the
	 * source: the source in another site the field propagates to, and any separate copy, whose nested elements were
	 * duplicated along with it, see translateSharedNestedElements()
	 */
	private static function keepsNestedElements(ElementInterface $source, ElementInterface $target, FieldInterface $field): bool
	{
		if (!$source::isLocalized()) return true;

		// A separate copy was duplicated with nested elements of its own, which are paired with the source's by position
		if ($target->id !== null && $target->id !== $source->id) return true;

		return FieldValueParser::getInstance()->getSites()->isValueShared($source, self::getNode($field), (int)$target->siteId);
	}

	/**
	 * Fields whose nested elements are translated as elements of their own, like Matrix, Neo and Content Block fields.
	 * Addresses fields hold owned nested elements too, but they aren't localized, so they're left alone, see getCopyOptions().
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
			fn(string $html): string => self::translateValue($source, $html, $language, $field) ?? $html,
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
		// Omit::VALUE, for a field the element doesn't have, is passed on: it's what isFieldKept() leaves out, and it has to
		// stay apart from an empty value, which is written
		return FieldValueParser::getInstance()->getValues()->parseField($element, (string)$field->handle, Purpose::WRITE, self::getCopyOptions($language));
	}

	/**
	 * Field Value Parser options translating the text of translatable fields, and keeping only nested elements' titles,
	 * translated. Addresses fields are left out, see the handler below.
	 */
	private static function getCopyOptions(string $language): ParseOptions
	{
		$translate = function (FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context) use ($language): mixed {
			// Link fields only have text to translate with some settings
			if (!self::isFieldTranslatable($field) && !Integrations::isSeoSettingsField($field)) {
				return FieldValueParser::getInstance()->getHandlers()->getHandler($field, $context->purpose)($field, $value, $element, $context);
			}

			$translatedTexts = self::translateTexts($element, self::getFieldTexts($field, $value), $language, $field);

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
			fieldHandlers: [
				...array_fill_keys($types, $translate),
				// An element's addresses aren't localized: every one of its sites reads the same rows, and Craft refuses to
				// save an address in any other site. A separate copy gets its own from Craft's duplication, so they're left
				// out of the value either way, at the top level and inside nested elements alike.
				AddressesField::class => fn(): Omit => Omit::VALUE,
			],
			attributeFilter: function (Node $node, mixed $value, ElementInterface $element) use ($language): mixed {
				// Entry types without a title field generate their titles when saved
				if ($node->handle !== 'title' || ($element instanceof Entry && !$element->getType()->hasTitleField)) {
					return Omit::VALUE;
				}

				$translated = self::translateValue($element, $value, $language);

				// An empty title empties the target's, like an empty field value; only a value that isn't text is left out
				return is_string($translated) || $translated === null ? $translated : Omit::VALUE;
			},
		);
	}

	/**
	 * Translates text, from the source element's language. Empty text becomes null, other values are returned as they are.
	 *
	 * @param FieldInterface|null $field the field the text belongs to, null for an attribute like a title or a slug
	 */
	private static function translateValue(ElementInterface $source, mixed $value, string $language, ?FieldInterface $field = null): mixed
	{
		if (!is_string($value) && !$value instanceof Stringable) return $value;

		$text = (string)$value;
		if (trim($text) === '') return null;

		$translator = Plugin::getInstance()->getTranslations()->getTranslator();
		// Everything an element copy translates comes through here, not through BaseTranslator::translateTexts()
		$options = $translator instanceof BaseTranslator ? $translator->getTranslateOptions($source, $language, $field) : [];

		return $translator->translate($text, $language, $source->getLanguage(), $options);
	}

	/**
	 * @param array<string, string> $texts by attribute
	 * @param FieldInterface|null $field the field the texts belong to
	 * @return array<string, string> the non-empty translations, by attribute
	 */
	private static function translateTexts(ElementInterface $source, array $texts, string $language, ?FieldInterface $field = null): array
	{
		$translatedTexts = array_map(fn(string $text): mixed => self::translateValue($source, $text, $language, $field), $texts);

		return array_filter($translatedTexts, fn(mixed $text): bool => is_string($text));
	}

	/**
	 * @param ElementInterface $owner
	 * @param string $fieldHandle
	 * @return ElementInterface[] disabled ones included
	 */
	/**
	 * The text behind one attribute, nested as it's stored: a list per nested element, in order, so moving a block
	 * counts as a change too.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function collectTexts(ElementInterface $element, AttributeType $type, string $name): array
	{
		if ($type === AttributeType::ATTRIBUTE) {
			$value = trim((string)$element->$name);

			return $value === '' ? [] : ['value' => $value];
		}

		$field = $element->getFieldLayout()?->getFieldByHandle($name);
		if ($field === null) return [];

		// A nested field's text lives on nested elements of their own, which getFieldTexts() knows nothing about:
		// Field Value Parser registers no texts for Matrix, Neo or Content Block fields, so without this a changed
		// block would leave the digest alone and the guard would suppress a translation that was needed
		if (self::isFieldStructured($field)) {
			$nested = [];
			foreach (self::getNestedElements($element, $name) as $nestedElement) {
				$texts = [];
				foreach (self::getDigestibleAttributes($nestedElement) as $attribute) {
					$attributeTexts = self::collectTexts($nestedElement, $attribute['type'], $attribute['name']);
					if ($attributeTexts !== []) $texts[$attribute['name']] = $attributeTexts;
				}
				$nested[] = $texts;
			}

			return array_filter($nested) === [] ? [] : $nested;
		}

		$texts = self::getFieldTexts($field, $element->getFieldValue($name));
		ksort($texts);

		return $texts;
	}

	/**
	 * What to digest on a nested element: everything of its own that could hold text, without asking what a target
	 * site shares. Answering that needs the target's own nested elements, and a target that doesn't exist yet has
	 * none. It over-detects change, which only ever makes the guard warn less often.
	 *
	 * @return array<int, array{type: AttributeType, name: string}>
	 */
	private static function getDigestibleAttributes(ElementInterface $element): array
	{
		$attributes = [];

		foreach ($element->getFieldLayout()?->getAllElements() ?? [] as $fieldLayoutElement) {
			if (!$fieldLayoutElement instanceof BaseField) continue;
			if (!self::isLayoutFieldAllowed($element, $fieldLayoutElement)) continue;

			if (!$fieldLayoutElement instanceof CustomField) {
				// The only attribute Traduco ever writes on a nested element, see getCopyOptions()
				if ($fieldLayoutElement->attribute() === 'title') {
					$attributes[] = ['type' => AttributeType::ATTRIBUTE, 'name' => 'title'];
				}

				continue;
			}

			$field = $fieldLayoutElement->getField();
			if (!self::isFieldStructured($field) && !self::isFieldTranslatable($field)) continue;

			$attributes[] = ['type' => AttributeType::FIELD, 'name' => $field->handle];
		}

		return $attributes;
	}

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