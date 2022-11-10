<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Cms;


final class CmsSignResponse
{
	public function __construct(
		public bool $loginStatus,
		public ?string $identityId = null,
	) {
	}
}
