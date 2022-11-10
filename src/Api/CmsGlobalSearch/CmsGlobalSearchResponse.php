<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\CmsGlobalSearch;


final class CmsGlobalSearchResponse
{
	/**
	 * @param CmsGlobalSearchItem[] $results
	 */
	public function __construct(
		public bool $active,
		public string $query,
		public array $results,
	) {
	}
}
