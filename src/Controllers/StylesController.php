<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Plugin;
use ContentReactor\Traduco\Translators\DeepL;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\{
	BadRequestHttpException,
	NotFoundHttpException,
	Response,
};

/**
 * DeepL's style rules and the custom instructions they carry, read and written against the account rather than kept
 * here. A rule's configured rules are left alone: DeepL documents neither their keys nor their allowed values, so a
 * screen would be guessing, and they can still be set in DeepL's own interface.
 */
class StylesController extends Controller
{
	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::PERMISSION_STYLES);
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Style rules'));

		return true;
	}

	public function actionIndex(): Response
	{
		$translator = $this->getTranslator();

		return $this->renderTemplate('traduco/styles/index.twig', [
			'plugin' => Plugin::getInstance(),
			'rules' => $translator?->getStyleRules() ?? [],
			'isConfigured' => $translator !== null,
			'languages' => $this->getLanguageOptions(),
		]);
	}

	public function actionEdit(string $styleId): Response
	{
		$translator = $this->requireTranslator();
		$rule = $translator->getStyleRules()[$styleId] ?? null;
		if ($rule === null) {
			throw new NotFoundHttpException('Style rule not found.');
		}

		return $this->renderTemplate('traduco/styles/edit.twig', [
			'plugin' => Plugin::getInstance(),
			'styleId' => $styleId,
			'rule' => $rule,
		]);
	}

	public function actionCreate(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$name = trim((string)$this->request->getRequiredBodyParam('name'));
		$language = (string)$this->request->getRequiredBodyParam('language');

		if ($name === '' || $language === '') {
			return $this->asFailure(Craft::t('traduco', 'A style rule needs a name and the language it’s written for.'));
		}

		try {
			$styleId = $translator->createStyleRule($name, $language);
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Style rule created.'), redirect: "traduco/styles/$styleId");
	}

	public function actionRename(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$styleId = (string)$this->request->getRequiredBodyParam('styleId');

		try {
			$translator->renameStyleRule($styleId, trim((string)$this->request->getRequiredBodyParam('name')));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Style rule renamed.'), redirect: "traduco/styles/$styleId");
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();

		try {
			$translator->deleteStyleRule((string)$this->request->getRequiredBodyParam('styleId'));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Style rule deleted.'), redirect: 'traduco/styles');
	}

	public function actionSaveInstruction(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$styleId = (string)$this->request->getRequiredBodyParam('styleId');
		$label = trim((string)$this->request->getRequiredBodyParam('label'));
		$prompt = trim((string)$this->request->getRequiredBodyParam('prompt'));

		if ($label === '' || $prompt === '') {
			return $this->asFailure(Craft::t('traduco', 'An instruction needs a label and something to say.'));
		}

		try {
			$translator->saveStyleRuleInstruction($styleId, $label, $prompt, $this->request->getBodyParam('instructionId') ?: null);
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Instruction saved.'), redirect: "traduco/styles/$styleId");
	}

	public function actionDeleteInstruction(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();
		$styleId = (string)$this->request->getRequiredBodyParam('styleId');

		try {
			$translator->deleteStyleRuleInstruction($styleId, (string)$this->request->getRequiredBodyParam('instructionId'));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Instruction deleted.'), redirect: "traduco/styles/$styleId");
	}

	/**
	 * A style rule is written for one language, and the sites are what the editor knows
	 *
	 * @return array<int, array{value: string, label: string, disabled?: bool}>
	 */
	private function getLanguageOptions(): array
	{
		$options = [['value' => '', 'label' => Craft::t('traduco', 'Select a language…'), 'disabled' => true]];
		$seen = [];
		foreach (Craft::$app->getSites()->getAllSites() as $site) {
			if (isset($seen[$site->language])) continue;

			$seen[$site->language] = true;
			$options[] = ['value' => $site->language, 'label' => $site->language];
		}

		return $options;
	}

	private function getTranslator(): ?DeepL
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof DeepL ? $translator : null;
	}

	private function requireTranslator(): DeepL
	{
		return $this->getTranslator() ?? throw new BadRequestHttpException('Style rules need DeepL to be the selected translator, with an API key it can use.');
	}
}
