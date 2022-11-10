<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Cms;


final class CmsKeepConnectionResponse
{
	public function __construct(
		public bool $login,
	) {
	}
}
