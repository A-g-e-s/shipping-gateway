<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Entity\Values;

enum StatusCode: string
{
    case Code_01 = 'Zásilka byla předána společnosti GLS.';
    case Code_02 = 'Zásilka opustila balíkové centrum.';
    case Code_03 = 'Zásilka dorazila do balíkového centra.';
    case Code_04 = 'Zásilka má být doručena během dne.';
    case Code_05 = 'Zásilka byla doručena.';
    case Code_06 = 'Zásilka je uložena v balíkovém centru.';
    case Code_07 = 'Zásilka je uložena v balíkovém centru. /2';
    case Code_08 = 'Zásilka je uložena v balíkovém centru GLS. Příjemce souhlasil s osobním vyzvednutím.';
    case Code_09 = 'Zásilka je uložena v balíkovém centru pro doručení v novém termínu.';
    case Code_10 = 'Kontrola skenu normální.';
    case Code_11 = 'Zásilku nebylo možné doručit, protože příjemce je na dovolené.';
    case Code_12 = 'Zásilku nebylo možné doručit, protože příjemce nebyl přítomen.';
    case Code_13 = 'Chyba při třídění na depu.';
    case Code_14 = 'Zásilku nebylo možné doručit, protože recepce byla zavřená.';
    case Code_15 = 'Nedoručeno z důvodu nedostatku času.';
    case Code_16 = 'Zásilku nebylo možné doručit, protože příjemce neměl dostupnou hotovost.';
    case Code_17 = 'Zásilku nebylo možné doručit, protože příjemce odmítl převzetí.';
    case Code_18 = 'Zásilku nebylo možné doručit, protože jsou potřeba další adresní údaje.';
    case Code_19 = 'Zásilku nebylo možné doručit kvůli nepříznivým povětrnostním podmínkám.';
    case Code_20 = 'Zásilku nebylo možné doručit kvůli nesprávné nebo neúplné adrese.';
    case Code_21 = 'Předáno kvůli chybě při třídění.';
    case Code_22 = 'Zásilka byla odeslána z depa do třídicího centra.';
    case Code_23 = 'Zásilka byla vrácena odesílateli.';
    case Code_24 = 'Změněná možnost doručení byla uložena v systému GLS a bude provedena podle požadavku.';
    case Code_25 = 'Předáno kvůli chybnému směrování.';
    case Code_26 = 'Zásilka dorazila do balíkového centra. /2';
    case Code_27 = 'Zásilka dorazila do balíkového centra. /3';
    case Code_28 = 'Likvidováno.';
    case Code_29 = 'Zásilka je pod šetřením.';
    case Code_30 = 'Příchozí zásilka je poškozená.';
    case Code_31 = 'Zásilka byla zcela poškozena.';
    case Code_32 = 'Zásilka bude doručena večer.';
    case Code_33 = 'Zásilku nebylo možné doručit kvůli překročení časového rámce.';
    case Code_34 = 'Zásilku nebylo možné doručit, protože převzetí bylo odmítnuto kvůli opožděnému doručení.';
    case Code_35 = 'Zásilka byla odmítnuta, protože zboží nebylo objednáno.';
    case Code_36 = 'Příjemce nebyl doma a nebylo možné zanechat oznámení.';
    case Code_37 = 'Změna doručení na žádost odesílatele.';
    case Code_38 = 'Zásilku nebylo možné doručit kvůli chybějícímu dodacímu listu.';
    case Code_39 = 'Dodací list nebyl podepsán.';
    case Code_40 = 'Zásilka byla vrácena odesílateli. /2';
    case Code_41 = 'Předáno normálně.';
    case Code_42 = 'Zásilka byla zlikvidována na žádost odesílatele.';
    case Code_43 = 'Zásilku nelze lokalizovat.';
    case Code_44 = 'Zásilka je vyloučena z všeobecných obchodních podmínek.';
    case Code_46 = 'Změna doručovací adresy byla dokončena.';
    case Code_47 = 'Zásilka opustila balíkové centrum. /2';
    case Code_51 = 'Data zásilky byla zadána do systému GLS; zásilka zatím nebyla předána GLS.';
    case Code_52 = 'Data dobírky byla zadána do systému GLS.';
    case Code_53 = 'Tranzit na depu.';
    case Code_54 = 'Zásilka byla doručena do balíkomatu.';
    case Code_55 = 'Zásilka byla doručena do ParcelShopu (viz informace o ParcelShopu).';
    case Code_56 = 'Zásilka je uložena v ParcelShopu GLS.';
    case Code_57 = 'Zásilka dosáhla maximální doby uložení v ParcelShopu.';
    case Code_58 = 'Zásilka byla doručena sousedovi (viz podpis).';
    case Code_59 = 'Vyzdvižení v ParcelShopu.';
    case Code_60 = 'Celní odbavení je zpožděno kvůli chybějící faktuře.';
    case Code_61 = 'Připravují se celní dokumenty.';
    case Code_62 = 'Celní odbavení je zpožděno, protože telefonní číslo příjemce není k dispozici.';
    case Code_64 = 'Zásilka byla uvolněna celní správou.';
    case Code_65 = 'Zásilka byla uvolněna celní správou. Celní odbavení provádí příjemce.';
    case Code_66 = 'Celní odbavení je zpožděno, dokud nebude k dispozici souhlas příjemce.';
    case Code_67 = 'Připravují se celní dokumenty. /2';
    case Code_68 = 'Zásilku nebylo možné doručit, protože příjemce odmítl zaplatit poplatky.';
    case Code_69 = 'Zásilka je uložena v balíkovém centru. Nelze ji doručit, protože zásilka není kompletní.';
    case Code_70 = 'Celní odbavení je zpožděno kvůli neúplným dokumentům.';
    case Code_71 = 'Celní odbavení je zpožděno kvůli chybějícím nebo nepřesným celním dokumentům.';
    case Code_72 = 'Je nutné zaznamenat celní údaje.';
    case Code_73 = 'Celní zásilka je zablokována v zemi odesílatele.';
    case Code_74 = 'Celní odbavení je zpožděno kvůli celní kontrole.';
    case Code_75 = 'Zásilka byla zabavena celními orgány.';
    case Code_76 = 'Celní údaje zaznamenány, zásilka může být odeslána do cílové lokace.';
    case Code_80 = 'Zásilka byla přeposlána na požadovanou adresu k doručení.';
    case Code_83 = 'Data zásilky pro službu vyzvednutí byla zadána do systému GLS.';
    case Code_84 = 'Štítek pro vyzvednutí zásilky byl vytvořen.';
    case Code_85 = 'Řidič obdržel pokyn vyzvednout zásilku během dne.';
    case Code_86 = 'Zásilka dorazila do balíkového centra. /4';
    case Code_87 = 'Žádost o vyzvednutí byla zrušena, protože nebylo co vyzvednout.';
    case Code_88 = 'Zásilku nebylo možné vyzvednout, protože zboží nebylo zabaleno.';
    case Code_89 = 'Zásilku nebylo možné vyzvednout, protože zákazník nebyl informován o vyzvednutí.';
    case Code_90 = 'Žádost o vyzvednutí byla zrušena, protože zboží bylo odesláno jiným způsobem.';
    case Code_91 = 'Pick and Ship/Return zrušeno.';
    case Code_92 = 'Zásilka byla doručena. /2';
    case Code_93 = 'Potvrzeno podpisem.';
    case Code_97 = 'Zásilka byla umístěna do balíkového boxu.';
    case Code_99 = 'Příjemce byl kontaktován e-mailem s oznámením o doručení.';

    public static function getStatusCode(StatusCode|string $statusCode): false|self
    {
        if (is_string($statusCode)) {
            $caseName = sprintf('Code_%s', $statusCode);
            foreach (self::cases() as $case) {
                if ($case->name === $caseName) {
                    return $case;
                }
            }
            return false;
        }
        return $statusCode;
    }

    public static function isDelivered(StatusCode $statusCode): bool
    {
        return $statusCode === StatusCode::Code_05
            || $statusCode === StatusCode::Code_58
            || $statusCode === StatusCode::Code_92;
    }

    public static function isDamaged(StatusCode $statusCode): bool
    {
        return $statusCode === StatusCode::Code_30
            || $statusCode === StatusCode::Code_31
            || $statusCode === StatusCode::Code_92;
    }
}
