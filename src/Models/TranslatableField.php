<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use ContentReactor\Traduco\Base\AttributeType;

class TranslatableField
{
	public AttributeType $attributeType;

	public function __construct(
		string|AttributeType $attributeType,
		public string $attributeName,
		public mixed $value = null,
	) {
		$this->attributeType = $attributeType instanceof AttributeType ? $attributeType : AttributeType::from($attributeType);
	}
}