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
	protected ?TranslatorSettings $settingsModel = null;

	/** How many characters have been sent since the count was last reset, see countCharacters() */
	private int $characterCount = 0;

	/**
	 * The interface declares a constructor taking only the API key, and PHP holds every implementation to that, so the
	 * settings are an extra optional parameter here rather than there: translators extending this class can take them,
	 * and ones that don't keep working.
	 *
	 * @param array<string, mixed> $settings the translator's own settings, see UserSettings::getTranslatorSettings()
	 */
	public function __construct(string $apiKey, protected array $settings = [])
	{
		$this->settingsModel = static::createSettings($settings);
	}

	/**
	 * The translator's own settings as a model, or null where it has none. Static, because the system settings screen
	 * draws a form for every registered translator and can't construct one to do it.
	 *
	 * @param array<string, mixed> $values stored or posted settings
	 */
	public static function createSettings(array $values = []): ?TranslatorSettings
	{
		return null;
	}

	public function getSettingsModel(): ?TranslatorSettings
	{
		return $this->settingsModel;
	}

	/**
	 * Why this translator can't translate anything right now, for the screens that would otherwise queue work bound
	 * to fail. Null means nothing is known to be in the way, which is also the answer where a service can't be asked.
	 *
	 * Declared here rather than on the interface, which PHP would hold every third-party translator to.
	 */
	public function getQuotaMessage(): ?string
	{
		return null;
	}

	/**
	 * How much text has gone to the service since the count was last reset, which is what a translation is billed on.
	 * It's what was sent, not what the service says it counted: the services bill a period rather than a request, so
	 * only the caller knows where one translation ends and the next begins.
	 */
	public function getCharacterCount(): int
	{
		return $this->characterCount;
	}

	/**
	 * Starts the count again. A worker keeps one translator for every job it runs, so a count left standing would be
	 * the worker's rather than the translation's: the history resets it as each run starts, see Services\History.
	 */
	public function resetCharacterCount(): void
	{
		$this->characterCount = 0;
	}

	/**
	 * Counts a text that's about to be sent. Called by translate() and by anything else that sends text, so a
	 * translator that batches or rephrases counts that too.
	 */
	protected function countCharacters(string $text): void
	{
		$this->characterCount += mb_strlen($text);
	}

	/**
	 * Options for translate(), assembled per call from the element, the language it's translated into and the field
	 * the text comes from. Translators with per-call options, like a glossary, a context or a way of handling markup,
	 * return them from here. Both paths that translate text pass them: this class for a field, and
	 * Parser::translateValue() for everything an element copy translates.
	 *
	 * @param FieldInterface|null $field the field the text belongs to, null for an attribute like a title or a slug
	 * @return array<string, mixed>
	 */
	public function getTranslateOptions(ElementInterface $source, string $targetLanguage, ?FieldInterface $field = null): array
	{
		return [];
	}

	/**
	 * @param callable(string): void|null $onProgress called with each attribute's name as it's translated, which is
	 *  how the queue job keeps a long translation's reservation from running out, see ElementTranslationJob
	 */
	public function translateElement(TranslatableElement $element, ?callable $onProgress = null): ElementInterface
	{
		$original = $element->getElement() ?? throw new InvalidArgumentException("Element $element->elementId not found in site $element->siteId.");
		$target = $element->getTranslatedElement();
		$isNewTarget = !$target->id || $target->isNewForSite;
		$translatedElement = $this->ensureTargetExists($original, $target);
		$language = $translatedElement->getLanguage();

		$nestedFieldHandles = Parser::applyFields($original, $translatedElement, $language, $onProgress);

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

		// The target before ensureTargetExists(): a separate copy has no ID yet and shares nothing, see Traduco::getTargetElement()
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
		$source = $this->getSourceElement($translation);
		$sourceLanguage = $source->getLanguage();
		$field = $translation->attributeType === AttributeType::FIELD ? $this->getField($source, $translation) : null;
		$options = $this->getTranslateOptions($source, $site->language, $field);

		return array_map(
			fn(string $text): string => $this->translate($text, $site->language, $sourceLanguage, $options),
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
