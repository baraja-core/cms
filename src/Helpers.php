<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\Container\Container;
use Baraja\Network\Ip;
use Baraja\PhoneNumber\PhoneNumberFormatter;
use Baraja\Url\Url;
use Latte\Engine;
use Nette\Utils\Strings;

final class Helpers
{
	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . self::class . ' is static and cannot be instantiated.');
	}


	public static function formatApiMethod(string $signal): string
	{
		return (string) preg_replace_callback(
			'/-([a-z])/',
			static fn(array $match): string => mb_strtoupper($match[1], 'UTF-8'),
			'action' . str_replace('.', '', Strings::firstUpper($signal)),
		);
	}


	/** @deprecated since 2021-09-11 use Ip::get() instead. */
	public static function userIp(): string
	{
		return Ip::get();
	}


	/**
	 * @param string $data -> a string of length divisible by five
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function otpBase32Encode(string $data): string
	{
		static $codes = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach (str_split($data) as $c) {
			$bits .= sprintf('%08b', \ord($c));
		}
		$return = '';
		foreach (str_split($bits, 5) as $c) {
			$return .= $codes[bindec($c)];
		}

		return $return;
	}


	/**
	 * Generate URL for OTP QR code
	 *
	 * @param string $issuer -> service (or project) name
	 * @param string $user -> username (displayed in Authenticator app)
	 * @param string $secret -> in binary format
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function getOtpQrUrl(string $issuer, string $user, string $secret): string
	{
		return 'https://chart.googleapis.com/chart?chs=500x500&chld=M|0&cht=qr&chl='
			. urlencode(
				'otpauth://totp/' . rawurlencode($issuer)
				. ':' . $user . '?secret=' . self::otpBase32Encode($secret)
				. '&issuer=' . rawurlencode($issuer),
			);
	}


	/**
	 * Generate one-time password
	 *
	 * @param string $secret -> in binary format
	 * @param string $timeSlot -> example: floor(time() / 30)
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function getOtp(string $secret, string $timeSlot): int
	{
		$data = str_pad(pack('N', $timeSlot), 8, "\0", STR_PAD_LEFT);
		$hash = hash_hmac('sha1', $data, $secret, true);
		$offset = \ord(\substr($hash, -1)) & 0xF;
		$unpacked = (array) unpack('N', substr($hash, $offset, 4));

		return ($unpacked[1] & 0x7FFFFFFF) % 1e6;
	}


	public static function checkAuthenticatorOtpCodeManually(string $otpCode, int $code): bool
	{
		$checker = static fn(int $timeSlot): bool => self::getOtp($otpCode, (string) $timeSlot) === $code;

		return $checker($slot = (int) floor(time() / 30)) || $checker($slot - 1) || $checker($slot + 1);
	}


	/**
	 * @deprecated since 2021-04-21, use PhoneNumberFormatter::fix() instead.
	 * Normalize phone to basic format if pattern match.
	 *
	 * @param int $region use this prefix when number prefix does not exist
	 */
	public static function fixPhone(string $phone, int $region = 420): string
	{
		trigger_error(
			__METHOD__ . ': Method fixPhone() is deprecated and will be removed soon, '
			. 'please use PhoneNumberFormatter::fix() instead.',
		);

		return PhoneNumberFormatter::fix($phone, $region);
	}


	/**
	 * Advance function for parsing real user full name.
	 * Accept name in format "Doc. Ing. Jan Barášek, PhD."
	 *
	 * @return array{firstName: string|null, lastName: string|null, degreeBefore: string|null, degreeAfter: string|null}
	 */
	public static function nameParser(string $name): array
	{
		static $degreePattern = '((?:(?:\s*(?:[A-Za-z]{2,8})\.\s*)+))?';
		$normalized = str_replace(',', '', trim(str_replace('/\s+/', ' ', $name)));
		$degreeBefore = '';
		$degreeAfter = '';

		if (preg_match('/^' . $degreePattern . '\s*([^.]+?)?\s*' . $degreePattern . '$/', $normalized, $degreeParser) === 1) {
			$normalized = trim($degreeParser[2] ?? '');
			$degreeBefore = trim($degreeParser[1] ?? '');
			$degreeAfter = trim($degreeParser[3] ?? '');
		}

		$parts = explode(' ', $normalized, 2);
		$firstName = Strings::firstUpper($parts[0] ?? '');
		$lastName = Strings::firstUpper($parts[1] ?? '');

		return [
			'firstName' => $firstName !== '' ? $firstName : null,
			'lastName' => $lastName !== '' ? $lastName : null,
			'degreeBefore' => $degreeBefore !== '' ? $degreeBefore : null,
			'degreeAfter' => $degreeAfter !== '' ? $degreeAfter : null,
		];
	}


	public static function formatPresenterNameToUri(string $name): string
	{
		return trim((string) preg_replace_callback('/([A-Z])/', static fn(array $match): string => '-' . mb_strtolower($match[1], 'UTF-8'), $name), '-');
	}


	public static function formatPresenterNameByUri(string $name): string
	{
		return Strings::firstUpper(self::formatPresenter(mb_strtolower($name, 'UTF-8')));
	}


	public static function formatActionNameByUri(string $name): string
	{
		return trim(self::formatPresenter($name), '/');
	}


	/**
	 * Convert URI case to Presenter name case. The first character will not be enlarged automatically.
	 *
	 * For example: "article-manager" => "articleManager".
	 */
	public static function formatPresenter(string $haystack): string
	{
		return (string) preg_replace_callback('/-([a-z])/', static fn(array $match): string => mb_strtoupper($match[1], 'UTF-8'), $haystack);
	}


	/**
	 * Escapes string for use inside HTML attribute value.
	 */
	public static function escapeHtmlAttr(string $s, bool $double = true): string
	{
		if (str_contains($s, '`') && strpbrk($s, ' <>"\'') === false) {
			$s .= ' '; // protection against innerHTML mXSS vulnerability nette/nette#1496
		}

		return htmlspecialchars($s, ENT_QUOTES, 'UTF-8', $double);
	}


	/**
	 * Escapes string for use inside HTML comments.
	 */
	public static function escapeHtmlComment(string $s): string
	{
		if ($s !== '' && (str_starts_with($s, '-') || str_starts_with($s, '>') || str_starts_with($s, '!'))) {
			$s = ' ' . $s;
		}

		$s = str_replace('--', '- - ', $s);
		if (substr($s, -1) === '-') {
			$s .= ' ';
		}

		return $s;
	}


	public static function minifyHtml(string $haystack): string
	{
		$return = (string) preg_replace_callback(
			'#[ \t\r\n]+|<(/)?(textarea|pre)(?=\W)#i',
			static fn(array $match): string => ($match[2] ?? '') === '' ? ' ' : $match[0],
			$haystack,
		);
		$return = (string) preg_replace('/(\w|;)\s+({|})\s+(\w|\.|#)/', '$1$2$3', $return);
		$return = str_replace(';}', '}', $return);
		$return = (string) preg_replace('/(\w)\s*:\s+(\w|#|-|.)/', '$1:$2', $return);

		return (string) preg_replace('/\s*\/\*+[^\*]+\*+\/\s*/', '', $return);
	}


	public static function formatUsername(string $username): string
	{
		$username = mb_strtolower(trim($username), 'UTF-8');
		if (mb_strlen($username, 'UTF-8') > 64) {
			throw new \InvalidArgumentException(sprintf('Username "%s" is too long.', $username));
		}
		if (preg_match('/^[a-z0-9@\-_.]+$/', $username) !== 1) {
			throw new \InvalidArgumentException(sprintf('Username "%s" is not valid, because it contains forbidden characters.', $username));
		}

		return $username;
	}


	/**
	 * @return never-return
	 */
	public static function brokenAdmin(\Throwable $e): void
	{
		$logged = false;
		$container = Container::getSingleton();
		$correlationId = null;
		if ($container !== null) {
			try {
				$correlationId = $container->getRequestId();
				$container->getLogger()->debug($e->getMessage(), [
					'exception' => $e,
					'request_id' => self::getRequestId(),
				]);
				$logged = true;
			} catch (\Throwable) {
				// Silence is golden.
			}
		}
		for ($ttl = 10; $ttl > 0; $ttl--) {
			if (ob_get_length() === false || ob_end_clean() === true) {
				break;
			}
		}
		echo self::minifyHtml((new Engine)
			->renderToString(__DIR__ . '/../template/broken-admin.latte', [
				'basePath' => Url::get()->getBaseUrl(),
				'exception' => $e,
				'correlationId' => $correlationId,
				'isLogged' => $logged,
				'isDebug' => Configuration::get()->isDebugMode(),
			]));
		die;
	}


	public static function getRequestId(): ?string
	{
		$container = Container::getSingleton();
		if ($container !== null) {
			try {
				return $container->getRequestId();
			} catch (\Throwable) {
				// Silence is golden.
			}
		}

		return null;
	}
}
