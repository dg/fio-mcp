Fio MCP Server
==============

MCP server pro [API Fio banky](https://www.fio.cz/bankovni-sluzby/api-bankovnictvi).

Propojte svého AI asistenta s bankovním účtem. Zeptejte se, kdo vám tento měsíc zaplatil,
jestli už přišla platba za fakturu s daným variabilním symbolem, nebo nechte asistenta
připravit platbu. Platba se v bance jen zařadí do dávky a odejde teprve ve chvíli, kdy ji
sami autorizujete v internetovém bankovnictví nebo v aplikaci.


Požadavky
---------

- PHP 8.2+
- ext-curl, ext-mbstring, ext-simplexml, ext-xmlwriter
- API token k účtu u Fio banky


Získání tokenu
--------------

V internetovém bankovnictví otevřete **Nastavení > API** a přidejte nový token:

- pro čtení pohybů stačí oprávnění **Sledování účtu**,
- pro odesílání plateb je potřeba **Sledování účtu a zadávání příkazů**.

Token platí pro jeden účet, nejdéle 180 dní, a začne fungovat zhruba 5 minut po vytvoření.
Kdo token zná, vidí pohyby na účtu, proto s ním zacházejte jako s heslem.


Instalace
---------

```shell
git clone https://github.com/dg/fio-mcp
cd fio-mcp
composer install
```


Konfigurace
-----------

| Proměnná             | Popis |
|----------------------|-------|
| `FIO_TOKEN`          | API token k účtu (povinné) |
| `FIO_ALLOW_PAYMENTS` | `1` povolí odesílání plateb; bez něj server platby odmítá |

Konfigurace pro Claude Code (`.mcp.json`):

```json
{
	"mcpServers": {
		"fio": {
			"command": "php",
			"args": ["/cesta/k/fio-mcp/server.php"],
			"env": {
				"FIO_TOKEN": "...",
				"FIO_ALLOW_PAYMENTS": "1"
			}
		}
	}
}
```

Více účtů = více instancí serveru, každá se svým tokenem a jménem (`fio-firma`, `fio-osobni`).


Dostupné nástroje
-----------------

### fio_list_transactions

Pohyby na účtu za zvolené období. Stáhne celé období najednou a filtruje lokálně.

| Parametr         | Popis |
|------------------|-------|
| `dateFrom`       | první den, `YYYY-MM-DD` (výchozí před 30 dny) |
| `dateTo`         | poslední den včetně (výchozí dnes) |
| `direction`      | `all`, `incoming` (přijaté platby), `outgoing` (odeslané); pohyb s nulovou částkou nepatří ani do jedné skupiny |
| `variableSymbol` | přesný variabilní symbol |
| `counterAccount` | číslo protiúčtu nebo jeho část |
| `text`           | hledá v názvu protiúčtu, zprávě, komentáři a dalších textech |
| `limit`          | kolik nejnovějších pohybů vrátit (výchozí 100, nejvýš 1000) |

Vrací i číslo účtu, IBAN a účetní zůstatek na začátku a na konci období. Účetní zůstatek
nezahrnuje blokace z plateb kartou, nejde tedy o disponibilní zůstatek.

### fio_send_domestic_payment

Platba na účet v ČR ve tvaru `[předčíslí-]číslo/kód banky`. Číslo účtu se ověřuje kontrolním
součtem, takže překlep se odhalí dřív, než platba odejde.

Příkaz jde vždy v měně Fio účtu, banka nic nepřevádí. Parametr `currency` proto slouží jako
pojistka: když se neshoduje s měnou účtu, platba se odmítne ještě před odesláním. Z účtu v jiné
měně než CZK lze posílat jen na účty u Fio banky.

Parametry: `account`, `amount`, `currency`, `variableSymbol`, `constantSymbol`, `specificSymbol`,
`message` (zpráva pro příjemce), `comment` (vaše poznámka), `date` (splatnost, výchozí dnes),
`paymentType` (`standard` nebo `priority`), `allowDuplicate` (zopakuje platbu odmítnutou jako
duplicitu).

### fio_send_sepa_payment

Europlatba v EUR na IBAN, jen z EUR účtu. Parametry: `iban`, `amount`, `recipientName`, `bic`,
`recipientStreet`, `recipientCity`, `recipientCountry`, `message`, symboly, `comment`, `date`,
`paymentType` (`standard`, `priority`, `instant`), `allowDuplicate`.

Zpráva pro příjemce se dělí do tří řádků po 35 znacích, a to na mezerách, aby se slova
nerozpadla. Pokud se do nich nevejde, platba se odmítne.

Obě platební volání vrací i shrnutí toho, co banka skutečně dostala, takže dávku v bankovnictví
snadno porovnáte.


Použití z PHP kódu (bez MCP)
----------------------------

Knihovnu lze použít i přímo jako PHP klienta Fio API, nezávisle na MCP a bez proměnných
prostředí. Hodí se pro vlastní skripty, cronjoby nebo integraci do existující aplikace.

```php
use DG\Fio\BankAccount;
use DG\Fio\DomesticPayment;
use DG\Fio\FioClient;

$client = new FioClient('vas-64-znakovy-token');

// Kdo zaplatil fakturu s VS 2024017?
$statement = $client->getTransactions('2024-01-01', '2024-01-31');
foreach ($statement->transactions as $t) {
	if ($t->isIncoming() && $t->variableSymbol === '2024017') {
		echo "$t->date $t->amount $t->currency od $t->counterAccountName\n";
	}
}
echo "Zůstatek: $statement->closingBalance\n";

// Připrav platbu, v bankovnictví pak čeká na autorizaci
$result = $client->sendPayment(new DomesticPayment(
	to: BankAccount::parse('19-2000145399/0800'),
	amount: 1250.50,
	variableSymbol: '2024017',
	message: 'Faktura 2024017',
));
echo $result->isAccepted() ? "Dávka $result->batchId čeká na autorizaci\n" : implode("\n", $result->messages);
```

Chyby Fio API hlásí klient výjimkou `DG\Fio\FioException`, neplatné údaje platby
`InvalidArgumentException`.


Bezpečnost
----------

- **Platby jsou vypnuté**, dokud nenastavíte `FIO_ALLOW_PAYMENTS=1`.
- **Každá platba čeká na autorizaci** v internetovém bankovnictví nebo v aplikaci. Server sám nic
  nezaplatí.
- **Platby z MCP jsou označené**: jejich poznámka ("Vaše označení") začíná `MCP`, takže je
  v dávkách k autorizaci poznáte.
- **Ochrana proti dvojímu odeslání**: stejnou platbu (příjemce, částka, VS, datum) server do
  10 minut odmítne, pokud ji asistent výslovně neoznačí jako záměrné opakování.
- Texty pohybů (názvy protiúčtů, zprávy) píší cizí lidé. Server je asistentovi předává jako
  nedůvěryhodná data a instruuje ho, aby podle nich nikdy neposílal platby.
- Server nepoužívá zarážku "poslední stažení" (`last`, `set-last-id`), takže neovlivní jiné
  programy, které z účtu stahují pohyby stejným tokenem.


Řešení problémů
---------------

| Hlášení | Příčina a řešení |
|---------|------------------|
| FIO_TOKEN is not set | Serveru chybí token, doplňte ho do konfigurace MCP klienta. |
| Sending payments is disabled | Nastavte `FIO_ALLOW_PAYMENTS=1` a restartujte klienta. |
| one request per token every 30 seconds (HTTP 409) | Fio odmítl dotaz jako příliš častý. Zkuste to znovu za půl minuty. |
| older than 90 days (HTTP 422) | Pohyby starší 90 dní vyžadují dodatečné ověření: v **Nastavení > API** klikněte u tokenu na zámek. Odemčení platí 10 minut. |
| rejected the token (HTTP 500) | Token neexistuje, není aktivní, vypršel, nebo nemá právo zadávat příkazy. |
| a payment in EUR cannot be sent | Měna platby se neshoduje s měnou účtu. Banka nepřevádí, zadejte částku v měně účtu. |
| Fio rejected the payment | Banka příkaz odmítla, důvod je v hlášce. Dávka nevznikla, opravte údaje a pošlete znovu. |
| certificate authority | PHP nezná certifikační autoritu serveru. Nastavte `curl.cainfo` v `php.ini` na aktuální CA bundle. |
| identical payment | Stejná platba už byla odeslána. Ověřte v bankovnictví, zda ji opravdu chcete poslat znovu. |
| unknown whether Fio received the payment | Spojení spadlo během odesílání. Podívejte se do bankovnictví, zda dávka dorazila. |
