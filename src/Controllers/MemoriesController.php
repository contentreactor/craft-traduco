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
	Response,
};

/**
 * DeepL's translation memories, read and deleted against the account. Everything that writes one stays in DeepL's own
 * interface: it has no call to create a memory, rename one or write a segment, and the SDK's one-call import and export
 * poll until DeepL has finished, which a control panel request can't wait for.
 */
class MemoriesController extends Controller
{
	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::PERMISSION_MEMORIES);
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Translation memories'));

		return true;
	}

	public function actionIndex(): Response
	{
		$translator = $this->getTranslator();

		return $this->renderTemplate('traduco/memories/index.twig', [
			'plugin' => Plugin::getInstance(),
			'memories' => $translator?->getTranslationMemories() ?? [],
			'isConfigured' => $translator !== null,
		]);
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$translator = $this->requireTranslator();

		try {
			$translator->deleteTranslationMemory((string)$this->request->getRequiredBodyParam('memoryId'));
		} catch (Throwable $e) {
			return $this->asFailure($e->getMessage());
		}

		return $this->asSuccess(Craft::t('traduco', 'Translation memory deleted.'), redirect: 'traduco/memories');
	}

	private function getTranslator(): ?DeepL
	{
		$translator = Plugin::getInstance()->getTranslations()->getConfiguredTranslator();

		return $translator instanceof DeepL ? $translator : null;
	}

	private function requireTranslator(): DeepL
	{
		return $this->getTranslator() ?? throw new BadRequestHttpException('Translation memories need DeepL to be the selected translator, with an API key it can use.');
	}
}
