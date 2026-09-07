<?php declare(strict_types=1);

use DG\Fio\Account;
use DG\Fio\BankAccount;
use DG\Fio\DomesticPayment;
use DG\Fio\Payment;
use DG\Fio\SepaPayment;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$czk = new Account('2400222222', '2010', 'CZK');
$eur = new Account('2500222222', '2010', 'EUR');
$future = (new DateTimeImmutable('+10 days'))->format('Y-m-d');


test('domestic payment XML follows the XSD sequence', function () use ($czk, $future) {
	$payment = new DomesticPayment(
		to: BankAccount::parse('19-2000145399/0800'),
		amount: 100,
		date: $future,
		variableSymbol: '1234567890',
		constantSymbol: '0558',
		specificSymbol: '42',
		message: 'Hračky pro děti',
		comment: 'MCP',
		paymentType: DomesticPayment::Priority,
	);

	Assert::match(<<<XML
		<?xml version="1.0" encoding="UTF-8"?>
		<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">
			<Orders>
				<DomesticTransaction>
					<accountFrom>2400222222</accountFrom>
					<currency>CZK</currency>
					<amount>100.00</amount>
					<accountTo>19-2000145399</accountTo>
					<bankCode>0800</bankCode>
					<ks>0558</ks>
					<vs>1234567890</vs>
					<ss>42</ss>
					<date>$future</date>
					<messageForRecipient>Hračky pro děti</messageForRecipient>
					<comment>MCP</comment>
					<paymentType>431005</paymentType>
				</DomesticTransaction>
			</Orders>
		</Import>

		XML, $payment->toXml($czk));
});


test('optional elements are omitted, date defaults to today', function () use ($czk) {
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 0.1 + 0.2);
	$xml = $payment->toXml($czk);

	Assert::contains('<amount>0.30</amount>', $xml);
	Assert::contains('<date>' . Payment::today() . '</date>', $xml);
	Assert::notContains('<vs>', $xml);
	Assert::notContains('<messageForRecipient>', $xml);
	Assert::contains('<paymentType>431001</paymentType>', $xml);
});


test('amount validation', function () {
	$to = BankAccount::parse('1234567899/2010');
	Assert::same('1250.50', (new DomesticPayment($to, 1250.5))->amount);
	Assert::same('0.01', (new DomesticPayment($to, 0.01))->amount);
	Assert::exception(fn() => new DomesticPayment($to, 1.005), InvalidArgumentException::class, '%a%two decimal places%a%');
	Assert::exception(fn() => new DomesticPayment($to, 0), InvalidArgumentException::class, 'Amount must be a positive number.');
	Assert::exception(fn() => new DomesticPayment($to, -5), InvalidArgumentException::class);
	Assert::exception(fn() => new DomesticPayment($to, INF), InvalidArgumentException::class);
});


test('symbol, text and date validation', function () {
	$to = BankAccount::parse('1234567899/2010');
	Assert::exception(fn() => new DomesticPayment($to, 1, variableSymbol: '12345678901'), InvalidArgumentException::class, 'Variable symbol must be up to 10 digits%a%');
	Assert::exception(fn() => new DomesticPayment($to, 1, variableSymbol: 'FA-1'), InvalidArgumentException::class);
	Assert::exception(fn() => new DomesticPayment($to, 1, constantSymbol: '12345'), InvalidArgumentException::class);
	Assert::exception(fn() => new DomesticPayment($to, 1, message: str_repeat('ž', 141)), InvalidArgumentException::class, '%a%at most 140 characters%a%');
	Assert::same(str_repeat('ž', 140), (new DomesticPayment($to, 1, message: str_repeat('ž', 140)))->message);
	Assert::null((new DomesticPayment($to, 1, variableSymbol: ' '))->variableSymbol);
	Assert::exception(fn() => new DomesticPayment($to, 1, date: '2020-01-01'), InvalidArgumentException::class, '%a%in the past%a%');
	Assert::exception(fn() => new DomesticPayment($to, 1, date: '2030-02-30'), InvalidArgumentException::class, '%a%expected YYYY-MM-DD%a%');
});


test('non-CZK domestic payment only within Fio', function () use ($eur) {
	$xml = (new DomesticPayment(BankAccount::parse('1234567899/2010'), 5))->toXml($eur);
	Assert::contains('<currency>EUR</currency>', $xml);

	Assert::exception(
		fn() => (new DomesticPayment(BankAccount::parse('19-2000145399/0800'), 5))->toXml($eur),
		InvalidArgumentException::class,
		'%a%only to accounts at Fio banka%a%',
	);
});


test('SEPA payment XML, message split into remittance lines', function () use ($eur, $future) {
	$payment = new SepaPayment(
		iban: 'AT61 1904 3002 3457 3201',
		amount: 99.9,
		recipientName: 'Hans Gruber',
		bic: 'abagatwwxxx',
		recipientStreet: 'Gugitzgasse 2',
		recipientCity: 'Wien',
		recipientCountry: 'at',
		date: $future,
		variableSymbol: '2024017',
		message: str_repeat('a', 35) . str_repeat('b', 35) . 'ccc',
		comment: 'MCP',
		paymentType: SepaPayment::Instant,
	);

	Assert::match(<<<XML
		<?xml version="1.0" encoding="UTF-8"?>
		<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">
			<Orders>
				<T2Transaction>
					<accountFrom>2500222222</accountFrom>
					<currency>EUR</currency>
					<amount>99.90</amount>
					<accountTo>AT611904300234573201</accountTo>
					<vs>2024017</vs>
					<bic>ABAGATWWXXX</bic>
					<date>$future</date>
					<comment>MCP</comment>
					<benefName>Hans Gruber</benefName>
					<benefStreet>Gugitzgasse 2</benefStreet>
					<benefCity>Wien</benefCity>
					<benefCountry>AT</benefCountry>
					<remittanceInfo1>aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa</remittanceInfo1>
					<remittanceInfo2>bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb</remittanceInfo2>
					<remittanceInfo3>ccc</remittanceInfo3>
					<paymentType>431018</paymentType>
				</T2Transaction>
			</Orders>
		</Import>

		XML, $payment->toXml($eur));
});


test('SEPA message is split on spaces, never inside a word', function () use ($eur) {
	$xml = (new SepaPayment('AT611904300234573201', 1, 'Hans', message: 'Faktura 2024017 za konzultace a implementaci systemu'))->toXml($eur);

	Assert::contains('<remittanceInfo1>Faktura 2024017 za konzultace a</remittanceInfo1>', $xml);
	Assert::contains('<remittanceInfo2>implementaci systemu</remittanceInfo2>', $xml);
	Assert::notContains('<remittanceInfo3>', $xml);

	// a single word longer than a line still has to be broken
	$xml = (new SepaPayment('AT611904300234573201', 1, 'Hans', message: str_repeat('x', 40)))->toXml($eur);
	Assert::contains('<remittanceInfo1>' . str_repeat('x', 35) . '</remittanceInfo1>', $xml);
	Assert::contains('<remittanceInfo2>xxxxx</remittanceInfo2>', $xml);

	Assert::exception(
		fn() => (new SepaPayment('AT611904300234573201', 1, 'Hans', message: implode(' ', array_fill(0, 5, str_repeat('x', 20)))))->toXml($eur),
		InvalidArgumentException::class,
		'%a%does not fit into 3 lines%a%',
	);
});


test('control characters are refused', function () {
	$to = BankAccount::parse('1234567899/2010');
	Assert::exception(
		fn() => new DomesticPayment($to, 1, message: "Faktura\x01 17"),
		InvalidArgumentException::class,
		'Message for recipient must not contain control characters.',
	);
	Assert::noError(fn() => new DomesticPayment($to, 1, message: "Faktura\t17"));
});


test('currency confirms the account currency', function () use ($czk, $eur) {
	$to = BankAccount::parse('1234567899/2010');
	Assert::noError(fn() => (new DomesticPayment($to, 1, currency: 'czk'))->toXml($czk));
	Assert::exception(
		fn() => (new DomesticPayment($to, 1, currency: 'EUR'))->toXml($czk),
		InvalidArgumentException::class,
		'The account is in CZK, a payment in EUR cannot be sent from it.',
	);
	Assert::noError(fn() => (new DomesticPayment($to, 1, currency: 'EUR'))->toXml($eur));
});


test('SEPA validation', function () use ($czk) {
	$iban = 'AT611904300234573201';
	Assert::exception(fn() => new SepaPayment('AT611904300234573202', 1, 'Hans'), InvalidArgumentException::class, '%a%checksum%a%');
	Assert::exception(fn() => new SepaPayment($iban, 1, ' '), InvalidArgumentException::class, 'Recipient name is required.');
	Assert::exception(fn() => new SepaPayment($iban, 1, str_repeat('x', 36)), InvalidArgumentException::class);
	Assert::exception(fn() => new SepaPayment($iban, 1, 'Hans', recipientCountry: 'AUT'), InvalidArgumentException::class);
	Assert::exception(fn() => new SepaPayment($iban, 1, 'Hans', recipientCountry: 'EL'), InvalidArgumentException::class, "Fio does not accept 'EL' as a recipient country code.");
	Assert::exception(fn() => new SepaPayment($iban, 1, 'Hans', message: str_repeat('x', 106)), InvalidArgumentException::class);
	Assert::exception(
		fn() => (new SepaPayment($iban, 1, 'Hans'))->toXml($czk),
		InvalidArgumentException::class,
		'%a%only from a EUR account%a%',
	);
});


test('fingerprint identifies the same payment', function () {
	$to = BankAccount::parse('1234567899/2010');
	$a = new DomesticPayment($to, 100, variableSymbol: '1', message: 'first');
	$b = new DomesticPayment($to, 100.0, variableSymbol: '1', message: 'second');
	$c = new DomesticPayment($to, 100, variableSymbol: '2');

	Assert::same($a->getFingerprint(), $b->getFingerprint());
	Assert::notSame($a->getFingerprint(), $c->getFingerprint());
	Assert::notSame($a->getFingerprint(), (new SepaPayment('AT611904300234573201', 100, 'Hans', variableSymbol: '1'))->getFingerprint());
});
