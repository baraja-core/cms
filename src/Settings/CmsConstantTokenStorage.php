<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\TokenStorage;
use Baraja\DynamicConfiguration\Configuration;

final class CmsConstantTokenStorage implements TokenStorage
{
	public function __construct(
		private Configuration $configuration,
	) {
	}


	public function setToken(string $token): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
			throw new \InvalidArgumentException('API token "' . $token . '" is invalid. Did you use generated token from Baraja Cloud account?');
		}

		$this->configuration->save('baraja-cloud-token', $token, 'core');
	}


	public function getToken(): ?string
	{
		return $this->configuration->get('baraja-cloud-token', 'core');
	}
}
