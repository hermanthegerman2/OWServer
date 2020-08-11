<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/UTF8Helper.php';  // diverse Klassen

trait OWSPLITProfile
{
    /**
     * Erzeugt alle benötigten Profile.
     */
    private function CreateProfile()
    {
        $this->RegisterProfileIntegerEx('OWSPLIT.Scanner', 'Gear', '', '', [
            [0, $this->Translate('standby'), '', -1],
            [1, $this->Translate('abort'), '', -1],
            [2, $this->Translate('scan'), '', -1],
            [3, $this->Translate('only playlists'), '', -1],
            [4, $this->Translate('completely'), '', -1]
        ]);
        $this->RegisterProfileInteger('OWSPLIT.ServerSelect.' . $this->InstanceID, 'Speaker', '', '', 0, 0, 0);
    }
}
/**
 * Definiert eine Datensatz zum Versenden an des OWSPLIT.
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2016 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       1.0
 *
 * @example <b>Ohne</b>
 */
class OWSPLITData extends stdClass
{
    use UTF8Coder;

    /**
     * Adresse des Gerätes.
     *
     * @var string
     */
    public $Address;

    /**
     * Alle Kommandos als Array.
     *
     * @var string|array
     */
    public $Command;

    /**
     * Alle Daten des Kommandos.
     *
     * @var string|array
     */
    public $Data;

    /**
     * Flag ob auf Antwort gewartet werden muss.
     *
     * @var bool
     */
    public $needResponse;

    /**
     * Anzahl der versendeten Daten.
     *
     * @var int
     */
    private $SendValues;

    /**
     * Erzeugt ein Objekt vom Typ OWSPLITData.
     *
     * @param string|array $Command Kommando
     * @param string|array $Data Nutzdaten
     * @param bool $needResponse Auf Antwort warten.
     */
    public function __construct($Command = '', $Data = '', $needResponse = true)
    {
        $this->Address = '';
        if (is_array($Command)) {
            $this->Command = $Command;
        } else {
            $this->Command = [$Command];
        }
        if (is_array($Data)) {
            $this->Data = array_map('rawurlencode', $Data);
        } else {
            $this->Data = rawurlencode((string)$Data);
        }
        $this->needResponse = $needResponse;
    }
}