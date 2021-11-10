<?php

declare(strict_types=1);

namespace Baraja\Cms\Security;


use Baraja\Cms\Session;

final class Nonce
{
	public const NONCE_QUERY_PARAM = 'brjnonce';

	public const NONCE_EXPIRATION_SECONDS = 60;

	/** @var array<string, int> */
	private static array $lastNonce = [];


	public function setupNonce(): void
	{
		if (self::$lastNonce !== []) {
			return;
		}
		if (headers_sent() === true) {
			throw new \LogicException('HTTP headers can not be sent before setup nonce.');
		}
		self::$lastNonce = (array) (Session::get(Session::WORKFLOW_NONCE) ?? []);
		if (count(self::$lastNonce) > 32 && mt_rand() / mt_getrandmax() < 0.01) { // gc
			$minAllowedTime = $this->getMinimalAllowedTime();
			foreach (self::$lastNonce as $nonce => $time) {
				if ($time < $minAllowedTime) {
					unset(self::$lastNonce[$nonce]);
				}
			}
			Session::set(Session::WORKFLOW_NONCE, self::$lastNonce);
		}
	}


	public function getCurrentNonce(): string
	{
		$this->setupNonce();
		static $cache;
		if ($cache === null) {
			$cache = $this->getRandomNonce();
			Session::set(
				key: Session::WORKFLOW_NONCE,
				value: self::$lastNonce + [$cache => time()],
			);
		}

		return $cache;
	}


	public function getRandomNonce(): string
	{
		$return = '';
		for ($i = 0; $i < 32; $i++) {
			$return .= chr(mt_rand(0, 255));
		}

		return substr(md5($return), 0, 16);
	}


	private function getMinimalAllowedTime(): int
	{
		static $cache;
		if ($cache === null) {
			$cache = time() - self::NONCE_EXPIRATION_SECONDS;
		}

		return $cache;
	}
}
