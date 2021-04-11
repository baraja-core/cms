<?php

declare(strict_types=1);

namespace Baraja\Cms\User\AdminBar;


use Baraja\AdminBar\Panel\Panel;
use Baraja\Cms\LinkGenerator;

final class LoginAsUserPanel implements Panel
{
	public function __construct(
		private string|int $id,
	) {
	}


	public function getTab(): string
	{
		$url = LinkGenerator::generateInternalLink('User:loginAs', ['id' => $this->id]);

		return '<a href="' . $url . '" class="btn btn-primary">Login as this user</a>';
	}


	public function getBody(): ?string
	{
		return null;
	}


	public function getPriority(): ?int
	{
		return 20;
	}
}
