<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Controllers;

use ContentReactor\Traduco\Plugin;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\{
	ForbiddenHttpException,
	Response,
};

/**
 * The control panel section itself. Its screens each have a permission and some need Pro, so which of them a user
 * lands on depends on both, and the section's own URI only forwards.
 */
class CpController extends Controller
{
	public $defaultAction = 'index';
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();

		return true;
	}

	public function actionIndex(): Response
	{
		$navItems = Plugin::getInstance()->getNavItems();
		if ($navItems === []) {
			throw new ForbiddenHttpException('User is not permitted to perform this action');
		}

		$firstItem = reset($navItems);

		return $this->redirect(UrlHelper::cpUrl($firstItem['url']));
	}
}
