<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Jobs;

use ContentReactor\Traduco\Models\TranslatableElement;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\queue\BaseJob;

class ElementTranslationJob extends BaseJob
{
	public int $elementId;
	public int $siteId;
	public int $targetSiteId;

	public function execute($queue): void
	{
		$translatableElement = new TranslatableElement($this->elementId, $this->siteId, $this->targetSiteId);

		// Deleted since the job was queued
		if (!$translatableElement->getElement()) return;

		Plugin::getInstance()->getTranslations()->getTranslator()->translateElement($translatableElement);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('traduco', 'Translating element {id} into {site}', [
			'id' => $this->elementId,
			'site' => Craft::$app->getSites()->getSiteById($this->targetSiteId, true)?->getName() ?? $this->targetSiteId,
		]);
	}
}
