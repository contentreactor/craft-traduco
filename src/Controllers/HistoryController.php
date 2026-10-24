<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The translation history screen, which lists what was translated and how each translation went
 */
class HistoryController extends Controller
{
	public const PAGE_SIZE = 50;

	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::PERMISSION_HISTORY);
		Plugin::getInstance()->requirePro(Craft::t('traduco', 'Translation history'));

		return true;
	}

	public function actionIndex(): Response
	{
		$history = Plugin::getInstance()->getHistory();
		$total = $history->getTotal();
		$pageCount = max(1, (int)ceil($total / self::PAGE_SIZE));
		$page = min(max(1, (int)$this->request->getParam('page', 1)), $pageCount);

		return $this->renderTemplate('traduco/history/index.twig', [
			'plugin' => Plugin::getInstance(),
			'settings' => Plugin::getInstance()->getSettings()->getUserSettings(),
			'runs' => $history->getRuns(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
			'page' => $page,
			'pageCount' => $pageCount,
			'total' => $total,
		]);
	}
}
