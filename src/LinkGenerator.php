<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Plugin\PluginLinkGenerator;
use Baraja\Url\Url;
use Nette\Utils\Random;
use Nette\Utils\Strings;

final class LinkGenerator implements PluginLinkGenerator
{
	public const NONCE_QUERY_PARAM = 'brjnonce';

	public const NONCE_EXPIRATION_SECONDS = 60;

	/** @var array<string, int> */
	private static array $lastNonce = [];


	public static function setupNonce(): void
	{
		if (self::$lastNonce !== []) {
			return;
		}
		if (headers_sent() === true) {
			throw new \LogicException('HTTP headers can not be sent before setup nonce.');
		}
		self::$lastNonce = (array) (Session::get(Session::WORKFLOW_NONCE) ?? []);
		if (count(self::$lastNonce) > 32 && mt_rand() / mt_getrandmax() < 0.01) { // gc
			$minAllowedTime = self::getMinimalAllowedTime();
			foreach (self::$lastNonce as $nonce => $time) {
				if ($time < $minAllowedTime) {
					unset(self::$lastNonce[$nonce]);
				}
			}
			Session::set(Session::WORKFLOW_NONCE, self::$lastNonce);
		}
	}


	/**
	 * @param array<string, mixed> $params
	 */
	public static function generateInternalLink(string $route, array $params = [], bool $nonce = false): string
	{
		if (($route[0] ?? '') === ':') {
			throw new \InvalidArgumentException('Route "' . $route . '" can not be absolute. Please remove the starting colon.');
		}

		[$plugin, $view] = explode(':', trim($route) . ':');

		if ($plugin === '') {
			$plugin = 'Homepage';
		}
		if ($view === '') {
			$view = 'default';
		}
		if ($plugin === 'Admin') {
			throw new \InvalidArgumentException('Route "' . $route . '" is potentially bug (because it\'s just the logic of administration). Did you mean "Homepage:default"?');
		}

		$path = '';
		if ($plugin === 'Homepage') {
			if ($view !== 'default') {
				$path = 'homepage/' . Helpers::formatPresenterNameToUri($view);
			}
		} else {
			$path = Helpers::formatPresenterNameToUri($plugin)
				. ($view !== 'default' ? '/' . Helpers::formatPresenterNameToUri($view) : '');
		}
		if ($nonce === true) {
			self::setupNonce();
			$params[self::NONCE_QUERY_PARAM] = self::getCurrentNonce();
		}

		return Strings::toAscii(
			Url::get()->getBaseUrl() . '/admin' . ($path !== '' ? '/' . $path : '')
			. ($params !== [] ? '?' . http_build_query($params) : '')
		);
	}


	public static function getSafeUrlForCallAgain(): string
	{
		$url = Url::get()->getNetteUrl();
		$url->setQueryParameter(self::NONCE_QUERY_PARAM, self::getCurrentNonce());

		return $url->getAbsoluteUrl();
	}


	public static function getCurrentNonce(): string
	{
		self::setupNonce();
		static $cache;
		if ($cache === null) {
			$cache = Random::generate(16);
			Session::set(
				key: Session::WORKFLOW_NONCE,
				value: self::$lastNonce + [$cache => time()],
			);
		}

		return $cache;
	}


	public static function verifyNonce(): bool
	{
		if (headers_sent() === true) {
			throw new \LogicException('HTTP headers can not be sent before nonce validation.');
		}
		self::getCurrentNonce();
		$nonce = trim((string) ($_GET[self::NONCE_QUERY_PARAM] ?? ''));
		if ($nonce === '') {
			return true;
		}
		if (self::$lastNonce === []) { // bad request, because init safe request has not been called
			return false;
		}
		$minAllowedTime = self::getMinimalAllowedTime();
		if (isset(self::$lastNonce[$nonce]) && self::$lastNonce[$nonce] > $minAllowedTime) {
			unset(self::$lastNonce[$nonce]);
			Session::set(Session::WORKFLOW_NONCE, self::$lastNonce);

			return true;
		}

		return false;
	}


	private static function getMinimalAllowedTime(): int
	{
		static $cache;
		if ($cache === null) {
			$cache = time() - self::NONCE_EXPIRATION_SECONDS;
		}

		return $cache;
	}


	/**
	 * @param array<string, mixed> $params
	 */
	public function link(string $route, array $params = [], bool $nonce = false): string
	{
		return self::generateInternalLink($route, $params, $nonce);
	}
}
