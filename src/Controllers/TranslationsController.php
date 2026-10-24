<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Base\{
	AttributeType,
	BaseTranslator,
	RephraserInterface,
};
use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Jobs\ElementTranslationJob;
use ContentReactor\Traduco\Models\{
	TranslatableElement,
	Translation,
	TranslationRun,
};
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use craft\fieldlayoutelements\CustomField;
use craft\web\Controller;
use Exception;
use yii\base\InvalidArgumentException;
use yii\web\{
	BadRequestHttpException,
	ForbiddenHttpException,
	NotFoundHttpException,
	Response,
};

class TranslationsController extends Controller
{
	public $defaultAction = 'index';

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePostRequest();

		return true;
	}

	public function actionTranslateElement(): Response
	{
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Translating whole elements'));

		$translatableElement = new TranslatableElement(
			(int)$this->request->getRequiredBodyParam('elementId'),
			(int)$this->request->getRequiredBodyParam('siteId'),
			(int)$this->request->getRequiredBodyParam('targetSiteId'),
		);

		if (!Craft::$app->getSites()->getSiteById($translatableElement->targetSiteId, true)) {
			throw new BadRequestHttpException('Invalid target site ID.');
		}

		$element = $translatableElement->getElement();
		if (!$element) {
			throw new NotFoundHttpException('Element not found.');
		}
		if (!$element::isLocalized()) {
			throw new BadRequestHttpException('This element type can’t be translated.');
		}

		$this->requireSavedContent($element);
		$this->requireTranslationAccess($element, $translatableElement->getTranslatedElement());

		// Fail here rather than in the queue when the translator isn't set up
		try {
			$translator = Plugin::getInstance()->getTranslations()->getTranslator();
		} catch (Exception $e) {
			return $this->translationFailure($e);
		}

		// A key with nothing left would translate some of the element's fields, save it half done and fail, and the
		// job is never retried. One sentence now says what a failed queue job would have said in a few minutes.
		$quotaMessage = $translator instanceof BaseTranslator ? $translator->getQuotaMessage() : null;
		if ($quotaMessage !== null) {
			return $this->asFailure($quotaMessage);
		}

		$attributes = Parser::getTranslatableAttributes($element, $translatableElement->getTranslatedElement());
		$warning = $this->getGuardWarning($element, $translatableElement->targetSiteId, TranslationRun::KIND_ELEMENT, $attributes);
		if ($warning !== null) {
			return $this->asConfirm($warning);
		}

		$run = Plugin::getInstance()->getHistory()->start(
			$element,
			$translatableElement->targetSiteId,
			TranslationRun::KIND_ELEMENT,
			userId: Craft::$app->getUser()->getId(),
		);

		Craft::$app->getQueue()->push(new ElementTranslationJob([
			'elementId' => $translatableElement->elementId,
			'siteId' => $translatableElement->siteId,
			'targetSiteId' => $translatableElement->targetSiteId,
			'userId' => Craft::$app->getUser()->getId(),
			// The job finishes the row the queue is waiting on, see Services\History
			'runId' => $run?->id,
		]));

		return $this->asJson([
			'status' => 'success',
			'message' => Craft::t('traduco', 'Element translation into the targeted language has been added to the queue.'),
		]);
	}

	public function actionTranslateField(): Response
	{
		$translation = $this->loadTranslation();
		$this->requireSavedContent($translation->getElement());
		$element = $this->requireTranslationAccess($translation->getElement(), $translation->getTargetElement());

		$attributes = $this->getGuardAttributes($translation);
		$warning = $this->getGuardWarning($element, (int)$translation->targetSiteId, TranslationRun::KIND_FIELD, $attributes);
		if ($warning !== null) {
			return $this->asConfirm($warning);
		}

		$history = Plugin::getInstance()->getHistory();
		$run = $history->start(
			$element,
			(int)$translation->targetSiteId,
			TranslationRun::KIND_FIELD,
			$translation->attribute,
			Craft::$app->getUser()->getId(),
		);

		try {
			$saved = Plugin::getInstance()->getTranslations()->getTranslator()->translateField($translation);
		} catch (Exception $e) {
			$history->markFailed($run, $e);

			return $this->translationFailure($e);
		}

		if (!$saved) {
			$history->markFailed($run, Craft::t('traduco', 'Couldn’t save the translation.'));

			return $this->asFailure(Craft::t('traduco', 'Couldn’t save the translation.'));
		}

		$history->markDone($run);
		// The saved translation as it now reads, so a later run can tell whether anyone has changed it since
		Plugin::getInstance()->getFingerprints()->record(
			$element,
			(int)$translation->targetSiteId,
			TranslationRun::KIND_FIELD,
			$attributes,
			Craft::$app->getElements()->getElementById((int)$element->id, $element::class, (int)$translation->targetSiteId),
		);

		return $this->asJson([
			'status' => 'success',
			'message' => Craft::t('traduco', 'Translated “{attribute}” into {site}.', [
				'attribute' => $translation->attribute,
				'site' => Craft::$app->getSites()->getSiteById((int)$translation->targetSiteId, true)?->getName(),
			]),
		]);
	}

	public function actionTranslateText(): Response
	{
		$translation = $this->loadTranslation();
		$element = $this->requireTranslationAccess($translation->getElement());

		$attributes = $this->getGuardAttributes($translation);
		$warning = $this->getGuardWarning($element, (int)$translation->targetSiteId, TranslationRun::KIND_TEXT, $attributes);
		if ($warning !== null) {
			return $this->asConfirm($warning);
		}

		$history = Plugin::getInstance()->getHistory();
		$run = $history->start(
			$element,
			(int)$translation->targetSiteId,
			TranslationRun::KIND_TEXT,
			$translation->attribute,
			Craft::$app->getUser()->getId(),
		);

		try {
			$translator = Plugin::getInstance()->getTranslations()->getTranslator();
			$translatedTexts = $translator instanceof BaseTranslator
				? $translator->translateTexts($translation)
				: ['value' => $translator->translateText($translation)];
		} catch (Exception $e) {
			$history->markFailed($run, $e);

			return $this->translationFailure($e);
		}

		$history->markDone($run);
		// Nothing was saved, so there's no translation of ours to be overwritten, only text not to pay for twice
		Plugin::getInstance()->getFingerprints()->record($element, (int)$translation->targetSiteId, TranslationRun::KIND_TEXT, $attributes);

		return $this->asTexts($translatedTexts, $this->getField($element, $translation), $translation);
	}

	/**
	 * Rewrites a field's value in its own language, through the translator's own rephrasing, and shows the result for
	 * the editor to copy. It isn't saved: it would go into the site they have open, whose form would overwrite it.
	 */
	public function actionRephraseText(): Response
	{
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Rephrasing values'));

		$translation = $this->loadTranslation();
		$element = $this->requireTranslationAccess($translation->getElement());
		$this->requireSavedContent($element);

		$translator = Plugin::getInstance()->getTranslations()->getTranslator();
		if (!$translator instanceof RephraserInterface) {
			return $this->asFailure(Craft::t('traduco', 'The selected translator can’t rephrase values.'));
		}
		if (($reason = $translator->getRephraseUnavailableReason()) !== null) {
			return $this->asFailure($reason);
		}

		$field = $this->getField($element, $translation);
		if ($field !== null && Parser::isRichTextField($field)) {
			return $this->asFailure(Craft::t('traduco', 'Rich text can’t be rephrased, as its markup would be rewritten as words.'));
		}

		$texts = $field !== null
			? Parser::getFieldTexts($field, $element->getFieldValue($field->handle))
			: ['value' => (string)$element->{$translation->attribute}];
		if (!$texts) {
			return $this->asFailure(Craft::t('traduco', '“{attribute}” has no text to rephrase.', ['attribute' => $translation->attribute]));
		}

		$language = $element->getLanguage();

		$attributes = $this->getGuardAttributes($translation);
		$warning = $this->getGuardWarning($element, (int)$translation->targetSiteId, TranslationRun::KIND_REPHRASE, $attributes);
		if ($warning !== null) {
			return $this->asConfirm($warning);
		}

		// Recorded like a translation: nothing is saved, but DeepL Write bills the same characters, so a rewrite
		// that an editor threw away still has to be accounted for
		$history = Plugin::getInstance()->getHistory();
		$run = $history->start(
			$element,
			(int)$translation->targetSiteId,
			TranslationRun::KIND_REPHRASE,
			$translation->attribute,
			Craft::$app->getUser()->getId(),
		);

		try {
			$rephrased = array_map(fn(string $text): string => $translator->rephrase($text, $language), $texts);
		} catch (Exception $e) {
			$history->markFailed($run, $e);

			return $this->translationFailure($e);
		}

		$history->markDone($run);
		Plugin::getInstance()->getFingerprints()->record($element, (int)$translation->targetSiteId, TranslationRun::KIND_REPHRASE, $attributes);

		return $this->asTexts($rephrased, $field, $translation);
	}

	/**
	 * What the guard should look at for a single attribute, see Services\Fingerprints
	 *
	 * @return array<int, array{type: AttributeType, name: string}>
	 */
	private function getGuardAttributes(Translation $translation): array
	{
		return [[
			'type' => $translation->attributeType ?? AttributeType::FIELD,
			'name' => (string)$translation->attribute,
		]];
	}

	/**
	 * Whether this has already been done, or would overwrite something, phrased for an editor to confirm. Null where
	 * there's nothing to ask about, and null whenever the request says to go ahead regardless.
	 *
	 * @param TranslationRun::KIND_* $kind
	 * @param array<int, array{type: AttributeType, name: string}> $attributes
	 */
	private function getGuardWarning(ElementInterface $element, int $targetSiteId, string $kind, array $attributes): ?string
	{
		if ($this->request->getBodyParam('force')) return null;

		return Plugin::getInstance()->getFingerprints()->check($element, $targetSiteId, $kind, $attributes);
	}

	/**
	 * A third answer beside success and failure: something the editor should see before it runs. Deliberately not
	 * asFailure(), which answers 400 and so reaches the control panel as an error with nothing to click.
	 */
	private function asConfirm(string $message): Response
	{
		return $this->asJson([
			'status' => 'confirm',
			'message' => $message,
		]);
	}

	/**
	 * The texts for the control panel to show, one by one for the fields that have several, like a link's label and
	 * its descriptions. The keys are the ones translating uses, as the same JavaScript shows both.
	 *
	 * @param array<string, string> $texts
	 */
	private function asTexts(array $texts, ?FieldInterface $field, Translation $translation): Response
	{
		return $this->asJson([
			'translatedText' => implode("\n", $texts),
			'translatedTexts' => array_map(
				fn(string $attribute, string $text): array => [
					'label' => $field ? Parser::getFieldTextLabel($field, $attribute) : $translation->attribute,
					'value' => $text,
				],
				array_keys($texts),
				array_values($texts),
			),
			'status' => 'success',
		]);
	}

	private function getField(ElementInterface $element, Translation $translation): ?FieldInterface
	{
		if ($translation->attributeType !== AttributeType::FIELD) return null;

		return $element->getFieldLayout()?->getFieldByHandle((string)$translation->attribute);
	}

	private function loadTranslation(): Translation
	{
		$translation = new Translation();
		$translation->load($this->request->getBodyParams());

		if (!$translation->validate()) {
			throw new BadRequestHttpException(implode(' ', $translation->getFirstErrors()));
		}

		$element = $translation->getElement();
		if (!$element) {
			throw new NotFoundHttpException('Element not found.');
		}

		// Only the fields the plugin offers translations for can be read or written
		try {
			$fieldLayoutField = $element->getFieldLayout()?->getField((string)$translation->attribute);
		} catch (InvalidArgumentException) {
			$fieldLayoutField = null;
		}

		if (
			!$fieldLayoutField ||
			($fieldLayoutField instanceof CustomField) !== ($translation->attributeType === AttributeType::FIELD) ||
			!Plugin::getInstance()->getTraduco()->isFieldTranslatable($fieldLayoutField) ||
			!Parser::isLayoutFieldAllowed($element, $fieldLayoutField)
		) {
			throw new BadRequestHttpException("“{$translation->attribute}” can’t be translated.");
		}

		return $translation;
	}

	/**
	 * Translations are saved from and into saved content, never drafts or revisions
	 */
	private function requireSavedContent(?ElementInterface $element): void
	{
		if ($element && !Plugin::getInstance()->getTraduco()->canTranslate($element)) {
			throw new BadRequestHttpException('Drafts, unsaved changes and revisions can’t be translated.');
		}
	}

	/**
	 * @return ElementInterface the source element, so callers have it without checking for null again
	 */
	private function requireTranslationAccess(?ElementInterface $source, ?ElementInterface $target = null): ElementInterface
	{
		if (!$source) {
			throw new NotFoundHttpException('Element not found.');
		}

		$elementsService = Craft::$app->getElements();

		if (!$elementsService->canView($source) || ($target && !$elementsService->canSave($target))) {
			throw new ForbiddenHttpException('User not authorized to translate this element.');
		}

		return $source;
	}

	private function translationFailure(Exception $e): Response
	{
		Craft::$app->getErrorHandler()->logException($e);

		return $this->asFailure($e->getMessage());
	}
}
