<?php
/**
 * The line that tells the buyer what they are paying for.
 *
 * @package Cherum_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the names of the items in an order into a description Cherum accepts.
 *
 * WHY THIS EXISTS (1.3.11). The description is printed on Cherum's own payment
 * page, so Cherum takes it as plain text only: no links, no website addresses,
 * no markup, no hidden characters. A store's product names are not written
 * with that in mind — "Amazon.com Gift Card", "Cable <10 cm", a name pasted
 * with a zero-width space in it — and from the day Cherum starts enforcing
 * the rule, an invoice carrying such a name is refused with
 * `400 invalid_description`. The buyer would press "Place order" and get an
 * error for something only the shop owner could change.
 *
 * So the plugin cleans the line itself, by the SAME rule the service applies:
 * it reads the text the way the service does (NFKC, the same look-alike dots,
 * the same list of real top-level domains) and changes only what the service
 * would refuse. A domain loses its dot ("Amazon com Gift Card"; a spelt-out
 * " dot " keeps its word: "Polka-Dot Baby Blanket"), a link is dropped with
 * the punctuation that only led to it, `<` and `>` become ‹ and ›, hidden
 * characters go — but not the joiners and variation selectors that are part
 * of an emoji (❤️, 👨‍👩‍👧), which the service accepts. A name with nothing
 * to refuse is sent exactly as it is, and a name that needs a fix keeps its
 * own spelling around the fix ("Brand™", not the "BrandTM" the service reads)
 * whenever that spelling passes too. What cannot be cleaned is replaced by
 * "Order #N".
 *
 * 1.3.12: the service reads more spelt-out dots as the dot of an address —
 * "( dot )" with spaces inside the brackets, the word with a look-alike
 * letter, and "cherum-dot-com" between two hyphens — and so does this class:
 * "cherum-dot-com" is sent as "cherum-dot com".
 *
 * Where the two sides cannot be identical. The PCRE inside PHP carries its own,
 * older, Unicode tables, so nothing here depends on them where it matters:
 * the format characters, the emoji and the CJK separators are spelt out from
 * the service's tables (and compared with them by the service's test). A code
 * point the service treats as unassigned is left for the service to refuse;
 * the gateway then retries once with "Order #N". A variation selector after an
 * ideograph or a Mongolian letter is removed here, though the service allows
 * it.
 *
 * The two sides cannot drift silently: one table of cases is read both by
 * the plugin's own test suite and by the service's, which runs its own check
 * on every line this class produces, and the domain list below is compared
 * with the service's one entry by entry.
 */
final class Cherum_Pay_Description {

	/**
	 * Longest description the service takes, counted the way it counts: in
	 * UTF-16 code units, so an emoji is two.
	 */
	const MAX_UNITS = 500;

	/**
	 * Letters of the scripts whose middle dot is a word separator, not the dot
	 * of a domain ("東京・Tokyo"). The service's exception, character for
	 * character: it reads these scripts by Script_Extensions, so the long vowel
	 * mark "ー", the CJK punctuation and the like count too. A PCRE older than
	 * 10.40 (PHP 7.4–8.1 as shipped, Ubuntu 22.04's library) reads the script
	 * names by Script alone, so the characters that belong to these scripts
	 * only by extension are listed after them, from the service's own tables.
	 */
	const CJK = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bopomofo}\x{B7}\x{2C7}\x{2C9}-\x{2CB}\x{2D9}\x{305}\x{323}\x{2FF0}-\x{2FFF}\x{3001}-\x{3003}\x{3006}\x{3008}-\x{3011}\x{3013}-\x{301F}\x{302A}-\x{302D}\x{3030}-\x{3035}\x{3037}\x{303C}-\x{303F}\x{3099}-\x{309C}\x{30A0}\x{30FB}-\x{30FC}\x{3190}-\x{319F}\x{31C0}-\x{31E5}\x{31EF}\x{3220}-\x{3247}\x{3280}-\x{32B0}\x{32C0}-\x{32CB}\x{32FF}\x{3358}-\x{3370}\x{337B}-\x{337F}\x{33E0}-\x{33FE}\x{A700}-\x{A707}\x{FE45}-\x{FE46}\x{FF61}-\x{FF65}\x{FF70}\x{FF9E}-\x{FF9F}\x{1D360}-\x{1D371}\x{1F250}-\x{1F251}]';

	/** Characters that read as a full stop. */
	const STOPS = '[.\x{3002}\x{FF0E}\x{FF61}\x{2024}\x{2027}]';

	/** Middle dots: a domain dot only when nothing stands around them. */
	const MIDDOTS = '[\x{00B7}\x{2219}\x{22C5}\x{2022}]';

	/**
	 * The word "dot", as the service reads it (1.3.12): each letter is the
	 * Latin one or one of its look-alikes in Unicode's confusables list
	 * (UTS #39, confusables.txt 18.0.0: every source whose prototype is d/D,
	 * o/O or t/T and that NFKC leaves as it is), so "dot" spelt with a Cyrillic o
	 * (U+043E) or "d0t" with a zero reads as the word too. The service's own list,
	 * compared with it character by character by the service's test.
	 */
	const DOT_WORD = '[\x{0044}\x{0064}\x{0501}\x{13A0}\x{13E7}\x{146F}\x{15DE}\x{15EA}\x{A4D2}\x{A4D3}\x{1018B}]'
		. '[\x{0030}\x{004F}\x{006F}\x{039F}\x{03BF}\x{03C3}\x{03ED}\x{041E}\x{043E}\x{0555}\x{0585}\x{05E1}\x{0647}\x{0665}\x{06BE}\x{06C1}\x{06D5}\x{06F5}\x{07C0}\x{07CB}\x{0840}\x{0966}\x{09E6}\x{0A66}\x{0AE6}\x{0B20}\x{0B66}\x{0BE6}\x{0C02}\x{0C66}\x{0C82}\x{0CE6}\x{0D02}\x{0D20}\x{0D66}\x{0D82}\x{0E50}\x{0ED0}\x{101D}\x{1040}\x{10FF}\x{110B}\x{11BC}\x{12D0}\x{17E0}\x{1A45}\x{1A80}\x{1A90}\x{1BEA}\x{1C82}\x{1CBF}\x{1D0F}\x{1D11}\x{2C9E}\x{2C9F}\x{2D54}\x{3007}\x{A4F3}\x{AB3D}\x{10292}\x{102AB}\x{1030F}\x{10404}\x{1042C}\x{104C2}\x{104EA}\x{10516}\x{1092C}\x{10C17}\x{10D07}\x{11124}\x{11302}\x{114D0}\x{118B5}\x{118C8}\x{118D7}\x{118E0}\x{11DE0}\x{16AE9}\x{1E140}\x{1E2F0}]'
		. '[\x{0054}\x{0074}\x{03A4}\x{0422}\x{07E0}\x{13A2}\x{22A4}\x{27D9}\x{2CA6}\x{3112}\x{4E05}\x{A4D4}\x{A50B}\x{10297}\x{102B1}\x{10315}\x{118BC}\x{16F0A}\x{1D373}\x{1F768}]';

	/** The hyphen and its look-alikes (UTS #39, prototype "-", kept by NFKC). */
	const DOT_HYPHENS = '[\x{002D}\x{02D7}\x{06D4}\x{174D}\x{1BF3}\x{2010}\x{2012}\x{2013}\x{2043}\x{2212}\x{2796}\x{2CBA}\x{2CBB}\x{AA7D}\x{10191}]';

	/**
	 * Every way the service reads "the dot of a domain": a stop, a middle dot
	 * pressed between two words, a stop with a space in front, "[.]", "(.)",
	 * " dot ", "[dot]", "(dot)" — with spaces inside the brackets too
	 * ("( dot )", 1.3.12). "cherum-dot-com" is read in twins().
	 */
	const DOT = self::STOPS . '|(?<!' . self::CJK . ')' . self::MIDDOTS . '|\s+' . self::STOPS . '\s*|\s*\[\s*\.\s*\]\s*|\s*\(\s*\.\s*\)\s*|\s+' . self::DOT_WORD . '\s+|\s*\[\s*' . self::DOT_WORD . '\s*\]\s*|\s*\(\s*' . self::DOT_WORD . '\s*\)\s*';

	/** Look-alikes of the full stop (Unicode confusables of "." and kin). */
	const TWINS = '[\x{30FB}\x{2E31}\x{2E33}\x{2E3C}\x{2E30}\x{25E6}\x{2981}\x{16EB}\x{16EC}\x{0589}\x{1427}\x{A4F8}\x{A4FA}\x{A4FB}\x{119E}\x{0660}\x{06F0}\x{0701}\x{0702}\x{A60E}\x{10101}\x{10A50}\x{1D16D}\x{A78F}\x{2218}\x{2B1D}\x{25AA}\x{1F784}\x{0971}]';

	/** Punctuation of another script, which has no business inside a Latin word. */
	const FOREIGN = '(?:(?![\p{Common}\p{Latin}\p{Inherited}])\p{P})';

	/**
	 * Characters nobody sees: controls, format characters, private use, line
	 * separators, the blank "filler" letters, every Default_Ignorable_Code_Point
	 * (variation selectors, zero-width joiners, tags, soft hyphen…) and the
	 * noncharacters. Spelt out as ranges because the PCRE that PHP 7.4 ships
	 * has no name for Default_Ignorable, and because a PCRE's Unicode is older
	 * than the service's: the format characters (Cf) are listed from the
	 * service's tables too. Unassigned code points are NOT in here: PHP reads
	 * every character newer than its own tables as unassigned — the 🩷 of
	 * Unicode 15 among them — and would strip it, while the service, which
	 * knows it, accepts it. A code point the service itself calls unassigned
	 * is refused there, and the gateway's retry sends "Order #N".
	 */
	const HIDDEN = '[\p{Cc}\p{Cf}\p{Co}\p{Zl}\p{Zp}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2800}\x{3164}\x{FFA0}\x{00AD}\x{034F}\x{061C}\x{180B}-\x{180F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFF0}-\x{FFF8}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}\x{0600}-\x{0605}\x{06DD}\x{070F}\x{0890}-\x{0891}\x{08E2}\x{FFF9}-\x{FFFB}\x{110BD}\x{110CD}\x{13430}-\x{1343F}\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]';

	/**
	 * Extended_Pictographic: the characters an emoji is built on, from the
	 * service's tables (PCRE before 10.40 has no name for the property). A
	 * zero-width joiner between two of them, a variation selector right after
	 * one, are part of the emoji — the service allows them, so they stay.
	 */
	const EMOJI = '[\x{A9}\x{AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}-\x{21AA}\x{231A}-\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{2604}\x{260E}\x{2611}\x{2614}-\x{2615}\x{2618}\x{261D}\x{2620}\x{2622}-\x{2623}\x{2626}\x{262A}\x{262E}-\x{262F}\x{2638}-\x{263A}\x{2640}\x{2642}\x{2648}-\x{2653}\x{265F}-\x{2660}\x{2663}\x{2665}-\x{2666}\x{2668}\x{267B}\x{267E}-\x{267F}\x{2692}-\x{2697}\x{2699}\x{269B}-\x{269C}\x{26A0}-\x{26A1}\x{26A7}\x{26AA}-\x{26AB}\x{26B0}-\x{26B1}\x{26BD}-\x{26BE}\x{26C4}-\x{26C5}\x{26C8}\x{26CE}-\x{26CF}\x{26D1}\x{26D3}-\x{26D4}\x{26E9}-\x{26EA}\x{26F0}-\x{26F5}\x{26F7}-\x{26FA}\x{26FD}\x{2702}\x{2705}\x{2708}-\x{270D}\x{270F}\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2728}\x{2733}-\x{2734}\x{2744}\x{2747}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2763}-\x{2764}\x{2795}-\x{2797}\x{27A1}\x{27B0}\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F004}\x{1F02C}-\x{1F02F}\x{1F094}-\x{1F09F}\x{1F0AF}-\x{1F0B0}\x{1F0C0}\x{1F0CF}-\x{1F0D0}\x{1F0F6}-\x{1F0FF}\x{1F170}-\x{1F171}\x{1F17E}-\x{1F17F}\x{1F18E}\x{1F191}-\x{1F19A}\x{1F1AE}-\x{1F1E5}\x{1F201}-\x{1F20F}\x{1F21A}\x{1F22F}\x{1F232}-\x{1F23A}\x{1F23C}-\x{1F23F}\x{1F249}-\x{1F25F}\x{1F266}-\x{1F321}\x{1F324}-\x{1F393}\x{1F396}-\x{1F397}\x{1F399}-\x{1F39B}\x{1F39E}-\x{1F3F0}\x{1F3F3}-\x{1F3F5}\x{1F3F7}-\x{1F3FA}\x{1F400}-\x{1F4FD}\x{1F4FF}-\x{1F53D}\x{1F549}-\x{1F54E}\x{1F550}-\x{1F567}\x{1F56F}-\x{1F570}\x{1F573}-\x{1F57A}\x{1F587}\x{1F58A}-\x{1F58D}\x{1F590}\x{1F595}-\x{1F596}\x{1F5A4}-\x{1F5A5}\x{1F5A8}\x{1F5B1}-\x{1F5B2}\x{1F5BC}\x{1F5C2}-\x{1F5C4}\x{1F5D1}-\x{1F5D3}\x{1F5DC}-\x{1F5DE}\x{1F5E1}\x{1F5E3}\x{1F5E8}\x{1F5EF}\x{1F5F3}\x{1F5FA}-\x{1F64F}\x{1F680}-\x{1F6C5}\x{1F6CB}-\x{1F6D2}\x{1F6D5}-\x{1F6E5}\x{1F6E9}\x{1F6EB}-\x{1F6F0}\x{1F6F3}-\x{1F6FF}\x{1F7DA}-\x{1F7FF}\x{1F80C}-\x{1F80F}\x{1F848}-\x{1F84F}\x{1F85A}-\x{1F85F}\x{1F888}-\x{1F88F}\x{1F8AE}-\x{1F8AF}\x{1F8BC}-\x{1F8BF}\x{1F8C2}-\x{1F8CF}\x{1F8D9}-\x{1F8FF}\x{1F90C}-\x{1F93A}\x{1F93C}-\x{1F945}\x{1F947}-\x{1F9FF}\x{1FA58}-\x{1FA5F}\x{1FA6E}-\x{1FAFF}\x{1FC00}-\x{1FFFD}]';

	/**
	 * Top-level domains in the root zone, as the service lists them (IANA
	 * list, version 2026100300). "Phase 2.Bonus" is not a website address
	 * because there is no "bonus" zone; "Amazon.com" is.
	 */
	const TLDS = '
		aaa aarp abb abbott abbvie abc able abogado abudhabi ac academy accenture accountant accountants aco
		actor ad ads adult ae aeg aero aetna af afl africa ag agakhan agency ai aig airbus airforce airtel
		akdn al alibaba alipay allfinanz allstate ally alsace alstom am amazon americanexpress
		americanfamily amex amfam amica amsterdam analytics android anquan anz ao aol apartments app apple
		aq aquarelle ar arab aramco archi army arpa art arte as asda asia associates at athleta attorney au
		auction audi audible audio auspost author auto autos aw aws ax axa az azure ba baby baidu banamex
		band bank bar barcelona barclaycard barclays barefoot bargains baseball basketball bauhaus bayern bb
		bbc bbt bbva bcg bcn bd be beats beauty beer berlin best bestbuy bet bf bg bh bharti bi bible bid
		bike bing bingo bio biz bj black blackfriday blockbuster blog bloomberg blue bm bms bmw bn
		bnpparibas bo boats boehringer bofa bom bond boo book booking bosch bostik boston bot boutique box
		br bradesco bridgestone broadway broker brother brussels bs bt build builders business buy buzz bv
		bw by bz bzh ca cab cafe cal call calvinklein cam camera camp canon capetown capital capitalone car
		caravan cards care career careers cars casa case cash casino cat catering catholic cba cbn cbre cc
		cd center ceo cern cf cfa cfd cg ch chanel channel charity chase chat cheap chintai christmas chrome
		church ci cipriani circle cisco citadel citi citic city ck cl claims cleaning click clinic clinique
		clothing cloud club clubmed cm cn co coach codes coffee college cologne com commbank community
		company compare computer comsec condos construction consulting contact contractors cooking cool coop
		corsica country coupon coupons courses cpa cr credit creditcard creditunion cricket crown crs cruise
		cruises cu cuisinella cv cw cx cy cymru cyou cz dad dance data date dating datsun day dclk dds de
		deal dealer deals degree delivery dell deloitte delta democrat dental dentist desi design dev dhl
		diamonds diet digital direct directory discount discover dish diy dj dk dm dnp do docs doctor dog
		domains dot download drive dtv dubai dupont durban dvag dvr dz earth eat ec eco edeka edu education
		ee eg email emerck energy engineer engineering enterprises epson equipment er ericsson erni es esq
		estate et eu eurovision eus events exchange expert exposed express extraspace fage fail fairwinds
		faith family fan fans farm farmers fashion fast fedex feedback ferrari ferrero fi fidelity fido film
		final finance financial fire firestone firmdale fish fishing fit fitness fj fk flickr flights flir
		florist flowers fly fm fo foo food football ford forex forsale forum foundation fox fr free
		fresenius frl frogans frontier ftr fujitsu fun fund furniture futbol fyi ga gal gallery gallo gallup
		game games gap garden gay gb gbiz gd gdn ge gea gent genting george gf gg ggee gh gi gift gifts
		gives giving gl glass gle global globo gm gmail gmbh gmo gmx gn godaddy gold goldpoint golf goodyear
		goog google gop got gov gp gq gr grainger graphics gratis green gripe grocery group gs gt gu gucci
		guge guide guitars guru gw gy hair hamburg hangout haus hbo hdfc hdfcbank health healthcare help
		helsinki here hermes hiphop hisamitsu hitachi hiv hk hkt hm hn hockey holdings holiday homedepot
		homegoods homes homesense honda horse hospital host hosting hot hotels hotmail house how hr hsbc ht
		hu hughes hyatt hyundai ibm icbc ice icu id ie ieee ifm ikano il im imamat imdb immo immobilien in
		inc industries infiniti info ing ink institute insurance insure int international intuit investments
		io ipiranga iq ir irish is ismaili ist istanbul it itau itv jaguar java jcb je jeep jetzt jewelry
		jio jll jm jmp jnj jo jobs joburg jot joy jp jpmorgan jprs juegos kaufen kddi ke kerryhotels
		kerryproperties kfh kg kh ki kia kids kim kindle kitchen kiwi km kn koeln komatsu kosher kp kpmg kpn
		kr krd kred kuokgroup kw ky kyoto kz la lacaixa lamborghini lamer land landrover lanxess lasalle lat
		latino latrobe law lawyer lb lc lds lease leclerc lefrak legal lego lexus lgbt li lidl life
		lifeinsurance lifestyle lighting like lilly limited limo lincoln link live living lk llc llp loan
		loans locker locus lol london lotte lotto love lpl lplfinancial lr ls lt ltd ltda lu lundbeck luxe
		luxury lv ly ma madrid maif maison makeup man management mango map market marketing markets marriott
		marshalls mattel mba mc mckinsey md me med media meet melbourne meme memorial men menu merck
		merckmsd mg mh miami microsoft mil mini mint mit mitsubishi mk ml mlb mls mm mma mn mo mobi mobile
		moda moe moi mom monash money monster mormon mortgage moscow moto motorcycles mov movie mp mq mr ms
		msd mt mtn mtr mu museum music mv mw mx my mz na nab nagoya name navy nba nc ne nec net netbank
		netflix network neustar new news next nextdirect nexus nf nfl ng ngo nhk ni nico nike nikon ninja
		nissan nissay nl no nokia norton now nowruz nowtv np nr nra nrw ntt nu nyc nz obi observer office
		okinawa olayan olayangroup ollo om omega one ong onl online ooo open oracle orange org organic
		origins osaka otsuka ott ovh pa page panasonic paris pars partners parts party pay pccw pe pet pf
		pfizer pg ph pharmacy phd philips phone photo photography photos physio pics pictet pictures pid pin
		ping pink pioneer pizza pk pl place play playstation plumbing plus pm pn pnc pohl poker politie porn
		post pr praxi press prime pro prod productions prof progressive promo properties property protection
		pru prudential ps pt pub pw pwc py qa qpon quebec quest racing radio re read realestate realtor
		realty recipes red redumbrella rehab reise reisen reit reliance ren rent rentals repair report
		republican rest restaurant review reviews rexroth rich richardli ricoh ril rio rip ro rocks rodeo
		rogers room rs rsvp ru rugby ruhr run rw rwe ryukyu sa saarland safe safety sakura sale salon
		samsclub samsung sandvik sandvikcoromant sanofi sap sarl sas save saxo sb sbi sbs sc scb schaeffler
		schmidt scholarships school schule schwarz science scot sd se search seat secure security seek
		select sener services seven sew sex sexy sfr sg sh shangrila sharp shell shia shiksha shoes shop
		shopping shouji show si silk sina singles site sj sk ski skin sky skype sl sling sm smart smile sn
		sncf so soccer social softbank software sohu solar solutions song sony soy spa space sport spot sr
		srl ss st stada staples star statebank statefarm stc stcgroup stockholm storage store stream studio
		study style su sucks supplies supply support surf surgery suzuki sv swatch swiss sx sy sydney
		systems sz tab taipei talk taobao target tatamotors tatar tattoo tax taxi tc tci td tdk team tech
		technology tel temasek tennis teva tf tg th thd theater theatre tiaa tickets tienda tips tires tirol
		tj tjmaxx tjx tk tkmaxx tl tm tmall tn to today tokyo tools top toray toshiba total tours town
		toyota toys tr trade trading training travel travelers travelersinsurance trust trv tt tube tui
		tunes tushu tv tvs tw tz ua ubank ubs ug uk unicom university uno uol ups us uy uz va vacations vana
		vanguard vc ve vegas ventures verisign versicherung vet vg vi viajes video vig viking villas vin vip
		virgin visa vision viva vivo vlaanderen vn vodka volvo vote voting voto voyage vu wales walmart
		walter wang wanggou watch watches weather weatherchannel web webcam weber website wed wedding weibo
		weir wf whoswho wien wiki williamhill win windows wine winners wme woodside work works world wow ws
		wtc wtf xbox xerox xihuan xin xn--11b4c3d xn--1ck2e1b xn--1qqw23a xn--2scrj9c xn--30rr7y xn--3bst00m
		xn--3ds443g xn--3e0b707e xn--3hcrj9c xn--3pxu8k xn--42c2d9a xn--45br5cyl xn--45brj9c xn--45q11c
		xn--4dbrk0ce xn--4gbrim xn--54b7fta0cc xn--55qw42g xn--55qx5d xn--5su34j936bgsg xn--5tzm5g
		xn--6frz82g xn--6qq986b3xl xn--80adxhks xn--80ao21a xn--80aqecdr1a xn--80asehdb xn--80aswg
		xn--8y0a063a xn--90a3ac xn--90ae xn--90ais xn--9dbq2a xn--9et52u xn--9krt00a xn--b4w605ferd
		xn--bck1b9a5dre4c xn--c1avg xn--c2br7g xn--cck2b3b xn--cckwcxetd xn--cg4bki xn--clchc0ea0b2g2a9gcd
		xn--czr694b xn--czrs0t xn--czru2d xn--d1acj3b xn--d1alf xn--e1a4c xn--eckvdtc9d xn--efvy88h
		xn--fct429k xn--fhbei xn--fiq228c5hs xn--fiq64b xn--fiqs8s xn--fiqz9s xn--fjq720a xn--flw351e
		xn--fpcrj9c3d xn--fzc2c9e2c xn--fzys8d69uvgm xn--g2xx48c xn--gckr3f0f xn--gecrj9c xn--gk3at1e
		xn--h2breg3eve xn--h2brj9c xn--h2brj9c8c xn--hxt814e xn--i1b6b1a6a2e xn--imr513n xn--io0a7i
		xn--j1aef xn--j1amh xn--j6w193g xn--jlq480n2rg xn--jvr189m xn--kcrx77d1x4a xn--kprw13d xn--kpry57d
		xn--kput3i xn--l1acc xn--lgbbat1ad8j xn--mgb9awbf xn--mgba3a3ejt xn--mgba3a4f16a xn--mgba7c0bbn0a
		xn--mgbaam7a8h xn--mgbab2bd xn--mgbah1a3hjkrd xn--mgbai9azgqp6j xn--mgbayh7gpa xn--mgbbh1a
		xn--mgbbh1a71e xn--mgbc0a9azcg xn--mgbca7dzdo xn--mgbcpq6gpa1a xn--mgberp4a5d4ar xn--mgbgu82a
		xn--mgbi4ecexp xn--mgbpl2fh xn--mgbt3dhd xn--mgbtx2b xn--mgbx4cd0ab xn--mix891f xn--mk1bu44c
		xn--mxtq1m xn--ngbc5azd xn--ngbe9e0a xn--ngbrx xn--node xn--nqv7f xn--nqv7fs00ema xn--nyqy26a
		xn--o3cw4h xn--ogbpf8fl xn--otu796d xn--p1acf xn--p1ai xn--pgbs0dh xn--pssy2u xn--q7ce6a xn--q9jyb4c
		xn--qcka1pmc xn--qxa6a xn--qxam xn--rhqv96g xn--rovu88b xn--rvc1e0am3e xn--s9brj9c xn--ses554g
		xn--t60b56a xn--tckwe xn--tiq49xqyj xn--unup4y xn--vermgensberater-ctb xn--vermgensberatung-pwb
		xn--vhquv xn--vuq861b xn--w4r85el8fhu5dnra xn--w4rs40l xn--wgbh1c xn--wgbl6a xn--xhq521b
		xn--xkc2al3hye2a xn--xkc2dl3a5ee0h xn--y9a3aq xn--yfro4i67o xn--ygbi2ammx xn--zfr164b xxx xyz yachts
		yahoo yamaxun yandex ye yodobashi yoga yokohama you youtube yt yun za zappos zara zero zip zm zone
		zuerich zw
	';

	/**
	 * The same zones in their Unicode spelling ("xn--p1ai" is also written as
	 * its Cyrillic name), escaped so that the file itself stays ASCII.
	 */
	const TLDS_UNICODE = "
		\u{76}\u{65}\u{72}\u{6D}\u{F6}\u{67}\u{65}\u{6E}\u{73}\u{62}\u{65}\u{72}\u{61}\u{74}\u{65}\u{72}
		\u{76}\u{65}\u{72}\u{6D}\u{F6}\u{67}\u{65}\u{6E}\u{73}\u{62}\u{65}\u{72}\u{61}\u{74}\u{75}\u{6E}\u{67}
		\u{3B5}\u{3BB} \u{3B5}\u{3C5} \u{431}\u{433} \u{431}\u{435}\u{43B} \u{434}\u{435}\u{442}\u{438}
		\u{435}\u{44E} \u{43A}\u{430}\u{442}\u{43E}\u{43B}\u{438}\u{43A} \u{43A}\u{43E}\u{43C}
		\u{43C}\u{43A}\u{434} \u{43C}\u{43E}\u{43D} \u{43C}\u{43E}\u{441}\u{43A}\u{432}\u{430}
		\u{43E}\u{43D}\u{43B}\u{430}\u{439}\u{43D} \u{43E}\u{440}\u{433} \u{440}\u{443}\u{441}
		\u{440}\u{444} \u{441}\u{430}\u{439}\u{442} \u{441}\u{440}\u{431} \u{443}\u{43A}\u{440}
		\u{49B}\u{430}\u{437} \u{570}\u{561}\u{575} \u{5D9}\u{5E9}\u{5E8}\u{5D0}\u{5DC}
		\u{5E7}\u{5D5}\u{5DD} \u{627}\u{628}\u{648}\u{638}\u{628}\u{64A}
		\u{627}\u{631}\u{627}\u{645}\u{643}\u{648} \u{627}\u{644}\u{627}\u{631}\u{62F}\u{646}
		\u{627}\u{644}\u{628}\u{62D}\u{631}\u{64A}\u{646} \u{627}\u{644}\u{62C}\u{632}\u{627}\u{626}\u{631}
		\u{627}\u{644}\u{633}\u{639}\u{648}\u{62F}\u{64A}\u{629}
		\u{627}\u{644}\u{639}\u{644}\u{64A}\u{627}\u{646} \u{627}\u{644}\u{645}\u{63A}\u{631}\u{628}
		\u{627}\u{645}\u{627}\u{631}\u{627}\u{62A} \u{627}\u{6CC}\u{631}\u{627}\u{646}
		\u{628}\u{627}\u{631}\u{62A} \u{628}\u{627}\u{632}\u{627}\u{631} \u{628}\u{64A}\u{62A}\u{643}
		\u{628}\u{6BE}\u{627}\u{631}\u{62A} \u{62A}\u{648}\u{646}\u{633} \u{633}\u{648}\u{62F}\u{627}\u{646}
		\u{633}\u{648}\u{631}\u{64A}\u{629} \u{634}\u{628}\u{643}\u{629} \u{639}\u{631}\u{627}\u{642}
		\u{639}\u{631}\u{628} \u{639}\u{645}\u{627}\u{646} \u{641}\u{644}\u{633}\u{637}\u{64A}\u{646}
		\u{642}\u{637}\u{631} \u{643}\u{627}\u{62B}\u{648}\u{644}\u{64A}\u{643} \u{643}\u{648}\u{645}
		\u{645}\u{635}\u{631} \u{645}\u{644}\u{64A}\u{633}\u{64A}\u{627}
		\u{645}\u{648}\u{631}\u{64A}\u{62A}\u{627}\u{646}\u{64A}\u{627} \u{645}\u{648}\u{642}\u{639}
		\u{647}\u{645}\u{631}\u{627}\u{647} \u{67E}\u{627}\u{6A9}\u{633}\u{62A}\u{627}\u{646}
		\u{680}\u{627}\u{631}\u{62A} \u{915}\u{949}\u{92E} \u{928}\u{947}\u{91F}
		\u{92D}\u{93E}\u{930}\u{924} \u{92D}\u{93E}\u{930}\u{924}\u{92E}\u{94D}
		\u{92D}\u{93E}\u{930}\u{94B}\u{924} \u{938}\u{902}\u{917}\u{920}\u{928}
		\u{9AC}\u{9BE}\u{982}\u{9B2}\u{9BE} \u{9AD}\u{9BE}\u{9B0}\u{9A4} \u{9AD}\u{9BE}\u{9F0}\u{9A4}
		\u{A2D}\u{A3E}\u{A30}\u{A24} \u{AAD}\u{ABE}\u{AB0}\u{AA4} \u{B2D}\u{B3E}\u{B30}\u{B24}
		\u{B87}\u{BA8}\u{BCD}\u{BA4}\u{BBF}\u{BAF}\u{BBE} \u{B87}\u{BB2}\u{B99}\u{BCD}\u{B95}\u{BC8}
		\u{B9A}\u{BBF}\u{B99}\u{BCD}\u{B95}\u{BAA}\u{BCD}\u{BAA}\u{BC2}\u{BB0}\u{BCD}
		\u{C2D}\u{C3E}\u{C30}\u{C24}\u{C4D} \u{CAD}\u{CBE}\u{CB0}\u{CA4} \u{D2D}\u{D3E}\u{D30}\u{D24}\u{D02}
		\u{DBD}\u{D82}\u{D9A}\u{DCF} \u{E04}\u{E2D}\u{E21} \u{E44}\u{E17}\u{E22} \u{EA5}\u{EB2}\u{EA7}
		\u{10D2}\u{10D4} \u{307F}\u{3093}\u{306A} \u{30A2}\u{30DE}\u{30BE}\u{30F3}
		\u{30AF}\u{30E9}\u{30A6}\u{30C9} \u{30B0}\u{30FC}\u{30B0}\u{30EB} \u{30B3}\u{30E0}
		\u{30B9}\u{30C8}\u{30A2} \u{30BB}\u{30FC}\u{30EB} \u{30D5}\u{30A1}\u{30C3}\u{30B7}\u{30E7}\u{30F3}
		\u{30DD}\u{30A4}\u{30F3}\u{30C8} \u{4E16}\u{754C} \u{4E2D}\u{4FE1} \u{4E2D}\u{56FD} \u{4E2D}\u{570B}
		\u{4E2D}\u{6587}\u{7F51} \u{4E9A}\u{9A6C}\u{900A} \u{4F01}\u{4E1A} \u{4F5B}\u{5C71} \u{4FE1}\u{606F}
		\u{5065}\u{5EB7} \u{516B}\u{5366} \u{516C}\u{53F8} \u{516C}\u{76CA} \u{53F0}\u{6E7E}
		\u{53F0}\u{7063} \u{5546}\u{57CE} \u{5546}\u{5E97} \u{5546}\u{6807} \u{5609}\u{91CC}
		\u{5609}\u{91CC}\u{5927}\u{9152}\u{5E97} \u{5728}\u{7EBF} \u{5927}\u{62FF} \u{5929}\u{4E3B}\u{6559}
		\u{5A31}\u{4E50} \u{5BB6}\u{96FB} \u{5E7F}\u{4E1C} \u{5FAE}\u{535A} \u{6148}\u{5584}
		\u{6211}\u{7231}\u{4F60} \u{624B}\u{673A} \u{62DB}\u{8058} \u{653F}\u{52A1} \u{653F}\u{5E9C}
		\u{65B0}\u{52A0}\u{5761} \u{65B0}\u{95FB} \u{65F6}\u{5C1A} \u{66F8}\u{7C4D} \u{673A}\u{6784}
		\u{6DE1}\u{9A6C}\u{9521} \u{6E38}\u{620F} \u{6FB3}\u{9580} \u{70B9}\u{770B} \u{79FB}\u{52A8}
		\u{7EC4}\u{7EC7}\u{673A}\u{6784} \u{7F51}\u{5740} \u{7F51}\u{5E97} \u{7F51}\u{7AD9} \u{7F51}\u{7EDC}
		\u{8054}\u{901A} \u{8C37}\u{6B4C} \u{8D2D}\u{7269} \u{901A}\u{8CA9} \u{96C6}\u{56E2}
		\u{96FB}\u{8A0A}\u{76C8}\u{79D1} \u{98DE}\u{5229}\u{6D66} \u{98DF}\u{54C1} \u{9910}\u{5385}
		\u{9999}\u{683C}\u{91CC}\u{62C9} \u{9999}\u{6E2F} \u{B2F7}\u{B137} \u{B2F7}\u{CEF4} \u{C0BC}\u{C131}
		\u{D55C}\u{AD6D}
	";

	/**
	 * The set above, built once.
	 *
	 * @var array<string,int>|null
	 */
	private static $tld_set = null;

	/**
	 * The description for an order: the first item names, cleaned, or the
	 * fallback when nothing usable is left.
	 *
	 * @param string[] $names    Item names, in order (the caller sends at most three).
	 * @param bool     $more     Whether the order has more items than $names.
	 * @param string   $fallback "Order #N", already translated.
	 * @param int      $order_id Order id, for the untranslated last resort.
	 * @param bool     $nfkc     Read text as the service does (null: when the intl extension is there).
	 * @return string
	 */
	public static function for_order( array $names, $more, $fallback, $order_id, $nfkc = null ) {
		$clean = array();
		foreach ( $names as $name ) {
			$one = self::clean( (string) $name, $nfkc );
			if ( '' !== $one ) {
				$clean[] = $one;
			}
		}
		$line = '';
		if ( $clean ) {
			$line = implode( ', ', $clean ) . ( $more ? ' …' : '' );
			/* Cleaned again as a whole, and again after the cut: joining two
			   clean names, or cutting "x.comet" to "x.com", can make what
			   neither had. NFKC can lengthen a line ("ﬃ" is three letters),
			   so the cut is repeated until it holds. The joined line is text
			   already — its entities were decoded once, name by name — so it
			   goes through the text part only: decoding it again would turn
			   a literal "&amp;" in a name into "&". */
			for ( $i = 0; $i < 3; $i++ ) {
				$line = self::text( self::fit( self::text( $line, $nfkc ) ), $nfkc );
				if ( self::units( $line ) <= self::MAX_UNITS ) {
					break;
				}
			}
			if ( self::units( $line ) > self::MAX_UNITS || '' !== self::problem( $line, $nfkc ) ) {
				$line = '';
			}
		}
		if ( '' !== $line ) {
			return $line;
		}
		return self::fallback( $fallback, $order_id, $nfkc );
	}

	/**
	 * "Order #N" — checked too, because a translation is text from outside.
	 *
	 * @param string $fallback Translated "Order #N".
	 * @param int    $order_id Order id.
	 * @param bool   $nfkc     See for_order().
	 * @return string
	 */
	public static function fallback( $fallback, $order_id, $nfkc = null ) {
		$fallback = trim( (string) $fallback );
		if ( '' !== $fallback && self::units( $fallback ) <= self::MAX_UNITS && '' === self::problem( $fallback, $nfkc ) ) {
			return $fallback;
		}
		return 'Order #' . (int) $order_id;
	}

	/**
	 * One piece of text, cleaned to what the service accepts. Returns '' when
	 * nothing is left or the text cannot be read with certainty.
	 *
	 * @param string    $raw  Text as the store has it.
	 * @param bool|null $nfkc See for_order().
	 * @return string
	 */
	public static function clean( $raw, $nfkc = null ) {
		$s = (string) $raw;
		if ( '' === $s ) {
			return '';
		}
		if ( ! preg_match( '//u', $s ) ) {
			/* Not UTF-8: no reading of it can be trusted. */
			return '';
		}
		/* A product name can arrive with tags (from a page builder) and with
		   entities ("Salt &amp; Pepper"). The payment page prints text, so the
		   buyer would read "<strong>" and "&amp;" literally. Read it the way
		   the store's page shows it: tags first, on the stored value, then the
		   entities, once — "&lt;M&gt;" is the text "<M>" in the store, not a
		   tag, and stays "‹M›" here. */
		$s = preg_replace( '@<(script|style)\b[^>]*>.*?</\1\s*>@is', ' ', $s );
		$s = null === $s ? null : preg_replace( '@<!--.*?-->|</?[a-z][^<>]*>@is', ' ', $s );
		if ( null === $s ) {
			return '';
		}
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::text( $s, $nfkc );
	}

	/**
	 * Text (no HTML any more) cleaned to what the service accepts.
	 *
	 * @param string    $s    Text.
	 * @param bool|null $nfkc See for_order().
	 * @return string
	 */
	private static function text( $s, $nfkc ) {
		/* Line breaks and other controls read as a space; invisible
		   characters go, except a joiner or a variation selector that is part
		   of an emoji. */
		$s = preg_replace( '/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', (string) $s );
		$s = null === $s ? null : preg_replace_callback(
			'/(' . self::joiners() . ')|' . self::HIDDEN . '/u',
			static function ( $m ) {
				return isset( $m[1] ) ? $m[1] : '';
			},
			$s
		);
		if ( null === $s ) {
			return '';
		}
		/* A lone < or > is a size or a comparison ("Cable <10 cm"), not
		   markup; the angle quotes keep the meaning without being markup. The
		   backtick is nearly always a misplaced apostrophe. Their full-width
		   and small forms too: the service reads them as the same signs. */
		$s = strtr(
			$s,
			array(
				'<'        => "\u{2039}",
				"\u{FF1C}" => "\u{2039}",
				"\u{FE64}" => "\u{2039}",
				'>'        => "\u{203A}",
				"\u{FF1E}" => "\u{203A}",
				"\u{FE65}" => "\u{203A}",
				'`'        => "'",
				"\u{FF40}" => "'",
				"\u{1FEF}" => "'",
			)
		);
		$s = self::squash( $s );
		if ( '' === $s ) {
			return '';
		}
		$n = self::read( $s, $nfkc );
		if ( null === $n ) {
			return '';
		}
		$fixed = self::defuse( $n );
		if ( null === $fixed ) {
			return '';
		}
		/* Nothing to change: send the text as the store wrote it ("Brand™"
		   stays "Brand™" rather than the "BrandTM" the service reads). */
		if ( $fixed === $n ) {
			return $s;
		}
		/* Something has to change. Make the same change in the text as the
		   store wrote it, and send that when the service's reading of it is
		   clean ("Brand™ Amazon com card", not "BrandTM Amazon com card");
		   the form read the service's way stays for what only shows in it
		   ("ｃｈｅｒｕｍ．ｉｏ"). */
		$own = self::defuse( $s );
		if ( null !== $own && '' !== $own && '' === self::problem( $own, $nfkc ) ) {
			return $own;
		}
		return $fixed;
	}

	/**
	 * The joiners and variation selectors the service allows: a zero-width
	 * joiner between two emoji (after a selector or a skin tone too), a
	 * variation selector right after an emoji, U+FE0F in a keycap ("1️⃣").
	 * The service's own exceptions, except the ones for a selector after an
	 * ideograph or a Mongolian letter (those are removed here instead).
	 *
	 * @return string Pattern, without delimiters.
	 */
	private static function joiners() {
		return '(?<=' . self::EMOJI . ')[\x{FE0E}\x{FE0F}]'
			. '|(?:(?<=' . self::EMOJI . ')|(?<=' . self::EMOJI . '[\x{FE0F}\x{1F3FB}-\x{1F3FF}]))\x{200D}(?=' . self::EMOJI . ')'
			. '|(?<=[#*0-9])\x{FE0F}(?=\x{20E3})';
	}

	/**
	 * The text without the joiners the service allows — what its check of
	 * hidden characters looks at.
	 *
	 * @param string $s Text.
	 * @return string|null
	 */
	private static function unjoin( $s ) {
		return preg_replace( '/' . self::joiners() . '/u', '', $s );
	}

	/**
	 * What the service would refuse in this text, or '' for nothing: the
	 * service's own checks, in its order. Stricter than the service in two
	 * respects — a variation selector after an ideograph or a Mongolian letter
	 * counts as hidden here, and a character too new for PHP's tables is tried
	 * as a letter too (see domain_dots()); softer in one — a code point the
	 * service calls unassigned passes here (see HIDDEN).
	 *
	 * @param string    $s    Text.
	 * @param bool|null $nfkc See for_order().
	 * @return string 'invisible', 'markup', 'link', 'domain', 'unreadable' or ''.
	 */
	public static function problem( $s, $nfkc = null ) {
		$s = (string) $s;
		if ( ! preg_match( '//u', $s ) ) {
			return 'unreadable';
		}
		$x      = self::unjoin( str_replace( array( "\t", "\n", "\r" ), ' ', $s ) );
		$hidden = null === $x ? false : preg_match( '/' . self::HIDDEN . '/u', $x );
		if ( false === $hidden ) {
			return 'unreadable';
		}
		if ( $hidden ) {
			return 'invisible';
		}
		$n = self::read( $s, $nfkc );
		if ( null === $n ) {
			return 'unreadable';
		}
		if ( '' === $n ) {
			return '';
		}
		$x = self::unjoin( $n );
		if ( null === $x ) {
			return 'unreadable';
		}
		if ( preg_match( '/' . self::HIDDEN . '/u', $x ) ) {
			return 'invisible';
		}
		if ( preg_match( '/[<>`]/', $n ) ) {
			return 'markup';
		}
		/* The service looks for links and domains in the text as it is and in
		   a copy without the joiners it allowed, with look-alike dots made
		   real. */
		$d = self::twins( $x );
		if ( null === $d ) {
			return 'unreadable';
		}
		foreach ( array( $n, $d ) as $form ) {
			$link = self::link_span( $form );
			if ( false === $link ) {
				return 'unreadable';
			}
			if ( null !== $link ) {
				return 'link';
			}
		}
		foreach ( array( $n, $d ) as $form ) {
			$dots = self::domain_dots( $form );
			if ( false === $dots ) {
				return 'unreadable';
			}
			if ( $dots ) {
				return 'domain';
			}
		}
		return '';
	}

	/**
	 * The text as the service reads it: NFKC, spaces collapsed.
	 *
	 * Without the intl extension there is no NFKC in PHP; the full-width forms
	 * and the few compatibility characters that turn into a dot are folded by
	 * hand. Anything rarer that the service still refuses is caught by the
	 * retry with "Order #N" in the gateway.
	 *
	 * @param string    $s    Text.
	 * @param bool|null $nfkc See for_order().
	 * @return string|null Null when the text cannot be read.
	 */
	private static function read( $s, $nfkc ) {
		if ( null === $nfkc ) {
			$nfkc = class_exists( 'Normalizer' );
		}
		if ( $nfkc ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_KC );
			if ( ! is_string( $n ) ) {
				return null;
			}
		} else {
			$n = self::fold( $s );
			if ( null === $n ) {
				return null;
			}
		}
		/* A variation selector whose emoji NFKC took apart ("™️" reads
		   "TM" and the selector) is dropped, as the service drops it. */
		$n = preg_replace( '/(?<!' . self::EMOJI . ')(?<![#*0-9])[\x{FE0E}\x{FE0F}]|(?<=[#*0-9])[\x{FE0E}\x{FE0F}](?!\x{20E3})/u', '', $n );
		return null === $n ? null : self::squash( $n );
	}

	/**
	 * A hand-made NFKC for the characters that matter to the rule.
	 *
	 * @param string $s Text.
	 * @return string|null
	 */
	private static function fold( $s ) {
		$s = preg_replace_callback(
			'/[\x{FF01}-\x{FF5E}\x{24B6}-\x{24E9}\x{2488}-\x{249B}]/u',
			static function ( $m ) {
				$c = self::ord( $m[0] );
				if ( $c >= 0xFF01 && $c <= 0xFF5E ) {
					return chr( $c - 0xFEE0 );
				}
				if ( $c >= 0x24B6 && $c <= 0x24CF ) {
					return chr( 0x41 + $c - 0x24B6 );
				}
				if ( $c >= 0x24D0 && $c <= 0x24E9 ) {
					return chr( 0x61 + $c - 0x24D0 );
				}
				return ( $c - 0x2487 ) . '.';
			},
			$s
		);
		if ( null === $s ) {
			return null;
		}
		return strtr(
			$s,
			array(
				"\u{3000}"  => ' ',
				"\u{00A0}"  => ' ',
				"\u{02D9}"  => " \u{0307}",
				"\u{02DA}"  => " \u{030A}",
				"\u{0387}"  => "\u{00B7}",
				"\u{013F}"  => "L\u{00B7}",
				"\u{0140}"  => "l\u{00B7}",
				"\u{FE12}"  => "\u{3002}",
				"\u{FF65}"  => "\u{30FB}",
				"\u{318D}"  => "\u{119E}",
				"\u{33C7}"  => 'Co.',
				"\u{2122}"  => 'TM',
				"\u{2120}"  => 'SM',
				"\u{2121}"  => 'TEL',
				"\u{2116}"  => 'No',
				"\u{2139}"  => 'i',
				"\u{2024}"  => '.',
				"\u{2025}"  => '..',
				"\u{2026}"  => '...',
				"\u{FE52}"  => '.',
				"\u{FF61}"  => "\u{3002}",
				"\u{33C2}"  => 'a.m.',
				"\u{33D8}"  => 'p.m.',
				"\u{1F100}" => '0.',
				"\u{017F}"  => 's',
				"\u{FB00}"  => 'ff',
				"\u{FB01}"  => 'fi',
				"\u{FB02}"  => 'fl',
				"\u{FB03}"  => 'ffi',
				"\u{FB04}"  => 'ffl',
				"\u{FB05}"  => 'st',
				"\u{FB06}"  => 'st',
			)
		);
	}

	/**
	 * Take out links and break website addresses in text already read the
	 * service's way. Returns the text unchanged when there is nothing to do,
	 * null when it does not settle.
	 *
	 * @param string $n Text after read().
	 * @return string|null
	 */
	private static function defuse( $n ) {
		$had_link = false;
		for ( $i = 0; $i < 40; $i++ ) {
			$n = self::squash( $n );
			if ( '' === $n ) {
				return '';
			}
			/* The look-alike dots become real ones in a SECOND copy of the
			   text, of the same length; edits found in it are made in the text
			   itself, so a Japanese "・" that is not a domain dot stays. */
			$d = self::twins( $n );
			if ( null === $d ) {
				return null;
			}
			$edit    = null;
			$is_link = false;
			foreach ( array( $n, $d ) as $form ) {
				$span = self::link_span( $form );
				if ( false === $span ) {
					return null;
				}
				if ( null !== $span ) {
					$edit    = array( $span );
					$is_link = true;
					break;
				}
			}
			if ( null === $edit ) {
				foreach ( array( $n, $d ) as $form ) {
					$dots = self::domain_dots( $form );
					if ( false === $dots ) {
						return null;
					}
					if ( $dots ) {
						$edit = $dots;
						break;
					}
				}
			}
			if ( null === $edit ) {
				/* What only led to a link, with the link gone, is no name:
				   "www.shop.com." must not leave a lone ".". */
				if ( $had_link && ! preg_match( '/[\p{L}\p{N}\p{S}]/u', $n ) ) {
					return '';
				}
				return $n;
			}
			$had_link = $had_link || $is_link;
			$n        = self::splice( $n, $edit, $is_link );
		}
		return null;
	}

	/**
	 * Look-alike dots before a Latin letter or digit, made into real dots —
	 * the service's detection form. Every replacement is one character for
	 * one, so positions in the copy are positions in the text.
	 *
	 * @param string $n Text after read().
	 * @return string|null
	 */
	private static function twins( $n ) {
		/* After a letter — or after a character too new for PHP's tables,
		   which may be a letter for the service (see domain_dots()). The last
		   branch (1.3.12) is the service's "cherum-dot-com": the hyphen AFTER a
		   spelt-out dot that stands between two hyphens inside a word reads as
		   a dot ("cherum-dot.com"), and only a real zone to its right makes it
		   an address — "Polka-dot-pattern" stays text. */
		$re = '/(?<=[\p{L}\p{N}\p{Cn}])(?<!' . self::CJK . ')' . self::TWINS . '(?=[a-z0-9])'
			. '|(?<=[a-z0-9])' . self::FOREIGN . '(?=[a-z0-9])'
			. '|(?<=[a-z0-9] )(?:' . self::TWINS . '|' . self::FOREIGN . '|[\x{0307}\x{030A}]|' . self::MIDDOTS . ')(?=[a-z0-9])'
			. '|(?<=[\p{L}\p{N}\p{Cn}]' . self::DOT_HYPHENS . self::DOT_WORD . ')' . self::DOT_HYPHENS . '(?=[\p{L}\p{N}\p{Cn}])/iu';
		return preg_replace( $re, '.', $n );
	}

	/**
	 * The first link ("scheme://…", "www.…"), as a range of characters.
	 *
	 * @param string $s Text.
	 * @return array{0:int,1:int}|null|false False when the pattern failed.
	 */
	private static function link_span( $s ) {
		$found = preg_match( '/(?:^|[^\p{L}\p{N}])(?:[a-z][a-z0-9+.\-]{1,15}:\/\/|www\s*' . self::STOPS . ')/iu', $s, $m, PREG_OFFSET_CAPTURE );
		if ( false === $found ) {
			return false;
		}
		if ( 0 === $found ) {
			return null;
		}
		$start = $m[0][1];
		if ( preg_match( '/^[^\p{L}\p{N}]/u', $m[0][0], $p ) ) {
			$start += strlen( $p[0] );
		}
		/* The link runs from its start ("https://", "www." — "www ." too) to
		   the next space; closing punctuation right after it belongs to the
		   sentence ("(see https://x.example)"). */
		$head = $m[0][1] + strlen( $m[0][0] );
		if ( ! preg_match( '/\G\S*/u', $s, $w, 0, $head ) ) {
			return false;
		}
		$link = substr( $s, $start, $head - $start ) . $w[0];
		$word = preg_replace( '/[)\]}\x{00BB}\x{201D}"\',.;:!?]+$/u', '', $link );
		$end  = max( $head, $start + strlen( null === $word ? $link : $word ) );
		return array( self::cp( $s, $start ), self::cp( $s, $end ) );
	}

	/**
	 * The dots of every real website address, as ranges of characters.
	 * Empty when there is none.
	 *
	 * A character newer than the tables of PHP's PCRE reads there as
	 * unassigned, neither letter nor not; the service, with newer tables,
	 * knows it as one or the other. So the search runs twice — first with
	 * such characters as non-letters ("Shop.com🩷": 🩷 ends the zone), then,
	 * if that finds nothing, as letters (a letter of Unicode 16 right before
	 * ".com" is part of the name) — and the text is clean only when neither
	 * finds an address.
	 *
	 * @param string $s Text.
	 * @return array<int,array{0:int,1:int}>|false False when the pattern failed.
	 */
	private static function domain_dots( $s ) {
		$dots = self::domain_dots_as( $s, '\p{L}\p{N}' );
		if ( false === $dots || $dots ) {
			return $dots;
		}
		return self::domain_dots_as( $s, '\p{L}\p{N}\p{Cn}' );
	}

	/**
	 * domain_dots() with the given characters counted as letters.
	 *
	 * @param string $s       Text.
	 * @param string $letters Class body of what counts as a letter.
	 * @return array<int,array{0:int,1:int}>|false
	 */
	private static function domain_dots_as( $s, $letters ) {
		$label = '[' . $letters . '\-]';
		$re    = '/(?:^|[^' . $letters . '_\-])[' . $letters . '](?:' . $label . '{0,62})?(?:' . self::DOT . ')(?:' . $label . '{1,63}(?:' . self::DOT . '))*(' . $label . '{1,63})(?=\z|[^' . $letters . '_\-])/iu';
		$count = preg_match_all( $re, $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false === $count ) {
			return false;
		}
		$dots = array();
		foreach ( $all as $m ) {
			$labels = preg_split( '/(?:' . self::DOT . ')/iu', $m[0][0] );
			if ( false === $labels ) {
				return false;
			}
			$real = false;
			foreach ( array_slice( $labels, 1 ) as $zone ) {
				if ( self::is_tld( trim( $zone ) ) ) {
					$real = true;
					break;
				}
			}
			if ( ! $real ) {
				continue;
			}
			/* Walk the address label by label and note every dot. */
			$pos   = $m[0][1];
			$end   = $pos + strlen( $m[0][0] );
			$found = count( $dots );
			if ( preg_match( '/\G[^' . $letters . ']/u', $s, $x, 0, $pos ) ) {
				$pos += strlen( $x[0] );
			}
			while ( $pos < $end ) {
				if ( preg_match( '/\G' . $label . '+/u', $s, $x, 0, $pos ) ) {
					$pos += strlen( $x[0] );
					continue;
				}
				if ( preg_match( '/\G(?:' . self::DOT . ')/iu', $s, $x, 0, $pos ) && '' !== $x[0] ) {
					$dots[] = array( self::cp( $s, $pos ), self::cp( $s, $pos + strlen( $x[0] ) ) );
					$pos   += strlen( $x[0] );
					continue;
				}
				if ( ! preg_match( '/\G./su', $s, $x, 0, $pos ) ) {
					return false;
				}
				$pos += strlen( $x[0] );
			}
			if ( count( $dots ) === $found ) {
				/* An address with no dot found in it: the walk and the match disagree. */
				return false;
			}
		}
		return $dots;
	}

	/**
	 * Whether a label is a top-level domain.
	 *
	 * @param string $label Label, already read the service's way.
	 * @return bool
	 */
	private static function is_tld( $label ) {
		if ( null === self::$tld_set ) {
			self::$tld_set = array_flip( preg_split( '/\s+/u', self::TLDS . ' ' . self::TLDS_UNICODE, -1, PREG_SPLIT_NO_EMPTY ) );
		}
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		return '' !== $label && isset( self::$tld_set[ $label ] );
	}

	/**
	 * Take the found ranges out of the text.
	 *
	 * A domain dot becomes a space ("Amazon com"); a spelt-out " dot " keeps
	 * its word and loses the space in front of it instead ("Polka Dot Baby
	 * Blanket" — the service reads "baby" as a zone — becomes "Polka-Dot Baby
	 * Blanket"). A link goes together with what only led to it: brackets
	 * around it alone, and the dash, colon or space before it when nothing
	 * but the end of the sentence follows ("Cap – www.x.com." → "Cap.").
	 *
	 * @param string                        $s       Text.
	 * @param array<int,array{0:int,1:int}> $ranges  Ranges, in characters.
	 * @param bool                          $is_link The range is a link.
	 * @return string
	 */
	private static function splice( $s, array $ranges, $is_link = false ) {
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		usort(
			$ranges,
			static function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $ranges as $r ) {
			$len  = max( 1, $r[1] - $r[0] );
			$with = array( ' ' );
			if ( $is_link ) {
				/* A private-use mark: the text holds none (HIDDEN took them). */
				$with = array( "\u{E000}" );
			} elseif ( preg_match( '/^\s+(' . self::DOT_WORD . ')\s+$/iu', implode( '', array_slice( $chars, $r[0], $len ) ), $w ) ) {
				/* "Polka Dot Baby" → "Polka-Dot Baby". Right after a spelt-out
				   dot that already has its hyphen ("Spot-Dot Dot com"), a second
				   hyphen would make "Spot-Dot-Dot", which reads "Spot-Dot.Dot";
				   that one keeps its space and takes the hyphen after it
				   instead: "Spot-Dot Dot-com". */
				$before = implode( '', array_slice( $chars, max( 0, $r[0] - 5 ), min( 5, $r[0] ) ) );
				if ( preg_match( '/[\p{L}\p{N}\p{Cn}]' . self::DOT_HYPHENS . self::DOT_WORD . '$/iu', $before ) ) {
					$word = ' ' . $w[1] . '-';
				} else {
					$word = ( $r[0] > 0 && '-' === $chars[ $r[0] - 1 ] ? '' : '-' ) . $w[1] . ' ';
				}
				$with = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
			}
			array_splice( $chars, $r[0], $len, $with );
		}
		$out = implode( '', $chars );
		if ( ! $is_link ) {
			return $out;
		}
		$lead = '[\s\-\x{2013}\x{2014}:|,;]*';
		$tidy = preg_replace(
			array(
				'/\(\s*\x{E000}\s*\)|\[\s*\x{E000}\s*\]|\{\s*\x{E000}\s*\}/u',
				'/^\s*\x{E000}[\s\-\x{2013}\x{2014}:|,;.!?]*/u',
				'/' . $lead . '\x{E000}(?=[.,;:!?]*$|[.,;:!?]+\s)/u',
				'/\x{E000}/u',
			),
			array( "\u{E000}", '', '', ' ' ),
			$out
		);
		return null === $tidy ? str_replace( "\u{E000}", ' ', $out ) : $tidy;
	}

	/**
	 * Runs of spaces of any kind to one ASCII space, trimmed.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function squash( $s ) {
		$t = preg_replace( '/[\s\p{Z}]+/u', ' ', (string) $s );
		return trim( null === $t ? (string) $s : $t, ' ' );
	}

	/**
	 * Cut to the service's limit, at a character boundary.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function fit( $s ) {
		if ( self::units( $s ) <= self::MAX_UNITS ) {
			return $s;
		}
		$out   = '';
		$units = 0;
		foreach ( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$w = 4 === strlen( $ch ) ? 2 : 1;
			if ( $units + $w > self::MAX_UNITS ) {
				break;
			}
			$out   .= $ch;
			$units += $w;
		}
		return trim( $out );
	}

	/**
	 * Length in UTF-16 code units: a character outside the Basic Multilingual
	 * Plane (four bytes in UTF-8) counts twice, as in JavaScript.
	 *
	 * @param string $s Text.
	 * @return int
	 */
	public static function units( $s ) {
		$s = (string) $s;
		return (int) preg_match_all( '/[^\x80-\xBF]/', $s ) + (int) preg_match_all( '/[\xF0-\xF7]/', $s );
	}

	/**
	 * Byte offset to character offset.
	 *
	 * @param string $s    Text.
	 * @param int    $byte Byte offset.
	 * @return int
	 */
	private static function cp( $s, $byte ) {
		return (int) preg_match_all( '/[^\x80-\xBF]/', substr( $s, 0, $byte ) );
	}

	/**
	 * Code point of one UTF-8 character.
	 *
	 * @param string $ch Character.
	 * @return int
	 */
	private static function ord( $ch ) {
		$b = array_values( unpack( 'C*', $ch ) );
		switch ( count( $b ) ) {
			case 1:
				return $b[0];
			case 2:
				return ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
			case 3:
				return ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
			default:
				return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
		}
	}
}
