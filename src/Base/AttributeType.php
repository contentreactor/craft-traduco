<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Base;

enum AttributeType: string
{
	case ATTRIBUTE = 'attribute';
	case FIELD = 'field';
	case STRUCTURED = 'structured';
	case NESTED = 'nested';
}