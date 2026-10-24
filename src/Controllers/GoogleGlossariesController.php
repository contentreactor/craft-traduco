<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Translators\GoogleTranslateAdvanced;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Throwable;
use yii\web\{
	BadRequestHttpException,
	NotFoundHttpException,
	Response,
};

/**
 * The glossaries of the Google project the service account belongs to. Google builds a glossary from a file in Cloud
 * Storage, so creating one takes a gs:// URI the customer already has: Traduco never writes to their bucket, which
 * would need the Cloud Storage API enabled and a write grant on top of the read grant Google itself needs.
 */
class GoogleGlossariesController extends Controller
{
	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::PERMISSION_GLOSSARIES);
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Glossaries'));

		return true;
	}

	public function actionIndex(): Response
	{
		$translator = $this->getTranslator();
		$isAvailable = $translator !== null && $translator->isGlossaryLocation();

		return $this->renderTemplate('traduco/glossaries/google.twig', [
			'plugin' => Plugin::getInstance(),
			// Not asked for at all where they can't exist, so a `global` project makes no request
			'glossaries' => $isAvailable ? $translator->getGlossaries() : [],
			'isAvailable' => $isAvailable,
			'isConfigured' => $translator !== null,
			'projectId' => $translator?->getProjectId(),
		]);
	}

	public function actionEdit(?string $glossaryId = null): Response
	{
		$translator = $this->requireTranslator();
		$glossary = $glossaryId === null ? null : ($translator->getGlossaries()[$glossaryId] ?? null);
		if ($glossaryId !== null && $glossary === null) {
			throw new NotFoundHttpException('Glossary not found.');
		}

		$entries = [];
		if ($glossaryId !== null && $glossary['set'] === []) {
			try {
				$entries = $translator->getGlossaryEntries($glossaryId);
			} catch (Throwable $e) {
				$this->setFailFlash($e->getMessage());
			}
		}

		return $this->renderTemplate('traduco/glossaries/google-edit.twig', [
			'plugin' => Plugin::getInstance(),
			'glossaryId' => $glossaryId,
			'glossary' => $glossary,
			'entries' => $entries,
			'languages' => $translator->getGlossaryLanguageCodes(),
		]);
	}

	public function actionCreate(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();

		$glossaryId = trim((string)$this->request->getRequiredBodyParam('glossaryId'));
		$source = (string)$this->request->getRequiredBodyParam('source');
		$target = (string)$this->request->getRequiredBodyParam('target');
		$inputUri = trim((string)$this->request->getRequiredBodyParam('inputUri'));

		if (!preg_match('/^[A-Za-z0-9_\-]+$/', $glossaryId)) {
			return $this->asFailure(Craft::t('traduco', 'A glossary’s ID can only have letters, digits, underscores and hyphens in it.'));
		}
		if (!str_starts_with($inputUri, 'gs://')) {
			return $this->asFailure(Craft::t('traduco', 'Google reads a glossary from a file in Cloud Storage, so this has to be a gs:// URI.'));
		}
		if ($source === $target) {
			return $this->asFailure(Craft::t('traduco', 'A glossary translates between two different languages.'));
		}

		try {
			$translator->createGlossary($glossaryId, [$source, $target], $inputUri);
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		// Google builds it in the background and answers a long-running operation, with nothing for Traduco to keep
		Craft::$app->getSession()->setNotice(Craft::t('traduco', 'Google is building the glossary. It will be listed once it has.'));

		return $this->redirect(UrlHelper::cpUrl('traduco/glossaries/google'));
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();

		try {
			$translator->deleteGlossary((string)$this->request->getRequiredBodyParam('glossaryId'));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(
			Craft::t('traduco', 'Google is deleting the glossary.'),
			redirect: 'traduco/glossaries/google',
		);
	}

	public function actionSaveEntry(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$glossaryId = (string)$this->request->getRequiredBodyParam('glossaryId');
		$source = trim((string)$this->request->getRequiredBodyParam('source'));
		$target = trim((string)$this->request->getRequiredBodyParam('target'));

		if ($source === '' || $target === '') {
			return $this->asFailure(Craft::t('traduco', 'A term needs both sides filled in.'));
		}

		try {
			$translator->saveGlossaryEntry($glossaryId, $source, $target, $this->request->getBodyParam('entryId') ?: null);
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Term saved.'), redirect: "traduco/glossaries/google/$glossaryId");
	}

	public function actionDeleteEntry(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$glossaryId = (string)$this->request->getRequiredBodyParam('glossaryId');

		try {
			$translator->deleteGlossaryEntry($glossaryId, (string)$this->request->getRequiredBodyParam('entryId'));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Term deleted.'), redirect: "traduco/glossaries/google/$glossaryId");
	}

	private function getTranslator(): ?GoogleTranslateAdvanced
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof GoogleTranslateAdvanced ? $translator : null;
	}

	private function requireTranslator(): GoogleTranslateAdvanced
	{
		return $this->getTranslator() ?? throw new BadRequestHttpException('These glossaries need Google Translate (Advanced) to be the selected translator, with a service account it can use.');
	}
}
