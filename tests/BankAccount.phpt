<?php declare(strict_types=1);

use DG\Fio\BankAccount;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('parse with and without prefix', function () {
	$account = BankAccount::parse('19-2000145399/0800');
	Assert::same('19', $account->prefix);
	Assert::same('2000145399', $account->number);
	Assert::same('0800', $account->bankCode);
	Assert::same('19-2000145399', $account->getAccountNumber());
	Assert::same('19-2000145399/0800', (string) $account);

	Assert::same('1234567899/2010', (string) BankAccount::parse('1234567899/2010'));
	Assert::same('123-123457/0100', (string) BankAccount::parse(' 123-123457 / 0100 '));
	Assert::same('19-2000145399/0800', (string) BankAccount::parse('000019-2000145399/0800'));
});


test('invalid format', function () {
	Assert::exception(fn() => BankAccount::parse('2000145399'), InvalidArgumentException::class, '%a%expected [prefix-]number/bankCode%a%');
	Assert::exception(fn() => BankAccount::parse('2000145399/80'), InvalidArgumentException::class);
	Assert::exception(fn() => BankAccount::parse('1234567-2000145399/0800'), InvalidArgumentException::class);
	Assert::exception(fn() => BankAccount::parse('00/0800'), InvalidArgumentException::class);
});


test('mod 11 checksum', function () {
	Assert::exception(fn() => BankAccount::parse('2000145398/0800'), InvalidArgumentException::class, '%a%checksum%a%');
	Assert::exception(fn() => BankAccount::parse('18-2000145399/0800'), InvalidArgumentException::class, '%a%checksum%a%');
});


test('IBAN', function () {
	Assert::same('CZ6508000000192000145399', BankAccount::normalizeIban('cz65 0800 0000 1920 0014 5399'));
	Assert::same('AT611904300234573201', BankAccount::normalizeIban('AT611904300234573201'));
	Assert::exception(fn() => BankAccount::normalizeIban('AT611904300234573202'), InvalidArgumentException::class, '%a%checksum%a%');
	Assert::exception(fn() => BankAccount::normalizeIban('2400222222/2010'), InvalidArgumentException::class);
});


test('BIC', function () {
	Assert::same('ABAGATWWXXX', BankAccount::normalizeBic('abagatwwxxx'));
	Assert::same('GIBACZPX', BankAccount::normalizeBic('GIBACZPX'));
	Assert::exception(fn() => BankAccount::normalizeBic('GIBACZP'), InvalidArgumentException::class);
});
