<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use ContentReactor\Traduco\Base\AttributeType;
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\{
	ElementInterface,
	Model,
};
use craft\validators\SiteIdValidator;
use yii\base\InvalidArgumentException;

/**
 * @property-read ElementInterface|null $element
 */
class Translation extends Model
{
	public ?string $attribute = null;
	public ?AttributeType $attributeType = null;
	public ?int $elementId = null;
	public ?int $siteId = null;
	public ?int $targetSiteId = null;
	private ?ElementInterface $_element = null;

	/**
	 * @param array<string, mixed> $data
	 * @param string|null $formName
	 */
	public function load($data, $formName = null): bool
	{
		$success = parent::load($data, $formName);

		if ($this->elementId !== null && $this->siteId !== null) {
			$this->_element = Craft::$app->getElements()->getElementById($this->elementId, siteId: $this->siteId);
		}

		return $success;
	}

	/**
	 * Returns the source element
	 */
	public function getElement(): ?ElementInterface
	{
		return $this->_element;
	}

	/**
	 * Returns the element to translate into, see Traduco::getTargetElement()
	 */
	public function getTargetElement(): ElementInterface
	{
		$source = $this->getElement() ?? throw new InvalidArgumentException("Element $this->elementId not found in site $this->siteId.");

		return Plugin::getInstance()->getTraduco()->getTargetElement($source, (int)$this->targetSiteId);
	}

	/**
	 * @return array<int, array<int|string, mixed>>
	 */
	protected function defineRules(): array
	{
		return [
			[[
				'attribute',
				'attributeType',
				'elementId',
				'siteId',
				'targetSiteId',
			], 'required'],

			[['elementId', 'siteId', 'targetSiteId'], 'number', 'integerOnly' => true],
			[['siteId', 'targetSiteId'], SiteIdValidator::class],
		];
	}
}
