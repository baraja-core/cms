<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\DTO;


final class CmsGlobalSettingsResponse
{
	public function __construct(
		public int $startWeekday = 0,
	) {
	}
}
