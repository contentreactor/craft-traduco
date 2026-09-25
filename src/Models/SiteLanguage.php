<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Models;

use Stringable;

class SiteLanguage implements Stringable
{
	public function __construct(
		public string $name,
		public int $siteId,
	)
	{
	}

	public function __toString(): string
	{
		return json_encode($this);
	}
}