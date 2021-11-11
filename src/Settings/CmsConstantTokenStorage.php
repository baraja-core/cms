<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\TokenStorage;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\DynamicConfiguration\ConfigurationSection;

final class CmsConstantTokenStorage implements TokenStorage
{
	private ConfigurationSection $config;


	public function __construct(
		Configuration $configuration,
	) {
		$this->config = new ConfigurationSection($configuration, 'core');
	}


	public function setToken(string $token): void
	{
		if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			throw new \InvalidArgumentException(
				sprintf('API token "%s" is invalid. Did you use generated token from Baraja Cloud account?', $token),
			);
		}

		$this->config->save('baraja-cloud-token', $token);
	}


	public function getToken(): ?string
	{
		return $this->config->get('baraja-cloud-token');
	}
}
