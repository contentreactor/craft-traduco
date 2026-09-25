<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Base\{
	AttributeType,
	BaseTranslator,
};
use ContentReactor\Traduco\Helpers\Parser;
use ContentReactor\Traduco\Jobs\ElementTranslationJob;
use ContentReactor\Traduco\Models\{
	TranslatableElement,
	Translation,
};
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\ElementInterface;
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
			Plugin::getInstance()->getTranslations()->getTranslator();
		} catch (Exception $e) {
			return $this->translationFailure($e);
		}

		Craft::$app->getQueue()->push(new ElementTranslationJob([
			'elementId' => $translatableElement->elementId,
			'siteId' => $translatableElement->siteId,
			'targetSiteId' => $translatableElement->targetSiteId,
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
		$this->requireTranslationAccess($translation->getElement(), $translation->getTargetElement());

		try {
			$saved = Plugin::getInstance()->getTranslations()->getTranslator()->translateField($translation);
		} catch (Exception $e) {
			return $this->translationFailure($e);
		}

		if (!$saved) {
			return $this->asFailure(Craft::t('traduco', 'Couldn’t save the translation.'));
		}

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
		$this->requireTranslationAccess($translation->getElement());

		try {
			$translator = Plugin::getInstance()->getTranslations()->getTranslator();
			$translatedTexts = $translator instanceof BaseTranslator
				? $translator->translateTexts($translation)
				: ['value' => $translator->translateText($translation)];
		} catch (Exception $e) {
			return $this->translationFailure($e);
		}

		$element = $translation->getElement();
		$field = $translation->attributeType === AttributeType::FIELD ? $element?->getFieldLayout()?->getFieldByHandle((string)$translation->attribute) : null;

		return $this->asJson([
			'translatedText' => implode("\n", $translatedTexts),
			// Fields with several texts, like links' labels and descriptions, show them one by one
			'translatedTexts' => array_map(
				fn(string $attribute, string $text): array => [
					'label' => $field ? Parser::getFieldTextLabel($field, $attribute) : $translation->attribute,
					'value' => $text,
				],
				array_keys($translatedTexts),
				array_values($translatedTexts),
			),
			'status' => 'success',
		]);
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

	private function requireTranslationAccess(?ElementInterface $source, ?ElementInterface $target = null): void
	{
		if (!$source) {
			throw new NotFoundHttpException('Element not found.');
		}

		$elementsService = Craft::$app->getElements();

		if (!$elementsService->canView($source) || ($target && !$elementsService->canSave($target))) {
			throw new ForbiddenHttpException('User not authorized to translate this element.');
		}
	}

	private function translationFailure(Exception $e): Response
	{
		Craft::$app->getErrorHandler()->logException($e);

		return $this->asFailure($e->getMessage());
	}
}
