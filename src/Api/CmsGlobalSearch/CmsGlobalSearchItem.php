<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\CmsGlobalSearch;


final class CmsGlobalSearchItem
{
	public function __construct(
		public string $title,
		public string $snippet,
		public string $link,
	) {
	}
}
