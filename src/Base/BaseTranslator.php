<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Base;

use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Models\{
	TranslatableElement,
	Translation,
};
use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use craft\helpers\{
	ArrayHelper,
	ElementHelper,
};
use Exception;
use MarcusGaius\FieldValueParser\Helpers\RichText;
use yii\base\InvalidArgumentException;

/**
 * Translates elements, fields and texts through the translation service implemented in translate()
 */
abstract class BaseTranslator implements TranslatorInterface
{
	public function translateElement(TranslatableElement $element): ElementInterface
	{
		$original = $element->getElement() ?? throw new InvalidArgumentException("Element $element->elementId not found in site $element->siteId.");
		$target = $element->getTranslatedElement();
		$isNewTarget = !$target->id || $target->isNewForSite;
		$translatedElement = $this->ensureTargetExists($original, $target);
		$language = $translatedElement->getLanguage();

		$nestedFieldHandles = Parser::applyFields($original, $translatedElement, $language);

		if ($this->shouldGenerateSlug($original, $translatedElement, $isNewTarget)) {
			$translatedElement->slug = ElementHelper::generateSlug((string)$translatedElement->title, language: $language);
		}
		if (!Craft::$app->getElements()->saveElement($translatedElement, propagate: false)) {
			throw new Exception('Couldn’t save the translated element: ' . implode(', ', $translatedElement->getFirstErrors()));
		}

		// Shared nested elements are translated in place, after the owner exists in the target site
		Parser::translateSharedNestedElements($original, $translatedElement, $nestedFieldHandles, $language);

		return $translatedElement;
	}

	public function translateField(Translation $translation): bool
	{
		$source = $this->getSourceElement($translation);
		$element = $translation->getTargetElement();
		$field = $translation->attributeType === AttributeType::FIELD ? $this->getField($source, $translation) : null;

		// Nested elements are only saved into sites that already have them, elsewhere their translation is copied instead
		if ($source->getRootOwner()->id !== $source->id && (!$element->id || $element->isNewForSite)) {
			throw new InvalidArgumentException(Craft::t('traduco', 'This block doesn’t exist in the target site. Copy its translation instead.'));
		}

		$isValueShared = match ($translation->attributeType) {
			AttributeType::ATTRIBUTE => !Parser::isAttributeTranslatable($source, $element, $translation->attribute),
			AttributeType::FIELD => Parser::isFieldValueShared($source, $element, $field),
			default => throw new Exception('Invalid attribute type: ' . $translation->attributeType?->value),
		};
		if ($isValueShared) {
			throw new InvalidArgumentException(Craft::t('traduco', '“{attribute}” shares its value with the target site, translating it would overwrite the original.', [
				'attribute' => $translation->attribute,
			]));
		}

		// Rich text with nested entries is translated once the target exists, which may keep them, see Parser::getRichTextEntryPairs()
		$hasNestedEntries = $field && RichText::hasNestedEntries($source->getFieldValue($field->handle));
		$translatedTexts = $hasNestedEntries ? [] : $this->translateTexts($translation);
		if (!$hasNestedEntries && !$translatedTexts) {
			throw new InvalidArgumentException(Craft::t('traduco', '“{attribute}” has no text to translate.', [
				'attribute' => $translation->attribute,
			]));
		}

		// Where the element doesn't exist in the target site yet, it's created with its other values copied as they are
		$element = $this->ensureTargetExists($source, $element);

		if ($field && $hasNestedEntries && ($entryPairs = Parser::getRichTextEntryPairs($source, $element, $field)) !== null) {
			$language = $element->getLanguage();
			$element->setFieldValue($field->handle, Parser::translateRichTextWithEntries($source, $field, $entryPairs, $language));
			if (!Craft::$app->getElements()->saveElement($element, propagate: false)) return false;

			Parser::translateSharedNestedElements($source, $element, [$field->handle], $language);

			return true;
		}

		$translatedTexts = $translatedTexts ?: $this->translateTexts($translation);

		if ($field) {
			$element->setFieldValue($field->handle, Parser::withFieldTexts($element, $field, $this->getValueToTranslateInto($source, $element, $field), $translatedTexts));
		} else {
			$element->{$translation->attribute} = $translatedTexts['value'];
		}

		return Craft::$app->getElements()->saveElement($element, propagate: false);
	}

	public function translateText(Translation $translation): string
	{
		return implode("\n", $this->translateTexts($translation));
	}

	/**
	 * Translates the texts of the translation's attribute into the target site's language, by attribute: the text itself (`value`),
	 * or the texts of fields with several, like links' labels and descriptions
	 *
	 * @return array<string, string>
	 */
	public function translateTexts(Translation $translation): array
	{
		$site = Craft::$app->getSites()->getSiteById((int)$translation->targetSiteId, true) ?? throw new InvalidArgumentException("Invalid site ID: $translation->targetSiteId");
		$sourceLanguage = $this->getSourceElement($translation)->getLanguage();

		return array_map(
			fn(string $text): string => $this->translate($text, $site->language, $sourceLanguage),
			$this->getOriginTexts($translation),
		);
	}

	protected function getOriginText(Translation $translation): string
	{
		return implode("\n", $this->getOriginTexts($translation));
	}

	/**
	 * @return array<string, string> the source's texts, see Parser::getFieldTexts()
	 */
	protected function getOriginTexts(Translation $translation): array
	{
		$origin = $this->getSourceElement($translation);

		return match ($translation->attributeType) {
			AttributeType::ATTRIBUTE => ['value' => (string)$origin->{$translation->attribute}],
			AttributeType::FIELD => Parser::getFieldTexts($this->getField($origin, $translation), $origin->getFieldValue($translation->attribute)),
			default => throw new Exception('Invalid attribute type: ' . $translation->attributeType?->value),
		};
	}

	/**
	 * Makes sure the target of Traduco::getTargetElement() is saved in the target site, with the source's content copied
	 */
	protected function ensureTargetExists(ElementInterface $source, ElementInterface $target): ElementInterface
	{
		$elementsService = Craft::$app->getElements();

		// Where the source can't exist in the target site, Craft's duplication creates a separate copy, nested elements included
		if (!$target->id) {
			return $elementsService->duplicateElement($source, ['siteId' => $target->siteId]);
		}

		if (!$target->isNewForSite) return $target;

		// Add the source to the target site the way the control panel does, which brings its nested elements along
		$targetSite = ArrayHelper::firstWhere(ElementHelper::supportedSitesForElement($source, true), 'siteId', $target->siteId);
		$source->setEnabledForSite(ElementHelper::siteStatusesForElement($source) + [
			$target->siteId => (bool)($targetSite['enabledByDefault'] ?? true),
		]);

		if (!$elementsService->saveElement($source)) {
			throw new Exception('Couldn’t add the element to the target site: ' . implode(', ', $source->getFirstErrors()));
		}

		return $elementsService->getElementById($source->id, $source::class, $target->siteId)
			?? throw new Exception("Couldn’t add element $source->id to site $target->siteId.");
	}

	private function getSourceElement(Translation $translation): ElementInterface
	{
		return $translation->getElement() ?? throw new InvalidArgumentException("Element $translation->elementId not found in site $translation->siteId.");
	}

	private function getField(ElementInterface $element, Translation $translation): FieldInterface
	{
		return $element->getFieldLayout()?->getFieldByHandle((string)$translation->attribute) ?? throw new InvalidArgumentException("Invalid field: $translation->attribute");
	}

	/**
	 * Returns the target's value to put the translated texts into. Links keep the target's own link, or get the source's when the target has none.
	 */
	private function getValueToTranslateInto(ElementInterface $source, ElementInterface $target, FieldInterface $field): mixed
	{
		$value = $target->getFieldValue($field->handle);

		if (Parser::isLinkField($field) && (!$value || (method_exists($value, 'isEmpty') && $value->isEmpty()))) {
			return $source->getFieldValue($field->handle);
		}

		return $value;
	}

	/**
	 * New translations get a slug in their own language. Existing ones only while they still have the source's slug (or none),
	 * so their URLs don't change.
	 */
	private function shouldGenerateSlug(ElementInterface $source, ElementInterface $target, bool $isNewTarget): bool
	{
		if (!$target::hasUris() || !$target->title || !Parser::isAttributeTranslatable($source, $target, 'slug')) return false;
		if ($isNewTarget) return true;

		return !$target->slug || ElementHelper::isTempSlug($target->slug) || $target->slug === $source->slug;
	}
}
