<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\ElementInterface;
use yii\base\InvalidArgumentException;

class TranslatableElement
{
	public function __construct(
		public int $elementId,
		public int $siteId,
		public int $targetSiteId,
	) {
	}

	public function getElement(): ?ElementInterface
	{
		return Craft::$app->getElements()->getElementById($this->elementId, siteId: $this->siteId);
	}

	/**
	 * Returns the element to translate into, see Traduco::getTargetElement()
	 */
	public function getTranslatedElement(): ElementInterface
	{
		$original = $this->getElement() ?? throw new InvalidArgumentException("Element $this->elementId not found in site $this->siteId.");

		return Plugin::getInstance()->getTraduco()->getTargetElement($original, $this->targetSiteId);
	}
}
