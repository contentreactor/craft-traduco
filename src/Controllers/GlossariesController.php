<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Translators\{
	DeepL,
	GoogleTranslateAdvanced,
};
use Craft;
use craft\helpers\UrlHelper;
use craft\web\{
	Controller,
	UploadedFile,
};
use DeepL\DeepLException;
use Throwable;
use yii\web\{
	BadRequestHttpException,
	NotFoundHttpException,
	Response,
};

/**
 * The glossaries of the DeepL account the API key belongs to. Traduco doesn't store them: every screen here reads from
 * DeepL and writes back to it, so a glossary made in DeepL's own interface is managed from the same place.
 */
class GlossariesController extends Controller
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
		// One Glossaries item in the subnav, serving whichever translator is selected, as the two services model
		// glossaries differently enough that one screen can't do both
		if (Plugin::getInstance()->getTranslations()->getConfiguredTranslator() instanceof GoogleTranslateAdvanced) {
			return $this->redirect(UrlHelper::cpUrl('traduco/glossaries/google'));
		}

		$translator = $this->getTranslator();

		return $this->renderTemplate('traduco/glossaries/index.twig', [
			'plugin' => Plugin::getInstance(),
			'glossaries' => $translator?->getGlossaries() ?? [],
			'isAvailable' => $translator !== null,
		]);
	}

	public function actionEdit(?string $glossaryId = null): Response
	{
		$translator = $this->requireTranslator();
		$glossary = $glossaryId === null ? null : $translator->getGlossary($glossaryId);
		if ($glossaryId !== null && $glossary === null) {
			throw new NotFoundHttpException('Glossary not found.');
		}

		// A glossary holds a dictionary per language pair, and a pair is edited at a time
		$source = $this->request->getParam('source');
		$target = $this->request->getParam('target');
		$entries = [];
		if ($glossaryId !== null && $source !== null && $target !== null) {
			try {
				$entries = $translator->getGlossaryEntries($glossaryId, $source, $target);
			} catch (DeepLException $e) {
				$this->setFailFlash($e->getMessage());
			}
		}

		$languages = $translator->getGlossaryLanguageCodes();

		return $this->renderTemplate('traduco/glossaries/edit.twig', [
			'plugin' => Plugin::getInstance(),
			'glossaryId' => $glossaryId,
			'glossary' => $glossary,
			'sourceOptions' => $this->getLanguageOptions($languages['sources']),
			'targetOptions' => $this->getLanguageOptions($languages['targets']),
			'source' => $source,
			'target' => $target,
			'entriesTsv' => $entries === [] ? '' : DeepL::glossaryEntriesToTsv($entries),
		]);
	}

	/**
	 * A glossary is written for DeepL's language codes, and the sites are what the editor knows, so the options are
	 * labelled by site language and valued by the code Traduco would translate it as
	 *
	 * @param array<string, string> $codes DeepL codes by site language
	 * @return array<int, array{value: string, label: string, disabled?: bool}>
	 */
	private function getLanguageOptions(array $codes): array
	{
		$options = [['value' => '', 'label' => Craft::t('traduco', 'Select a language…'), 'disabled' => true]];
		foreach ($codes as $siteLanguage => $code) {
			$options[] = ['value' => $code, 'label' => "$siteLanguage ($code)"];
		}

		return $options;
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();

		$glossaryId = $this->request->getBodyParam('glossaryId') ?: null;
		$name = trim((string)$this->request->getRequiredBodyParam('name'));
		$source = (string)$this->request->getRequiredBodyParam('source');
		$target = (string)$this->request->getRequiredBodyParam('target');

		if ($name === '') {
			return $this->asFailure(Craft::t('traduco', 'A glossary needs a name.'));
		}
		if ($source === $target) {
			return $this->asFailure(Craft::t('traduco', 'A glossary translates between two different languages.'));
		}

		try {
			$entries = $this->getPostedEntries();
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		try {
			if ($glossaryId === null) {
				$glossaryId = $translator->createGlossary($name, $source, $target, $entries);
			} else {
				$translator->renameGlossary($glossaryId, $name);
				$translator->saveGlossaryDictionary($glossaryId, $source, $target, $entries);
			}
		} catch (DeepLException $e) {
			return $this->asFailure($e->getMessage());
		}

		$this->setSuccessFlash(Craft::t('traduco', 'Glossary saved.'));

		return $this->redirect(UrlHelper::cpUrl("traduco/glossaries/$glossaryId", ['source' => $source, 'target' => $target]));
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$glossaryId = (string)$this->request->getRequiredBodyParam('glossaryId');

		try {
			$translator->deleteGlossary($glossaryId);
		} catch (DeepLException $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Glossary deleted.'), redirect: 'traduco/glossaries');
	}

	public function actionDeleteDictionary(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$glossaryId = (string)$this->request->getRequiredBodyParam('glossaryId');
		$source = (string)$this->request->getRequiredBodyParam('source');
		$target = (string)$this->request->getRequiredBodyParam('target');

		try {
			$translator->deleteGlossaryDictionary($glossaryId, $source, $target);
		} catch (DeepLException $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Dictionary deleted.'), redirect: 'traduco/glossaries');
	}

	/**
	 * The entries the request posts, from an uploaded TSV or CSV file where there is one, and from the text area
	 * otherwise
	 *
	 * @return array<string, string> by source term
	 */
	private function getPostedEntries(): array
	{
		$file = UploadedFile::getInstanceByName('entriesFile');
		if ($file !== null) {
			$contents = file_get_contents($file->tempName);
			if ($contents === false) {
				throw new BadRequestHttpException('Couldn’t read the uploaded file.');
			}

			return DeepL::parseGlossaryEntries($contents, strtolower((string)$file->getExtension()) === 'csv');
		}

		return DeepL::parseGlossaryEntries((string)$this->request->getBodyParam('entries', ''));
	}

	/**
	 * The DeepL translator the account's glossaries are read through, or null where it isn't the one configured
	 */
	private function getTranslator(): ?DeepL
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof DeepL ? $translator : null;
	}

	private function requireTranslator(): DeepL
	{
		return $this->getTranslator() ?? throw new BadRequestHttpException('Glossaries need DeepL to be the selected translator, with an API key it can use.');
	}
}
